/**
 * The chat widget: a floating button with a dialogue window behind it.
 */
( function ( window, document ) {
	'use strict';

	var client = window.BlueBranchChatbotClient;
	var markdown = window.BlueBranchChatbotMarkdown;

	function ChatbotWidget( container, config ) {
		this.container = container;
		this.strings = client.strings;

		this.toggleButton = container.querySelector( '.chatbot-widget__toggle' );
		this.closeButton = container.querySelector( '.chatbot-widget__close' );
		this.clearButton = container.querySelector( '.chatbot-widget__clear' );
		this.fontDecButton = container.querySelector( '.chatbot-widget__font-btn--dec' );
		this.fontIncButton = container.querySelector( '.chatbot-widget__font-btn--inc' );
		this.panel = container.querySelector( '.chatbot-widget__panel' );
		this.messagesEl = container.querySelector( '.chatbot-widget__messages' );
		this.suggestionsEl = container.querySelector( '.chatbot-widget__suggestions' );
		this.form = container.querySelector( '.chatbot-widget__form' );
		this.input = container.querySelector( '.chatbot-widget__input' );
		this.sendButton = container.querySelector( '.chatbot-widget__send' );
		this.badge = container.querySelector( '.chatbot-widget__badge' );
		this.exportButton = container.querySelector( '.chatbot-widget__export' );
		this.exportMenu = container.querySelector( '.chatbot-widget__export-menu' );
		this.titleEl = container.querySelector( '.chatbot-widget__header-title' );

		/*
		 * One history per site, not per element id. The id carries an instance
		 * counter that differs from page to page (a search block before the
		 * widget makes it -2), so keying on it made the chat look empty after
		 * every page change. A shortcode may still ask for its own history via
		 * data-bb-history.
		 */
		this.storageKey = 'bluebranch_chatbot_history' + ( container.getAttribute( 'data-bb-history' ) ? '_' + container.getAttribute( 'data-bb-history' ) : '' );
		this.fontStorageKey = 'bluebranch_chatbot_font_size';
		this.fontSizeSteps = [ 13, 14, 15, 16, 17, 18, 19, 20 ];

		// The send icon has to be kept: the button borrows its own contents out
		// to the stop state and needs them back afterwards.
		this.sendIcon = this.sendButton ? this.sendButton.innerHTML : '';
		this.abortRequest = null;

		this.history = [];
		this.isOpen = false;
		this.isBusy = false;
		this.hasGreeted = false;
		this.pendingSources = null;

		// Read off the markup rather than written into this.strings: that object
		// is shared with every other instance on the page, and this label is
		// this button's own.
		this.sendLabel = this.sendButton ? ( this.sendButton.getAttribute( 'aria-label' ) || '' ) : '';
		this.greeting = config.greeting || this.strings.greeting;
		this.suggestions = Array.isArray( config.suggestions ) ? config.suggestions.filter( Boolean ) : [];
		this.showSummarize = false !== config.showSummarize;

		this.loadHistory();
		this.loadFontSize();
	}

	ChatbotWidget.prototype.init = function () {
		var self = this;

		if ( ! this.toggleButton || ! this.panel || ! this.form || ! this.input ) {
			return;
		}

		this.toggleButton.addEventListener( 'click', function () {
			self.toggle();
		} );

		if ( this.closeButton ) {
			this.closeButton.addEventListener( 'click', function () {
				self.close();
			} );
		}

		if ( this.clearButton ) {
			this.clearButton.addEventListener( 'click', function () {
				self.clearChat();
			} );
		}

		if ( this.fontDecButton ) {
			this.fontDecButton.addEventListener( 'click', function () {
				self.adjustFontSize( -1 );
			} );
		}

		if ( this.fontIncButton ) {
			this.fontIncButton.addEventListener( 'click', function () {
				self.adjustFontSize( 1 );
			} );
		}

		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			// While an answer is running the same button is the stop button.
			if ( self.isBusy ) {
				self.stopRequest();

				return;
			}

			self.send();
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				self.send();
			}
		} );

		this.input.addEventListener( 'input', function () {
			self.autoGrow();
		} );

		this.initExport();

		this.renderSuggestions();
	};

	/**
	 * Takes over a history stored under the old per-element keys (1.0.x), the
	 * most recently written one, and removes the old keys.
	 *
	 * @return {string|null} The raw history, or null.
	 */
	ChatbotWidget.prototype.adoptLegacyHistory = function () {
		var prefix = 'bluebranch_chatbot_history_bluebranch-chatbot-widget-';
		var best = null;
		var bestTime = -1;
		var legacy = [];
		var i;
		var key;

		for ( i = 0; i < window.localStorage.length; i++ ) {
			key = window.localStorage.key( i );

			if ( key && 0 === key.indexOf( prefix ) ) {
				legacy.push( key );
			}
		}

		legacy.forEach( function ( legacyKey ) {
			var raw = window.localStorage.getItem( legacyKey );
			var entries;
			var time = 0;

			try {
				entries = JSON.parse( raw );
			} catch ( error ) {
				entries = null;
			}

			if ( Array.isArray( entries ) && entries.length ) {
				time = entries[ entries.length - 1 ].time || 0;

				if ( time > bestTime ) {
					bestTime = time;
					best = raw;
				}
			}
		} );

		if ( best ) {
			window.localStorage.setItem( this.storageKey, best );
		}

		legacy.forEach( function ( legacyKey ) {
			window.localStorage.removeItem( legacyKey );
		} );

		return best;
	};

	ChatbotWidget.prototype.loadHistory = function () {
		var self = this;
		var raw;
		var stored;

		try {
			raw = window.localStorage.getItem( this.storageKey ) || this.adoptLegacyHistory();
		} catch ( error ) {
			// localStorage unavailable (private mode, quota, ...) - start fresh.
			return;
		}

		if ( ! raw ) {
			return;
		}

		try {
			stored = JSON.parse( raw );
		} catch ( error ) {
			return;
		}

		if ( ! Array.isArray( stored ) ) {
			return;
		}

		stored.forEach( function ( entry ) {
			if ( ! entry || ( 'user' !== entry.role && 'bot' !== entry.role ) || 'string' !== typeof entry.content ) {
				return;
			}

			self.history.push( entry );
			self.renderEntry( entry );
		} );

		if ( this.history.length > 0 ) {
			this.hasGreeted = true;
		}
	};

	/**
	 * Draws one stored message with everything that belonged to it: the
	 * sources, the feedback given and -- for an answer the page was left in
	 * the middle of -- a note that it is incomplete.
	 */
	ChatbotWidget.prototype.renderEntry = function ( entry ) {
		var bubble = this.addMessage( entry.role, entry.content );
		var note;

		if ( 'bot' !== entry.role ) {
			return;
		}

		if ( entry.pending ) {
			note = document.createElement( 'p' );
			note.className = 'chatbot-widget__interrupted';
			note.textContent = this.strings.interrupted;
			bubble.appendChild( note );
		}

		if ( Array.isArray( entry.sources ) && entry.sources.length ) {
			this.appendSources( bubble, entry.sources );
		}

		if ( entry.ref && client.settings.feedback ) {
			this.addFeedback( bubble, entry );
		}
	};

	/**
	 * Puts the thumbs under an answer and remembers what was chosen.
	 */
	ChatbotWidget.prototype.addFeedback = function ( bubble, entry ) {
		var self = this;

		client.renderFeedback( bubble.parentNode, entry.ref, entry.rating || '', function ( rating ) {
			entry.rating = rating;
			self.saveHistory();
		} );
	};

	ChatbotWidget.prototype.saveHistory = function () {
		try {
			window.localStorage.setItem( this.storageKey, JSON.stringify( this.history.slice( -50 ) ) );
		} catch ( error ) {
			// Not essential to the chat working.
		}
	};

	ChatbotWidget.prototype.loadFontSize = function () {
		var size = 15;
		var stored;

		try {
			stored = parseInt( window.localStorage.getItem( this.fontStorageKey ), 10 );

			if ( this.fontSizeSteps.indexOf( stored ) !== -1 ) {
				size = stored;
			}
		} catch ( error ) {
			// Fall back to the default size.
		}

		this.applyFontSize( size );
	};

	ChatbotWidget.prototype.applyFontSize = function ( size ) {
		this.fontSize = size;
		this.container.style.setProperty( '--chatbot-widget-message-font-size', size + 'px' );
	};

	ChatbotWidget.prototype.adjustFontSize = function ( direction ) {
		var index = this.fontSizeSteps.indexOf( this.fontSize );
		var nextIndex = Math.min( this.fontSizeSteps.length - 1, Math.max( 0, index + direction ) );
		var nextSize = this.fontSizeSteps[ nextIndex ];

		this.applyFontSize( nextSize );

		try {
			window.localStorage.setItem( this.fontStorageKey, String( nextSize ) );
		} catch ( error ) {
			// The size just will not survive the next page load.
		}
	};

	ChatbotWidget.prototype.clearChat = function () {
		this.history = [];
		this.hasGreeted = false;
		this.pendingSources = null;
		this.messagesEl.innerHTML = '';

		try {
			window.localStorage.removeItem( this.storageKey );
		} catch ( error ) {
			// Nothing to clean up.
		}

		if ( this.isOpen ) {
			this.maybeGreet();
		}
	};

	ChatbotWidget.prototype.toggle = function () {
		if ( this.isOpen ) {
			this.close();
		} else {
			this.open();
		}
	};

	ChatbotWidget.prototype.open = function () {
		var self = this;

		this.isOpen = true;
		this.panel.hidden = false;

		// Forces a reflow so the browser registers the un-hidden state before
		// the class change is animated.
		void this.panel.offsetWidth;

		window.requestAnimationFrame( function () {
			self.panel.classList.add( 'is-open' );
		} );

		this.toggleButton.setAttribute( 'aria-expanded', 'true' );
		this.toggleButton.classList.add( 'is-active' );
		this.toggleButton.hidden = true;
		this.setUnread( false );
		this.input.focus();
		this.scrollToBottom();
		this.maybeGreet();
	};

	ChatbotWidget.prototype.close = function () {
		var self = this;

		this.isOpen = false;
		this.panel.classList.remove( 'is-open' );
		this.toggleButton.setAttribute( 'aria-expanded', 'false' );
		this.toggleButton.classList.remove( 'is-active' );
		this.toggleButton.hidden = false;

		function hide() {
			self.panel.hidden = true;
		}

		this.panel.addEventListener( 'transitionend', hide, { once: true } );
		// Fallback in case the transition never fires, e.g. with reduced motion.
		window.setTimeout( hide, 250 );
	};

	ChatbotWidget.prototype.maybeGreet = function () {
		var self = this;
		var typingRow;

		if ( this.hasGreeted || this.history.length > 0 ) {
			return;
		}

		this.hasGreeted = true;
		typingRow = this.addTyping();

		window.setTimeout( function () {
			typingRow.remove();
			self.addMessage( 'bot', self.greeting );
			self.history.push( { role: 'bot', content: self.greeting, time: Date.now() } );
			self.saveHistory();
		}, 800 );
	};

	ChatbotWidget.prototype.renderSuggestions = function () {
		var self = this;

		if ( ! this.suggestionsEl ) {
			return;
		}

		this.suggestionsEl.innerHTML = '';

		function addPill( text, onClick ) {
			var pill = document.createElement( 'button' );

			pill.type = 'button';
			pill.className = 'chatbot-widget__suggestion';
			pill.textContent = text;
			pill.addEventListener( 'click', onClick );
			self.suggestionsEl.appendChild( pill );
		}

		this.suggestions.forEach( function ( text ) {
			addPill( text, function () {
				self.sendPrompt( text );
			} );
		} );

		if ( this.showSummarize ) {
			addPill( this.strings.summarize, function () {
				self.summarizePage();
			} );
		}

		this.suggestionsEl.hidden = false;
	};

	ChatbotWidget.prototype.setUnread = function ( state ) {
		if ( this.badge ) {
			this.badge.hidden = ! state;
		}
	};

	ChatbotWidget.prototype.autoGrow = function () {
		this.input.style.height = 'auto';
		this.input.style.height = Math.min( this.input.scrollHeight, 120 ) + 'px';
	};

	/**
	 * Locks the field, the send button and the pills while an answer is running.
	 *
	 * Without it a second click starts a second request whose stream writes
	 * into the same bubble as the first.
	 */
	ChatbotWidget.prototype.setBusy = function ( state ) {
		this.isBusy = state;
		this.input.disabled = state;

		// Not disabled: it stays clickable and becomes the way out of a running
		// answer. Greying it out would remove the button at the one moment it
		// is wanted most.
		if ( this.sendButton ) {
			this.sendButton.innerHTML = state ? '&#9632;' : this.sendIcon;
			this.sendButton.classList.toggle( 'chatbot-widget__send--stop', state );
			this.sendButton.setAttribute( 'aria-label', state ? this.strings.stop : this.sendLabel );
			this.sendButton.setAttribute( 'title', state ? this.strings.stop : this.sendLabel );
		}

		// Clearing mid-stream would remove the bubble being written into.
		if ( this.clearButton ) {
			this.clearButton.disabled = state;
		}

		if ( this.suggestionsEl ) {
			Array.prototype.forEach.call(
				this.suggestionsEl.querySelectorAll( '.chatbot-widget__suggestion' ),
				function ( pill ) {
					pill.disabled = state;
				}
			);
		}

		this.form.setAttribute( 'aria-busy', state ? 'true' : 'false' );
	};

	/**
	 * Abandons the running answer.
	 *
	 * What has already been streamed stays in the bubble -- it has been read.
	 * Only when nothing arrived at all does a note take its place, so the
	 * question is not left hanging without any reply.
	 */
	ChatbotWidget.prototype.stopRequest = function () {
		if ( this.abortRequest ) {
			this.abortRequest();
		}
	};

	ChatbotWidget.prototype.send = function () {
		var text = this.input.value.trim();

		if ( ! text || this.isBusy ) {
			return;
		}

		this.input.value = '';
		this.autoGrow();
		this.sendPrompt( text );
	};

	ChatbotWidget.prototype.sendPrompt = function ( text ) {
		if ( ! text || this.isBusy ) {
			return;
		}

		this.addMessage( 'user', text );
		this.history.push( { role: 'user', content: text, time: Date.now() } );
		this.saveHistory();

		this.requestAnswer( text, true );
	};

	ChatbotWidget.prototype.summarizePage = function () {
		var displayText = this.strings.summarize;
		var pageContent = this.extractPageContent();
		var prompt;

		if ( this.isBusy ) {
			return;
		}

		prompt = pageContent
			? this.strings.summarizePrompt + '\n\n---\n' + pageContent + '\n---'
			: this.strings.summarizeFallbackPrompt;

		this.addMessage( 'user', displayText );
		this.history.push( { role: 'user', content: displayText, time: Date.now() } );
		this.saveHistory();

		this.requestAnswer( prompt, false, true );
	};

	ChatbotWidget.prototype.extractPageContent = function () {
		var source = document.querySelector( 'main' ) || document.body;
		var clone;
		var text;
		var maxLength = 2500;

		if ( ! source ) {
			return '';
		}

		clone = source.cloneNode( true );

		Array.prototype.forEach.call(
			clone.querySelectorAll( 'script, style, noscript, .chatbot-widget, nav, header, footer' ),
			function ( element ) {
				element.remove();
			}
		);

		text = ( clone.textContent || '' ).replace( /\s+/g, ' ' ).trim();

		// Kept well under the usual request-line limits: the text travels as a
		// query parameter and non-ASCII characters take several bytes each.
		return text.length > maxLength ? text.slice( 0, maxLength ) + ' …' : text;
	};

	ChatbotWidget.prototype.addMessage = function ( role, text ) {
		var row = document.createElement( 'div' );
		var bubble = document.createElement( 'div' );

		row.className = 'chatbot-widget__message chatbot-widget__message--' + role;
		bubble.className = 'chatbot-widget__bubble';

		if ( 'bot' === role ) {
			bubble.innerHTML = markdown.render( text );
		} else {
			bubble.textContent = text;
		}

		row.appendChild( bubble );
		this.messagesEl.appendChild( row );
		this.scrollToBottom();

		return bubble;
	};

	ChatbotWidget.prototype.addTyping = function () {
		var row = document.createElement( 'div' );

		row.className = 'chatbot-widget__message chatbot-widget__message--bot chatbot-widget__message--typing';
		row.innerHTML = '<div class="chatbot-widget__bubble chatbot-widget__typing"><span></span><span></span><span></span></div>';

		this.messagesEl.appendChild( row );
		this.scrollToBottom();

		return row;
	};

	ChatbotWidget.prototype.buildChatContext = function () {
		return this.history.slice( -10 ).map( function ( entry ) {
			return ( 'user' === entry.role ? 'Nutzer: ' : 'Assistent: ' ) + entry.content;
		} ).join( '\n' );
	};

	ChatbotWidget.prototype.requestAnswer = function ( prompt, includeContext, summarize ) {
		var self = this;
		var typingRow = this.addTyping();
		var bubble = null;
		var fullAnswer = '';
		var renderPending = false;
		var chatContext = includeContext ? this.buildChatContext() : '';
		var entry = null;
		var ref = '';
		var lastSave = 0;

		this.setBusy( true );
		this.pendingSources = null;

		/*
		 * The answer goes into the stored history while it is still arriving,
		 * marked as pending. Leaving the page halfway used to lose it entirely:
		 * only the question was stored, the answer only once it was complete.
		 */
		function keep( final ) {
			var now = Date.now();

			if ( ! fullAnswer ) {
				return;
			}

			if ( ! entry ) {
				entry = { role: 'bot', content: '', time: now, pending: true };
				self.history.push( entry );
			}

			entry.content = fullAnswer;

			if ( final ) {
				delete entry.pending;
			}

			// Throttled while streaming: writing localStorage on every chunk
			// would cost more than the rendering does.
			if ( final || now - lastSave > 500 ) {
				lastSave = now;
				self.saveHistory();
			}
		}

		function done( complete ) {
			self.abortRequest = null;
			self.setBusy( false );
			self.input.focus();

			if ( fullAnswer ) {
				if ( entry ) {
					// When the answer was finished, for how long the subtitle export shows it.
					entry.end = Date.now();
				}

				keep( true );

				if ( ! complete && entry ) {
					// Stopped or broken off: the text stays, but is marked.
					entry.pending = true;
					self.saveHistory();
				}

				if ( ! self.isOpen ) {
					self.setUnread( true );
				}
			}
		}

		var connection = client.stream( '/chat/stream', {
			prompt: prompt,
			chat_context: chatContext,
			source: 'widget',
			post_id: client.settings.postId || 0,
			summarize: summarize ? 1 : 0
		}, {
			onAnswer: function ( chunk ) {
				if ( ! bubble ) {
					typingRow.remove();
					bubble = self.addMessage( 'bot', '' );
				}

				fullAnswer += chunk;
				keep( false );

				// Throttled to animation frames so the browser paints each
				// chunk instead of batching everything that arrives in one tick.
				if ( ! renderPending ) {
					renderPending = true;

					window.requestAnimationFrame( function () {
						bubble.innerHTML = markdown.render( fullAnswer );
						self.scrollToBottom();
						renderPending = false;
					} );
				}
			},
			onSources: function ( sources ) {
				self.pendingSources = sources;
			},
			onMeta: function ( meta ) {
				ref = meta.ref;
			},
			onEnd: function () {
				var sources = client.linkableSources( self.pendingSources );

				self.pendingSources = null;

				if ( ! bubble ) {
					typingRow.remove();
					self.addMessage( 'bot', self.strings.noAnswer );
					done( false );

					return;
				}

				keep( true );

				if ( sources.length ) {
					entry.sources = sources.slice( 0, 3 ).map( function ( source ) {
						return { title: source.title || '', url: source.url };
					} );
					self.appendSources( bubble, entry.sources );
				}

				if ( ref && client.settings.feedback ) {
					entry.ref = ref;
					self.addFeedback( bubble, entry );
				}

				done( true );
			},
			onError: function ( message ) {
				if ( ! bubble ) {
					typingRow.remove();
					self.addMessage( 'bot', message || self.strings.requestError );
				}

				done( false );
			}
		} );

		this.abortRequest = function () {
			connection.close();

			// Nothing came back at all, so without this the question would sit
			// there with no reply under it.
			if ( ! bubble ) {
				typingRow.remove();
				self.addMessage( 'bot', self.strings.stopped );
			}

			done( false );
		};
	};

	ChatbotWidget.prototype.appendSources = function ( bubble, sources ) {
		var self = this;
		var list = document.createElement( 'ul' );
		var linkable = client.linkableSources( sources );

		// Sources without an address are not shown at all; see linkableSources().
		if ( ! linkable.length ) {
			return;
		}

		list.className = 'chatbot-widget__sources';

		linkable.slice( 0, 3 ).forEach( function ( source ) {
			var item = document.createElement( 'li' );

			item.appendChild( client.buildSourceLink( source, self.strings ) );
			list.appendChild( item );
		} );

		bubble.appendChild( list );
	};

	/**
	 * Wires the export button and its two formats.
	 */
	ChatbotWidget.prototype.initExport = function () {
		var self = this;

		if ( ! this.exportButton || ! this.exportMenu ) {
			return;
		}

		function setMenu( open ) {
			self.exportMenu.hidden = ! open;
			self.exportButton.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		this.exportButton.addEventListener( 'click', function () {
			setMenu( self.exportMenu.hidden );
		} );

		Array.prototype.forEach.call( this.exportMenu.querySelectorAll( 'button[data-format]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				setMenu( false );
				self.exportChat( button.getAttribute( 'data-format' ) );
			} );
		} );

		this.container.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! self.exportMenu.hidden ) {
				setMenu( false );
				self.exportButton.focus();
			}
		} );
	};

	/**
	 * Hands the conversation to the visitor as a file.
	 *
	 * Built entirely in the browser: the conversation is already here, and
	 * sending it to the server just to have it sent back would be one more
	 * place it passes through.
	 *
	 * @param {string} format Either "txt" or "vtt".
	 */
	ChatbotWidget.prototype.exportChat = function ( format ) {
		var entries = this.history.filter( function ( entry ) {
			return entry && 'string' === typeof entry.content && '' !== entry.content;
		} );
		var botName = this.titleEl ? this.titleEl.textContent.trim() : 'Chatbot';
		var content;
		var url;
		var link = document.createElement( 'a' );
		var stamp = new Date().toISOString().slice( 0, 10 );

		if ( ! entries.length ) {
			return;
		}

		content = 'vtt' === format ? this.toVtt( entries, botName ) : this.toTxt( entries, botName );
		url = window.URL.createObjectURL(
			new window.Blob( [ content ], { type: ( 'vtt' === format ? 'text/vtt' : 'text/plain' ) + ';charset=utf-8' } )
		);

		link.href = url;
		link.download = 'chat-' + stamp + '.' + ( 'vtt' === format ? 'vtt' : 'txt' );
		document.body.appendChild( link );
		link.click();
		link.remove();

		window.setTimeout( function () {
			window.URL.revokeObjectURL( url );
		}, 1000 );
	};

	ChatbotWidget.prototype.speaker = function ( entry, botName ) {
		return 'user' === entry.role ? this.strings.you : botName;
	};

	function pad( value, length ) {
		return String( value ).padStart( length || 2, '0' );
	}

	function clockOf( ms ) {
		var date = new Date( ms );

		return pad( date.getHours() ) + ':' + pad( date.getMinutes() ) + ':' + pad( date.getSeconds() );
	}

	/**
	 * The start of every entry in ms, ascending.
	 *
	 * Entries without a timestamp (histories from 1.0.x, the greeting among
	 * them) are bridged one by one, a second from their neighbour. Giving up
	 * on the timing of the whole file because the first entry has none -- as
	 * the first version did -- put every cue on a five-second grid.
	 *
	 * @param {Array} entries History entries.
	 * @return {number[]} Start times.
	 */
	function startTimes( entries ) {
		var valid = function ( entry ) {
			return entry && 'number' === typeof entry.time && entry.time > 0;
		};
		var firstTimed = -1;
		var anchor;
		var times = [];
		var i;

		for ( i = 0; i < entries.length; i++ ) {
			if ( valid( entries[ i ] ) ) {
				firstTimed = i;
				break;
			}
		}

		anchor = -1 === firstTimed ? Date.now() : entries[ firstTimed ].time;

		entries.forEach( function ( entry, index ) {
			var previous = index > 0 ? times[ index - 1 ] : null;

			if ( -1 === firstTimed || index < firstTimed ) {
				times.push( anchor - ( ( -1 === firstTimed ? entries.length : firstTimed ) - index ) * 1000 );
				return;
			}

			times.push( valid( entry ) && ( null === previous || entry.time >= previous ) ? entry.time : previous + 1000 );
		} );

		return times;
	}

	/**
	 * How long a cue stays: until the answer was finished plus reading time
	 * (50 ms a character, 2 to 20 seconds), but never past the next message --
	 * players stack overlapping cues on top of each other.
	 *
	 * @param {Array}    entries History entries.
	 * @param {number[]} times   Start times.
	 * @return {number[]} End times.
	 */
	function endTimes( entries, times ) {
		return entries.map( function ( entry, index ) {
			var shown = 'number' === typeof entry.end && entry.end >= times[ index ] ? entry.end : times[ index ];
			var end = shown + Math.min( 20000, Math.max( 2000, entry.content.length * 50 ) );

			if ( index + 1 < times.length && times[ index + 1 ] < end ) {
				end = times[ index + 1 ];
			}

			return Math.max( end, times[ index ] + 500 );
		} );
	}

	/**
	 * The markdown of an answer as readable text: links as "text (url)",
	 * emphasis, heading and code marks removed, list items with "- ".
	 *
	 * @param {string} text Markdown.
	 * @return {string} Plain text.
	 */
	function plainText( text ) {
		return String( text )
			.replace( /```[a-z]*\n?/gi, '' )
			.replace( /!\[([^\]]*)\]\([^)]*\)/g, '$1' )
			.replace( /\[([^\]]+)\]\(([^)\s]+)[^)]*\)/g, '$1 ($2)' )
			.replace( /(\*\*|__)(.+?)\1/g, '$2' )
			.replace( /(^|[^*\w])[*_]([^*_\n]+)[*_](?=[^*\w]|$)/g, '$1$2' )
			.replace( /`([^`]+)`/g, '$1' )
			.replace( /^\s{0,3}#{1,6}\s+/gm, '' )
			.replace( /^\s*[*+]\s+/gm, '- ' )
			.replace( /^\s{0,3}>\s?/gm, '' );
	}

	ChatbotWidget.prototype.toTxt = function ( entries, botName ) {
		var self = this;
		var times = startTimes( entries );
		var lines = [ document.title + ' – ' + new Date( times[ 0 ] ).toLocaleString(), '' ];

		entries.forEach( function ( entry, index ) {
			lines.push( '[' + clockOf( times[ index ] ) + '] ' + self.speaker( entry, botName ) + ': ' + plainText( entry.content ).trim() );
			lines.push( '' );
		} );

		return lines.join( '\r\n' );
	};

	/**
	 * WebVTT: one cue per message on a timeline from the start of the chat,
	 * as subtitles require. The wall-clock time is the cue identifier, the
	 * date sits in a NOTE at the top.
	 *
	 * Cue text must not contain "-->" or an empty line (either ends the cue
	 * early), and < and & are markup there.
	 */
	ChatbotWidget.prototype.toVtt = function ( entries, botName ) {
		var self = this;
		var times = startTimes( entries );
		var ends = endTimes( entries, times );
		var origin = times[ 0 ];
		var out = [ 'WEBVTT', '', 'NOTE ' + ( document.title + ' – ' + new Date( origin ).toLocaleString() ).replace( /-->/g, '→' ), '' ];

		function stamp( ms ) {
			var hours = Math.floor( ms / 3600000 );
			var minutes = Math.floor( ( ms % 3600000 ) / 60000 );
			var seconds = Math.floor( ( ms % 60000 ) / 1000 );

			return pad( hours ) + ':' + pad( minutes ) + ':' + pad( seconds ) + '.' + pad( ms % 1000, 3 );
		}

		function clean( text ) {
			return String( text )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /\r\n?/g, '\n' )
				.replace( /\n\s*\n+/g, '\n' )
				.trim();
		}

		entries.forEach( function ( entry, index ) {
			out.push( ( index + 1 ) + ' ' + clockOf( times[ index ] ) );
			out.push( stamp( times[ index ] - origin ) + ' --> ' + stamp( ends[ index ] - origin ) );
			out.push( '<v ' + clean( self.speaker( entry, botName ) ).replace( /\n/g, ' ' ) + '>' + clean( plainText( entry.content ) ) );
			out.push( '' );
		} );

		return out.join( '\n' );
	};

	ChatbotWidget.prototype.scrollToBottom = function () {
		this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
	};

	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-bb-chatbot="widget"]' ),
			function ( container ) {
				if ( container.dataset.bbInitialised ) {
					return;
				}

				container.dataset.bbInitialised = '1';

				new ChatbotWidget( container, client.readConfig( container ) ).init();
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.BlueBranchChatbotWidget = ChatbotWidget;
}( window, document ) );
