/**
 * Types the stored questions into the placeholder of an input field, one after
 * the other.
 *
 * Used by both the ask field and the search field, so the class knows about
 * nothing but the field and the questions.
 */
( function ( window ) {
	'use strict';

	function ChatbotTypedPlaceholder( input, questions, options ) {
		options = options || {};

		this.input = input;
		this.questions = Array.isArray( questions )
			? questions.filter( function ( question ) {
				return 'string' === typeof question && '' !== question.trim();
			} )
			: [];

		this.typeDelay = options.typeDelay || 45;
		this.deleteDelay = options.deleteDelay || 25;
		this.holdDelay = options.holdDelay || 1800;
		this.restDelay = options.restDelay || 400;

		// The placeholder from the markup is the fallback: whenever the
		// animation rests or never starts, it is what stands in the field.
		this.fallback = input ? input.getAttribute( 'placeholder' ) || '' : '';

		this.questionIndex = 0;
		this.charIndex = 0;
		this.deleting = false;
		this.timer = null;
		this.running = false;
	}

	/**
	 * Starts only when there is something to type and the animation disturbs
	 * nobody: with prefers-reduced-motion the first question stands still.
	 */
	ChatbotTypedPlaceholder.prototype.start = function () {
		if ( ! this.input || 0 === this.questions.length || this.running ) {
			return;
		}

		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			this.input.setAttribute( 'placeholder', this.questions[ 0 ] );

			return;
		}

		this.running = true;
		this.bindEvents();
		this.schedule( this.restDelay );
	};

	ChatbotTypedPlaceholder.prototype.stop = function () {
		this.running = false;
		window.clearTimeout( this.timer );
		this.timer = null;

		if ( this.input ) {
			this.input.setAttribute( 'placeholder', this.fallback );
		}
	};

	/**
	 * While somebody is typing, or the field holds text, a wandering
	 * placeholder has no business being there -- and in a background tab
	 * nothing needs to run at all.
	 */
	ChatbotTypedPlaceholder.prototype.bindEvents = function () {
		var self = this;

		this.input.addEventListener( 'focus', function () {
			self.pause();
		} );

		this.input.addEventListener( 'blur', function () {
			self.resume();
		} );

		this.input.addEventListener( 'input', function () {
			if ( '' !== self.input.value ) {
				self.pause();
			}
		} );

		window.document.addEventListener( 'visibilitychange', function () {
			if ( window.document.hidden ) {
				self.pause();
			} else {
				self.resume();
			}
		} );
	};

	ChatbotTypedPlaceholder.prototype.pause = function () {
		window.clearTimeout( this.timer );
		this.timer = null;
		this.charIndex = 0;
		this.deleting = false;

		if ( this.input ) {
			this.input.setAttribute( 'placeholder', this.fallback );
		}
	};

	ChatbotTypedPlaceholder.prototype.resume = function () {
		if ( ! this.running || null !== this.timer || window.document.hidden ) {
			return;
		}

		if ( this.input && ( '' !== this.input.value || this.input === window.document.activeElement ) ) {
			return;
		}

		this.schedule( this.restDelay );
	};

	ChatbotTypedPlaceholder.prototype.schedule = function ( delay ) {
		var self = this;

		this.timer = window.setTimeout( function () {
			self.tick();
		}, delay );
	};

	ChatbotTypedPlaceholder.prototype.tick = function () {
		var question = this.questions[ this.questionIndex ];

		if ( ! this.deleting ) {
			this.charIndex += 1;
			this.input.setAttribute( 'placeholder', question.slice( 0, this.charIndex ) );

			if ( this.charIndex >= question.length ) {
				this.deleting = true;
				this.schedule( this.holdDelay );

				return;
			}

			this.schedule( this.typeDelay );

			return;
		}

		this.charIndex -= 1;
		this.input.setAttribute( 'placeholder', question.slice( 0, Math.max( this.charIndex, 0 ) ) );

		if ( this.charIndex <= 0 ) {
			this.deleting = false;
			this.questionIndex = ( this.questionIndex + 1 ) % this.questions.length;
			this.schedule( this.restDelay );

			return;
		}

		this.schedule( this.deleteDelay );
	};

	window.BlueBranchChatbotTypedPlaceholder = ChatbotTypedPlaceholder;
}( window ) );
