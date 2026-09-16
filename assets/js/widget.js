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

		this.storageKey = 'bluebranch_chatbot_history_' + container.id;
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

		this.renderSuggestions();
	};

	ChatbotWidget.prototype.loadHistory = function () {
		var self = this;
		var raw;
		var stored;

		try {
			raw = window.localStorage.getItem( this.storageKey );
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
			self.addMessage( entry.role, entry.content );
		} );

		if ( this.history.length > 0 ) {
			this.hasGreeted = true;
		}
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
			self.history.push( { role: 'bot', content: self.greeting } );
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
		this.history.push( { role: 'user', content: text } );
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
		this.history.push( { role: 'user', content: displayText } );
		this.saveHistory();

		this.requestAnswer( prompt, false );
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

	ChatbotWidget.prototype.requestAnswer = function ( prompt, includeContext ) {
		var self = this;
		var typingRow = this.addTyping();
		var bubble = null;
		var fullAnswer = '';
		var renderPending = false;
		var chatContext = includeContext ? this.buildChatContext() : '';

		this.setBusy( true );
		this.pendingSources = null;

		function done() {
			self.abortRequest = null;
			self.setBusy( false );
			self.input.focus();

			if ( fullAnswer ) {
				self.history.push( { role: 'bot', content: fullAnswer } );
				self.saveHistory();

				if ( ! self.isOpen ) {
					self.setUnread( true );
				}
			}
		}

		var connection = client.stream( '/chat/stream', {
			prompt: prompt,
			chat_context: chatContext
		}, {
			onAnswer: function ( chunk ) {
				if ( ! bubble ) {
					typingRow.remove();
					bubble = self.addMessage( 'bot', '' );
				}

				fullAnswer += chunk;

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
			onEnd: function () {
				if ( ! bubble ) {
					typingRow.remove();
					self.addMessage( 'bot', self.strings.noAnswer );
				} else if ( self.pendingSources ) {
					self.appendSources( bubble, self.pendingSources );
					self.pendingSources = null;
				}

				done();
			},
			onError: function ( message ) {
				if ( ! bubble ) {
					typingRow.remove();
					self.addMessage( 'bot', message || self.strings.requestError );
				}

				done();
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

			done();
		};
	};

	ChatbotWidget.prototype.appendSources = function ( bubble, sources ) {
		var self = this;
		var list = document.createElement( 'ul' );

		list.className = 'chatbot-widget__sources';

		sources.slice( 0, 3 ).forEach( function ( source ) {
			var item = document.createElement( 'li' );

			if ( source.url ) {
				item.appendChild( client.buildSourceLink( source, self.strings ) );
			} else {
				item.textContent = source.title || self.strings.source;
			}

			list.appendChild( item );
		} );

		bubble.appendChild( list );
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
