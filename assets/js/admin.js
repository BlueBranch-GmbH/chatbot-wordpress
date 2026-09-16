/**
 * The behaviour of the plugin's own admin screens.
 *
 * Three unrelated jobs live here because they share one screen set: the media
 * and colour pickers on the settings page, the trained content table, and the
 * batch training run.
 */
( function ( window, document, wp ) {
	'use strict';

	var config = window.bluebranchChatbotAdmin || {};
	var strings = config.strings || {};

	function request( path, options ) {
		options = options || {};

		return window.fetch( config.restUrl + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : strings.failed );
				}

				return data;
			} );
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Settings screen: colour picker, media picker.
	 * ---------------------------------------------------------------- */

	function initSettings() {
		var field = document.querySelector( '.bluebranch-chatbot-color' );
		var media = document.querySelector( '.bluebranch-chatbot-media' );
		var frame = null;
		var input;
		var preview;
		var clearButton;

		if ( field && window.jQuery && window.jQuery.fn.wpColorPicker ) {
			window.jQuery( field ).wpColorPicker();
		}

		if ( ! media || ! wp || ! wp.media ) {
			return;
		}

		input = media.querySelector( 'input[type="hidden"]' );
		preview = media.querySelector( '.bluebranch-chatbot-media__preview' );
		clearButton = media.querySelector( '.bluebranch-chatbot-media__clear' );

		media.querySelector( '.bluebranch-chatbot-media__select' ).addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: media.querySelector( '.bluebranch-chatbot-media__select' ).textContent,
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();

					input.value = attachment.id;
					preview.src = attachment.url;
					preview.hidden = false;
					clearButton.hidden = false;
				} );
			}

			frame.open();
		} );

		clearButton.addEventListener( 'click', function () {
			input.value = '0';
			preview.removeAttribute( 'src' );
			preview.hidden = true;
			clearButton.hidden = true;
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Trained content screen.
	 * ---------------------------------------------------------------- */

	function initContent() {
		var tbody = document.getElementById( 'bluebranch-chatbot-rows' );
		var countEl = document.getElementById( 'bluebranch-chatbot-count' );
		var paginationEl = document.getElementById( 'bluebranch-chatbot-pagination' );
		var filterEl = document.getElementById( 'bluebranch-chatbot-filter' );
		var perPageEl = document.getElementById( 'bluebranch-chatbot-per-page' );
		var deleteAllEl = document.getElementById( 'bluebranch-chatbot-delete-all' );
		var tierEl = document.getElementById( 'bluebranch-chatbot-tier' );

		var allItems = [];
		var filtered = [];
		var page = 1;
		var perPage = 20;

		if ( ! tbody ) {
			return;
		}

		function formatDate( value ) {
			var date;

			if ( ! value ) {
				return '';
			}

			date = new Date( value );

			return isNaN( date.getTime() ) ? '' : date.toLocaleString();
		}

		function messageRow( text ) {
			var row = document.createElement( 'tr' );
			var cell = document.createElement( 'td' );

			cell.colSpan = 6;
			cell.textContent = text;
			row.appendChild( cell );

			return row;
		}

		function buildRow( item ) {
			var row = document.createElement( 'tr' );
			var titleCell = document.createElement( 'td' );
			var title = document.createElement( 'strong' );
			var url = document.createElement( 'div' );
			var actionCell = document.createElement( 'td' );
			var button = document.createElement( 'button' );

			// Everything is written as text, never as markup: these values come
			// from the API and have no business producing elements here.
			title.textContent = item.title || '';
			url.className = 'bluebranch-chatbot-url';
			url.textContent = item.url || '';
			titleCell.appendChild( title );
			titleCell.appendChild( url );

			row.appendChild( titleCell );
			row.appendChild( textCell( item.externalId || '' ) );
			row.appendChild( textCell( String( item.chunkCount || 1 ) ) );
			row.appendChild( textCell( item.language || '' ) );
			row.appendChild( textCell( formatDate( item.createdAt ) ) );

			button.type = 'button';
			button.className = 'button button-small button-link-delete';
			button.textContent = strings.deleteLabel;
			button.addEventListener( 'click', function () {
				deleteItem( item, button, row );
			} );

			actionCell.appendChild( button );
			row.appendChild( actionCell );

			return row;
		}

		function textCell( text ) {
			var cell = document.createElement( 'td' );

			cell.textContent = text;

			return cell;
		}

		function deleteItem( item, button, row ) {
			if ( ! window.confirm( strings.confirmDelete ) ) {
				return;
			}

			button.disabled = true;
			button.textContent = strings.deleting;

			request( '/content/' + encodeURIComponent( item.externalId ), { method: 'DELETE' } ).then( function () {
				allItems = allItems.filter( function ( candidate ) {
					return candidate.externalId !== item.externalId;
				} );

				row.remove();
				applyFilter();
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = strings.deleteLabel;
				window.alert( error.message || strings.failed );
			} );
		}

		function renderPagination( pages ) {
			var index;
			var button;

			paginationEl.innerHTML = '';

			if ( pages < 2 ) {
				return;
			}

			for ( index = 1; index <= pages; index++ ) {
				button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button' + ( index === page ? ' button-primary' : '' );
				button.textContent = String( index );

				( function ( target ) {
					button.addEventListener( 'click', function () {
						page = target;
						renderTable();
					} );
				}( index ) );

				paginationEl.appendChild( button );
			}
		}

		function renderTable() {
			var pages = Math.max( 1, Math.ceil( filtered.length / perPage ) );
			var slice;

			page = Math.min( page, pages );
			slice = filtered.slice( ( page - 1 ) * perPage, page * perPage );

			tbody.innerHTML = '';

			if ( 0 === slice.length ) {
				tbody.appendChild( messageRow( strings.empty ) );
			} else {
				slice.forEach( function ( item ) {
					tbody.appendChild( buildRow( item ) );
				} );
			}

			countEl.textContent = filtered.length === allItems.length
				? filtered.length + ' ' + strings.entries
				: strings.filtered.replace( '%1$d', filtered.length ).replace( '%2$d', allItems.length );

			renderPagination( pages );
		}

		function applyFilter() {
			var term = ( filterEl.value || '' ).toLowerCase().trim();

			filtered = '' === term ? allItems.slice() : allItems.filter( function ( item ) {
				return [ item.title, item.url, item.externalId ].some( function ( value ) {
					return String( value || '' ).toLowerCase().indexOf( term ) !== -1;
				} );
			} );

			page = 1;
			renderTable();
		}

		filterEl.addEventListener( 'input', applyFilter );

		perPageEl.addEventListener( 'change', function () {
			perPage = parseInt( perPageEl.value, 10 ) || 20;
			page = 1;
			renderTable();
		} );

		deleteAllEl.addEventListener( 'click', function () {
			if ( ! window.confirm( strings.confirmDeleteAll ) ) {
				return;
			}

			deleteAllEl.disabled = true;

			request( '/content', { method: 'DELETE' } ).then( function () {
				allItems = [];
				applyFilter();
				deleteAllEl.disabled = false;
			} ).catch( function ( error ) {
				deleteAllEl.disabled = false;
				window.alert( error.message || strings.failed );
			} );
		} );

		request( '/content' ).then( function ( data ) {
			allItems = Array.isArray( data.items ) ? data.items : [];
			applyFilter();
		} ).catch( function ( error ) {
			tbody.innerHTML = '';
			tbody.appendChild( messageRow( error.message || strings.failed ) );
		} );

	}

	/* ---------------------------------------------------------------- *
	 * The usage tier of the stored key.
	 * ---------------------------------------------------------------- */

	function initTier() {
		var box = document.getElementById( 'bluebranch-chatbot-tier' );

		if ( ! box ) {
			return;
		}

		request( '/tier' ).then( function ( data ) {
			var badge = document.createElement( 'strong' );
			var text = '';

			if ( ! data || ! data.tier ) {
				return;
			}

			/*
			 * When the API sends a notice, it is shown as it stands. Quota,
			 * wording and contact address then live at the far end, and a
			 * changed plan shows up here without this plugin being released
			 * again -- which is the whole point of the API sending one.
			 */
			if ( data.notice && data.notice.title ) {
				badge.textContent = data.notice.title;
				text = data.notice.text || '';
			} else {
				// No notice: say it from the numbers the API did send.
				badge.textContent = data.label || data.tier;
				text = data.requestsPerMinute
					? strings.tierRequests.replace( '%d', data.requestsPerMinute )
					: '';
			}

			box.textContent = '';
			box.className = 'bluebranch-chatbot-tier' + ( data.isPremium ? ' is-premium' : '' );
			box.appendChild( badge );

			if ( text ) {
				box.appendChild( document.createTextNode( ' ' + text ) );
			}

			box.hidden = false;
		} ).catch( function () {
			// An unavailable tier is not worth a message; every screen it sits
			// on works perfectly well without knowing which plan this is.
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Training screen.
	 * ---------------------------------------------------------------- */

	function initTraining() {
		var button = document.getElementById( 'bluebranch-chatbot-train' );
		var forceEl = document.getElementById( 'bluebranch-chatbot-force' );
		var progress = document.getElementById( 'bluebranch-chatbot-progress' );
		var progressText = document.getElementById( 'bluebranch-chatbot-progress-text' );
		var bar = progress ? progress.querySelector( '.bluebranch-chatbot-progress__bar span' ) : null;
		var log = document.getElementById( 'bluebranch-chatbot-log' );
		var counts;

		if ( ! button ) {
			return;
		}

		function report( entry ) {
			var item = document.createElement( 'li' );
			var outcome = entry.outcome || ( entry.success ? 'trained' : 'failed' );

			counts[ outcome ] = ( counts[ outcome ] || 0 ) + 1;

			item.className = 'is-' + outcome;
			item.textContent = ( entry.title || '#' + entry.id ) + ' — ' + ( strings[ 'outcome_' + outcome ] || outcome );
			log.appendChild( item );
			log.scrollTop = log.scrollHeight;
		}

		function update( done, total ) {
			var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;

			bar.style.width = percent + '%';
			progressText.textContent = strings.progress.replace( '%1$d', done ).replace( '%2$d', total );
		}

		function summarise() {
			var parts = [];

			Object.keys( counts ).forEach( function ( outcome ) {
				parts.push( counts[ outcome ] + ' ' + ( strings[ 'outcome_' + outcome ] || outcome ) );
			} );

			progressText.textContent = strings.trainingDone + ( parts.length ? ' (' + parts.join( ', ' ) + ')' : '' );
		}

		function step( offset ) {
			request( '/train', {
				method: 'POST',
				body: { offset: offset, force: !! ( forceEl && forceEl.checked ) }
			} ).then( function ( data ) {
				( data.trained || [] ).forEach( report );
				update( data.offset, data.total );

				if ( data.done ) {
					summarise();
					button.disabled = false;

					return;
				}

				step( data.offset );
			} ).catch( function ( error ) {
				progressText.textContent = error.message || strings.failed;
				button.disabled = false;
			} );
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			counts = {};
			log.innerHTML = '';
			progress.hidden = false;
			bar.style.width = '0%';
			progressText.textContent = strings.preparing;

			request( '/train', {
				method: 'POST',
				body: { reset: true, force: !! ( forceEl && forceEl.checked ) }
			} ).then( function ( data ) {
				if ( 0 === data.total ) {
					progressText.textContent = strings.nothingToTrain;
					button.disabled = false;

					return;
				}

				update( 0, data.total );
				step( 0 );
			} ).catch( function ( error ) {
				progressText.textContent = error.message || strings.failed;
				button.disabled = false;
			} );
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Crawl preview: shows what one page would actually contribute.
	 * ---------------------------------------------------------------- */

	function initPreview() {
		var button = document.getElementById( 'bluebranch-chatbot-preview' );
		var urlEl = document.getElementById( 'bluebranch-chatbot-preview-url' );
		var resultEl = document.getElementById( 'bluebranch-chatbot-preview-result' );
		var metaEl = document.getElementById( 'bluebranch-chatbot-preview-meta' );
		var textEl = document.getElementById( 'bluebranch-chatbot-preview-text' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			resultEl.hidden = false;
			metaEl.textContent = strings.preparing;
			textEl.textContent = '';

			request( '/crawl/preview?url=' + encodeURIComponent( urlEl.value ) ).then( function ( data ) {
				var dropped = data.page_length > 0
					? Math.round( ( 1 - ( data.kept_length / data.page_length ) ) * 100 )
					: 0;

				metaEl.textContent = strings.previewMeta
					.replace( '%1$d', dropped )
					.replace( '%2$s', data.marked ? strings.yes : strings.no )
					.replace( '%3$d', data.boilerplate );

				textEl.textContent = data.markdown;
				button.disabled = false;
			} ).catch( function ( error ) {
				metaEl.textContent = error.message || strings.failed;
				button.disabled = false;
			} );
		} );
	}

	function boot() {
		initSettings();
		initContent();
		initTier();
		initTraining();
		initPreview();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window, document, window.wp ) );
