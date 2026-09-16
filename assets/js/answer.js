/**
 * The answer shown on the page itself.
 *
 * The question reaches it in one of two ways: typed into the field this brings
 * with it, or taken from the query string of a search results page, where the
 * theme's own field has already asked it. Either way the same request goes to
 * the same route and the result is rendered the same way.
 */
( function ( window, document ) {
	'use strict';

	var client = window.BlueBranchChatbotClient;
	var markdown = window.BlueBranchChatbotMarkdown;

	function ChatbotAnswer( container, config ) {
		this.container = container;
		this.config = config;
		this.strings = client.strings;

		this.form = container.querySelector( '.chatbot-ask-form' );
		this.input = container.querySelector( '.chatbot-ask-input' );
		this.response = container.querySelector( '.chatbot-response' );
		this.contentEl = container.querySelector( '.chatbot-content' );
		this.loadingEl = container.querySelector( '.chatbot-loading' );
		this.sourcesEl = container.querySelector( '.chatbot-sources' );
		this.sourcesList = this.sourcesEl ? this.sourcesEl.querySelector( 'ul' ) : null;
		this.suggestionsEl = container.querySelector( '.chatbot-suggestions' );

		this.submitButton = container.querySelector( '.chatbot-submit' );
		this.askLabel = config.askLabel || ( this.submitButton ? this.submitButton.textContent.trim() : '' );
		this.stopLabel = config.stopLabel || client.strings.stop;

		this.activeStream = null;
		this.timerInterval = null;
		this.startTime = null;
		this.placeholder = null;
	}

	ChatbotAnswer.prototype.init = function () {
		var self = this;

		// Above a set of search results this module brings no field of its own,
		// because the theme already has one. Typing the stored questions into
		// that field is the whole point of the feature, so it is looked up
		// rather than done without.
		if ( ! this.input ) {
			this.input = document.querySelector( 'input[type="search"][name="s"], input[name="s"]' );
		}

		// A results page arrives with the term already in the field; a
		// placeholder underneath it would never be seen anyway.
		if ( this.input && '' !== this.input.value ) {
			this.config.questions = [];
		}

		if ( this.input && window.BlueBranchChatbotTypedPlaceholder ) {
			this.placeholder = new window.BlueBranchChatbotTypedPlaceholder( this.input, this.config.questions || [] );
			this.placeholder.start();
		}

		if ( this.form ) {
			this.form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				// While an answer is running the same button is the stop button.
				if ( self.busy ) {
					self.stop();

					return;
				}

				self.submit();
			} );
		}

		// A pill is a question somebody can pick instead of think of. Clicking
		// one puts it in the field as well as sending it, so it is visible
		// afterwards what was actually asked.
		if ( this.suggestionsEl ) {
			Array.prototype.forEach.call(
				this.suggestionsEl.querySelectorAll( '.chatbot-suggestion' ),
				function ( pill ) {
					pill.addEventListener( 'click', function () {
						if ( self.input ) {
							self.input.value = pill.textContent.trim();
						}

						self.submit();
					} );
				}
			);
		}

		// A term that came in with the page is answered straight away; there is
		// nothing for the visitor to submit.
		if ( this.config.query ) {
			this.ask( this.config.query );
		}
	};

	ChatbotAnswer.prototype.submit = function () {
		var question = this.input ? this.input.value.trim() : '';

		if ( '' === question ) {
			if ( this.input ) {
				this.input.focus();
			}

			return;
		}

		// From the first question on, the answer area stays visible; a typed
		// placeholder underneath it would only pull the eye away from reading.
		if ( this.placeholder ) {
			this.placeholder.stop();
		}

		this.ask( question );
	};

	/**
	 * Locks the field and the pills while an answer is on its way.
	 *
	 * Without it a second click starts a second stream that writes into the
	 * same box as the first.
	 */
	ChatbotAnswer.prototype.setBusy = function ( state ) {
		this.busy = state;

		if ( this.input ) {
			this.input.disabled = state;
		}

		// Not disabled: it stays clickable and becomes the way out. A running
		// answer somebody no longer wants is the one moment a button is needed
		// most, and greying it out would take it away exactly then.
		if ( this.submitButton ) {
			this.submitButton.textContent = state ? this.stopLabel : this.askLabel;
			this.submitButton.classList.toggle( 'chatbot-submit--stop', state );
		}

		if ( this.suggestionsEl ) {
			Array.prototype.forEach.call(
				this.suggestionsEl.querySelectorAll( '.chatbot-suggestion' ),
				function ( pill ) {
					pill.disabled = state;
				}
			);
		}
	};

	/**
	 * Abandons the running answer.
	 *
	 * What has already been streamed stays where it is -- it has been read.
	 * Only when nothing arrived at all is a note put in its place, so the
	 * question is not left standing on its own.
	 */
	ChatbotAnswer.prototype.stop = function () {
		if ( ! this.busy ) {
			return;
		}

		if ( this.activeStream ) {
			this.activeStream.close();
			this.activeStream = null;
		}

		this.stopTimer( ! this.answered );
		this.setBusy( false );

		if ( ! this.answered && this.contentEl ) {
			this.contentEl.textContent = this.strings.stopped;
		}

		if ( this.input ) {
			this.input.focus();
		}
	};

	ChatbotAnswer.prototype.ask = function ( question ) {
		var self = this;
		var fullAnswer = '';
		var renderPending = false;

		if ( this.busy ) {
			return;
		}

		if ( this.activeStream ) {
			this.activeStream.close();
			this.activeStream = null;
		}

		this.answered = false;
		this.setBusy( true );
		this.reset();

		if ( this.response ) {
			this.response.hidden = false;
		}

		this.startTimer();

		this.activeStream = client.stream( '/generate/stream', { prompt: question }, {
			onAnswer: function ( chunk ) {
				fullAnswer += chunk;
				self.answered = true;

				if ( ! renderPending ) {
					renderPending = true;

					window.requestAnimationFrame( function () {
						if ( self.contentEl ) {
							self.contentEl.innerHTML = markdown.render( fullAnswer );
						}

						renderPending = false;
					} );
				}
			},
			onSources: function ( sources ) {
				self.renderSources( sources );
			},
			onEnd: function () {
				self.activeStream = null;
				self.stopTimer();
				self.setBusy( false );

				if ( '' === fullAnswer && self.contentEl ) {
					self.contentEl.textContent = self.strings.noAnswer;
				}
			},
			onError: function ( message ) {
				self.activeStream = null;
				self.stopTimer( true );
				self.setBusy( false );

				if ( self.contentEl ) {
					self.contentEl.innerHTML = '';

					var paragraph = document.createElement( 'p' );
					paragraph.className = 'chatbot-error';
					paragraph.textContent = message || self.strings.requestError;
					self.contentEl.appendChild( paragraph );
				}
			}
		} );
	};

	ChatbotAnswer.prototype.reset = function () {
		if ( this.contentEl ) {
			this.contentEl.innerHTML = '';
		}

		if ( this.sourcesList ) {
			this.sourcesList.innerHTML = '';
		}

		if ( this.sourcesEl ) {
			this.sourcesEl.hidden = true;
		}
	};

	ChatbotAnswer.prototype.renderSources = function ( sources ) {
		var self = this;

		if ( ! this.sourcesList || ! this.sourcesEl ) {
			return;
		}

		this.sourcesList.innerHTML = '';

		sources.slice( 0, 3 ).forEach( function ( source ) {
			var item = document.createElement( 'li' );

			if ( source.url ) {
				item.appendChild( client.buildSourceLink( source, self.strings ) );
			} else {
				item.textContent = source.title || self.strings.source;
			}

			self.sourcesList.appendChild( item );
		} );

		this.sourcesEl.hidden = false;
	};

	ChatbotAnswer.prototype.startTimer = function () {
		var self = this;

		this.startTime = Date.now();

		if ( this.loadingEl ) {
			this.loadingEl.hidden = false;
			this.loadingEl.classList.remove( 'finished' );
		}

		this.updateTimer( 0 );

		this.timerInterval = window.setInterval( function () {
			self.updateTimer( Date.now() - self.startTime );
		}, 50 );
	};

	ChatbotAnswer.prototype.stopTimer = function ( failed ) {
		if ( this.timerInterval ) {
			window.clearInterval( this.timerInterval );
			this.timerInterval = null;
		}

		if ( ! this.loadingEl ) {
			return;
		}

		// Nothing was generated, so the elapsed time is not worth reporting --
		// and calling it "answer generated" would be a plain untruth.
		if ( failed ) {
			this.loadingEl.hidden = true;

			return;
		}

		this.loadingEl.classList.add( 'finished' );
		this.updateTimer( Date.now() - this.startTime );
	};

	ChatbotAnswer.prototype.updateTimer = function ( elapsed ) {
		var seconds = Math.floor( elapsed / 1000 );
		var hundredths = Math.floor( ( elapsed % 1000 ) / 10 );
		var formatted = seconds + ':' + String( hundredths ).padStart( 2, '0' );
		var label;

		if ( ! this.loadingEl ) {
			return;
		}

		label = this.loadingEl.classList.contains( 'finished' ) ? this.strings.generated : this.strings.generating;

		this.loadingEl.textContent = label + ' (' + this.strings.elapsed.replace( '%s', formatted ) + ')';
	};

	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-bb-chatbot="search"]' ),
			function ( container ) {
				if ( container.dataset.bbInitialised ) {
					return;
				}

				container.dataset.bbInitialised = '1';

				new ChatbotAnswer( container, client.readConfig( container ) ).init();
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.BlueBranchChatbotAnswer = ChatbotAnswer;
}( window, document ) );
