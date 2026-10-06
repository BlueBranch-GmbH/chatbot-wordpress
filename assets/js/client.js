/**
 * The one place that talks to the WordPress REST routes.
 *
 * Both the chat widget and the answer modules stream from the same endpoints,
 * so the token handling and the event-stream plumbing live here rather than
 * twice over.
 */
( function ( window ) {
	'use strict';

	var settings = window.bluebranchChatbotSettings || {};
	var strings = settings.strings || {};
	var pendingToken = null;

	// Overwritten by whatever the /token route reports, so the name only has to
	// be agreed in one place -- on the server.
	var TOKEN_PARAM = 'bb_token';

	/**
	 * Fetches a token, reusing the one already in flight.
	 *
	 * The token is not written into the page on purpose: a page served from a
	 * cache would hand every visitor the same token long after it expired.
	 * Asking for one at the moment somebody starts a conversation always gets
	 * a fresh one.
	 *
	 * @param {boolean} force Fetch a new one even if one is already known.
	 * @return {Promise<string>} The token.
	 */
	function getToken( force ) {
		if ( ! force && pendingToken ) {
			return pendingToken;
		}

		pendingToken = window.fetch( settings.restUrl + '/token', {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'token request failed' );
			}

			return response.json();
		} ).then( function ( data ) {
			if ( data.param ) {
				TOKEN_PARAM = data.param;
			}

			return data.token;
		} ).catch( function ( error ) {
			// A failed fetch must not be remembered, or every later attempt
			// would be handed the same rejected promise.
			pendingToken = null;

			throw error;
		} );

		return pendingToken;
	}

	function buildUrl( path, params, token ) {
		var url = new window.URL( settings.restUrl + path, window.location.origin );

		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] !== undefined && params[ key ] !== null && '' !== params[ key ] ) {
				url.searchParams.set( key, params[ key ] );
			}
		} );

		url.searchParams.set( TOKEN_PARAM, token );

		return url.toString();
	}

	/**
	 * Opens an event stream and reports what arrives.
	 *
	 * @param {string} path     Route below the plugin namespace.
	 * @param {Object} params   Query parameters.
	 * @param {Object} handlers onAnswer, onSources, onEnd and onError.
	 * @return {Object} An object with a close() method.
	 */
	function stream( path, params, handlers ) {
		var source = null;
		var finished = false;
		var retriedToken = false;
		var receivedAnything = false;

		function finish( errorMessage ) {
			if ( finished ) {
				return;
			}

			finished = true;

			if ( source ) {
				source.close();
			}

			if ( errorMessage && handlers.onError ) {
				handlers.onError( errorMessage );
			} else if ( ! errorMessage && handlers.onEnd ) {
				handlers.onEnd();
			}
		}

		function open( force ) {
			getToken( force ).then( function ( token ) {
				if ( finished ) {
					return;
				}

				source = new window.EventSource( buildUrl( path, params, token ) );

				source.onmessage = function ( event ) {
					var data;

					try {
						data = JSON.parse( event.data );
					} catch ( error ) {
						return;
					}

					if ( data.answer && handlers.onAnswer ) {
						receivedAnything = true;
						handlers.onAnswer( data.answer );
					}

					if ( data.sources && data.sources.length && handlers.onSources ) {
						handlers.onSources( data.sources );
					}
				};

				// The reference an answer can be rated by. Sent just before the
				// end, and only while feedback is switched on.
				source.addEventListener( 'meta', function ( event ) {
					var data;

					try {
						data = JSON.parse( event.data );
					} catch ( error ) {
						return;
					}

					if ( data && 'string' === typeof data.ref && /^[a-f0-9]{32}$/.test( data.ref ) && handlers.onMeta ) {
						handlers.onMeta( data );
					}
				} );

				source.addEventListener( 'end', function () {
					finish( null );
				} );

				source.addEventListener( 'error', function ( event ) {
					var data = null;

					if ( event && 'string' === typeof event.data && '' !== event.data ) {
						try {
							data = JSON.parse( event.data );
						} catch ( error ) {
							data = null;
						}
					}

					// A token that has merely aged out is not something to tell
					// the visitor about; it is something to replace and retry.
					if ( data && 'bluebranch_chatbot_token' === data.code && ! retriedToken ) {
						retriedToken = true;
						source.close();
						source = null;
						open( true );

						return;
					}

					if ( receivedAnything ) {
						// The answer was already on its way; a break at the end
						// is not worth replacing what has been read with an error.
						finish( null );

						return;
					}

					finish( data && data.message ? data.message : strings.requestError );
				} );
			} ).catch( function () {
				finish( strings.requestError );
			} );
		}

		open( false );

		return {
			close: function () {
				finished = true;

				if ( source ) {
					source.close();
				}
			}
		};
	}

	/**
	 * Addresses a browser can follow without being asked to run something.
	 *
	 * Deliberately a list of what is allowed rather than of what is not: a
	 * blocklist is only ever as good as the last scheme somebody thought of.
	 *
	 * @param {string} url The address as the API sent it.
	 * @return {string} The address, or an empty string.
	 */
	function safeUrl( url ) {
		var trimmed = String( url || '' ).trim();

		return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test( trimmed ) ? trimmed : '';
	}

	/**
	 * Builds a link to a source, marked as leaving the page.
	 *
	 * A link that opens a new tab and does not say so takes the back button
	 * away without warning. The arrow says it at a glance; the hidden text
	 * says it to a screen reader, which cannot see an arrow.
	 *
	 * @param {Object} source  The source, with url and title.
	 * @param {Object} strings Translated texts.
	 * @return {HTMLAnchorElement} The finished link.
	 */
	function buildSourceLink( source, strings ) {
		var link = window.document.createElement( 'a' );
		var label = window.document.createElement( 'span' );
		var arrow = window.document.createElement( 'span' );
		var hint = window.document.createElement( 'span' );
		var href = safeUrl( source.url );

		/*
		 * A source whose address is not something a browser can simply follow
		 * is rendered as plain text. The markdown renderer has always checked
		 * this and this path did not, which made the two disagree about the
		 * same question -- and the answer to it is the one that matters:
		 * everything arriving from the API is data, never something that gets
		 * to decide what the browser does next.
		 */
		if ( '' === href ) {
			label.className = 'chatbot-source__label';
			label.textContent = source.title || source.url || strings.source;

			return label;
		}

		link.href = href;
		link.target = '_blank';
		// noreferrer as well as noopener: the target has no business being told
		// which page of this site somebody was reading.
		link.rel = 'noopener noreferrer';
		link.className = 'chatbot-source';

		label.className = 'chatbot-source__label';
		label.textContent = source.title || source.url;

		arrow.className = 'chatbot-source__arrow';
		arrow.setAttribute( 'aria-hidden', 'true' );
		arrow.textContent = '↗';

		hint.className = 'chatbot-sr-only';
		hint.textContent = ' (' + ( strings.newTab || 'opens in a new tab' ) + ')';

		link.appendChild( label );
		link.appendChild( arrow );
		link.appendChild( hint );

		return link;
	}

	/**
	 * Sends the rating of one answer.
	 *
	 * The answer itself is not sent: the server kept it under the reference,
	 * so what ends up in the table is what was really answered and not what a
	 * script claims was.
	 *
	 * @param {string} ref     Reference from the meta event.
	 * @param {string} rating  Either "up" or "down".
	 * @param {string} comment Optional text with a thumbs down.
	 * @return {Promise} Resolves when the server has stored it.
	 */
	function sendFeedback( ref, rating, comment ) {
		function post( force ) {
			return getToken( force ).then( function ( token ) {
				var body = { ref: ref, rating: rating, comment: comment || '' };

				body[ TOKEN_PARAM ] = token;

				return window.fetch( settings.restUrl + '/feedback', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify( body )
				} );
			} );
		}

		return post( false ).then( function ( response ) {
			// An aged-out token is replaced once, like on the answer routes.
			return 403 === response.status ? post( true ) : response;
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'feedback failed' );
			}

			return true;
		} );
	}

	/**
	 * Puts thumbs up and down under an answer.
	 *
	 * A thumbs down opens a small field for what was wrong. Everything is
	 * built with textContent: nothing typed here is ever read back as markup.
	 *
	 * @param {Element}  parent   Where the bar goes.
	 * @param {string}   ref      Reference of the answer.
	 * @param {string}   rating   Rating already given, if any.
	 * @param {Function} onRated  Called with the rating once it is stored.
	 * @return {Element} The bar.
	 */
	function renderFeedback( parent, ref, rating, onRated ) {
		var doc = window.document;
		var bar = doc.createElement( 'div' );
		var question = doc.createElement( 'span' );
		var up = doc.createElement( 'button' );
		var down = doc.createElement( 'button' );
		var status = doc.createElement( 'p' );
		var form = null;

		bar.className = 'chatbot-feedback';
		question.className = 'chatbot-feedback__question';
		question.textContent = strings.feedbackQuestion || 'Was this answer helpful?';

		function button( element, kind, label, symbol ) {
			element.type = 'button';
			element.className = 'chatbot-feedback__btn chatbot-feedback__btn--' + kind;
			element.setAttribute( 'aria-label', label );
			element.setAttribute( 'title', label );
			element.setAttribute( 'aria-pressed', kind === rating ? 'true' : 'false' );
			element.textContent = symbol;
		}

		button( up, 'up', strings.feedbackUp || 'Helpful', '\uD83D\uDC4D' );
		button( down, 'down', strings.feedbackDown || 'Not helpful', '\uD83D\uDC4E' );

		status.className = 'chatbot-feedback__thanks';
		status.setAttribute( 'role', 'status' );
		status.hidden = true;

		function showStatus( text ) {
			status.textContent = text;
			status.hidden = false;
		}

		function press( kind ) {
			up.setAttribute( 'aria-pressed', 'up' === kind ? 'true' : 'false' );
			down.setAttribute( 'aria-pressed', 'down' === kind ? 'true' : 'false' );
		}

		function removeForm() {
			if ( form ) {
				form.remove();
				form = null;
			}
		}

		function openForm() {
			var label = doc.createElement( 'label' );
			var field = doc.createElement( 'textarea' );
			var hint = doc.createElement( 'span' );
			var submit = doc.createElement( 'button' );
			var fieldId = 'chatbot-feedback-' + ref;

			removeForm();

			form = doc.createElement( 'form' );
			form.className = 'chatbot-feedback__form';

			label.setAttribute( 'for', fieldId );
			label.textContent = strings.feedbackCommentLabel || 'What was wrong with it?';

			field.id = fieldId;
			field.rows = 2;
			field.maxLength = 1000;

			hint.className = 'chatbot-feedback__hint';
			hint.textContent = strings.feedbackCommentHint || '';

			submit.type = 'submit';
			submit.textContent = strings.feedbackSend || 'Send';

			form.appendChild( label );
			form.appendChild( field );
			form.appendChild( hint );
			form.appendChild( submit );

			form.addEventListener( 'submit', function ( event ) {
				var comment = field.value.trim();

				event.preventDefault();

				if ( '' === comment ) {
					removeForm();
					showStatus( strings.feedbackThanks || 'Thank you!' );

					return;
				}

				submit.disabled = true;

				sendFeedback( ref, 'down', comment ).then( function () {
					removeForm();
					showStatus( strings.feedbackThanks || 'Thank you!' );
				} ).catch( function () {
					submit.disabled = false;
					showStatus( strings.feedbackError || 'Error' );
				} );
			} );

			bar.appendChild( form );
			field.focus();
		}

		function rate( kind ) {
			up.disabled = true;
			down.disabled = true;

			sendFeedback( ref, kind, '' ).then( function () {
				rating = kind;
				press( kind );

				if ( onRated ) {
					onRated( kind );
				}

				if ( 'down' === kind ) {
					status.hidden = true;
					openForm();
				} else {
					removeForm();
					showStatus( strings.feedbackThanks || 'Thank you!' );
				}
			} ).catch( function () {
				showStatus( strings.feedbackError || 'Error' );
			} ).then( function () {
				up.disabled = false;
				down.disabled = false;
			} );
		}

		up.addEventListener( 'click', function () {
			rate( 'up' );
		} );

		down.addEventListener( 'click', function () {
			rate( 'down' );
		} );

		bar.appendChild( question );
		bar.appendChild( up );
		bar.appendChild( down );
		bar.appendChild( status );
		parent.appendChild( bar );

		return bar;
	}

	/**
	 * Only sources that can be linked to.
	 *
	 * A source without an address -- additional content added by hand -- has
	 * nothing to offer a visitor, and its title may be an internal file name.
	 *
	 * @param {Array} sources Sources as the API sent them.
	 * @return {Array} The ones with a safe address.
	 */
	function linkableSources( sources ) {
		return ( Array.isArray( sources ) ? sources : [] ).filter( function ( source ) {
			return source && '' !== safeUrl( source.url );
		} );
	}

	/**
	 * Reads the configuration a template left on an element.
	 *
	 * @param {Element} element The element carrying data-bb-config.
	 * @return {Object} The configuration, or an empty object.
	 */
	function readConfig( element ) {
		try {
			return JSON.parse( element.getAttribute( 'data-bb-config' ) || '{}' );
		} catch ( error ) {
			return {};
		}
	}

	window.BlueBranchChatbotClient = {
		settings: settings,
		strings: strings,
		getToken: getToken,
		stream: stream,
		readConfig: readConfig,
		buildSourceLink: buildSourceLink,
		sendFeedback: sendFeedback,
		renderFeedback: renderFeedback,
		linkableSources: linkableSources
	};
}( window ) );
