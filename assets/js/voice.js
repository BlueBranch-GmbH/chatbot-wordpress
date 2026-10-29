/**
 * Voice input: records a question, shows the level while recording and has the
 * API turn it into text.
 *
 * - Needs a secure context (HTTPS or localhost); otherwise the microphone
 *   button simply stays hidden.
 * - Recording ends by itself after the length the plan allows (maxSeconds).
 * - Nothing is kept in the browser: the buffer lives until it is sent.
 * - Complete silence is not sent at all -- the speech recognition tends to
 *   invent sentences for it, and it would cost quota for nothing.
 * - The API key stays on the server; the recording goes to this site's own
 *   route, with the same token every other request carries.
 */
( function ( window, document ) {
	'use strict';

	var client = window.BlueBranchChatbotClient;
	var CANDIDATES = [ 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4' ];
	var BARS = 30;

	function supported() {
		return true === window.isSecureContext &&
			!! ( window.navigator.mediaDevices && window.navigator.mediaDevices.getUserMedia ) &&
			'undefined' !== typeof window.MediaRecorder;
	}

	/**
	 * Records from the microphone.
	 *
	 * @param {Object} options maxSeconds, onTick(seconds), onLevel(0..1), onStop(), onBlob(blob, type), onError('denied'|'failed').
	 * @return {Object} start(), stop(), cancel() and isRecording().
	 */
	function record( options ) {
		var recorder = null;
		var stream = null;
		var chunks = [];
		var timer = null;
		var elapsed = 0;
		var cancelled = false;
		var audioContext = null;
		var levelTimer = null;

		function release() {
			if ( levelTimer ) {
				window.clearInterval( levelTimer );
				levelTimer = null;
			}

			if ( audioContext ) {
				audioContext.close().catch( function () {} );
				audioContext = null;
			}

			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}

			if ( stream ) {
				stream.getTracks().forEach( function ( track ) {
					track.stop();
				} );
				stream = null;
			}

			recorder = null;
			chunks = [];
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}

			if ( recorder && 'inactive' !== recorder.state ) {
				recorder.stop();
			}
		}

		function startLevel() {
			var Context = window.AudioContext || window.webkitAudioContext;
			var analyser;
			var samples;

			try {
				audioContext = new Context();
				analyser = audioContext.createAnalyser();
				analyser.fftSize = 512;
				audioContext.createMediaStreamSource( stream ).connect( analyser );
				samples = new Uint8Array( analyser.fftSize );

				levelTimer = window.setInterval( function () {
					var sum = 0;
					var i;
					var v;

					analyser.getByteTimeDomainData( samples );

					for ( i = 0; i < samples.length; i++ ) {
						v = ( samples[ i ] - 128 ) / 128;
						sum += v * v;
					}

					// Speech usually sits at an RMS of 0.05 to 0.3.
					options.onLevel( Math.min( 1, Math.sqrt( sum / samples.length ) * 3.2 ) );
				}, 70 );
			} catch ( error ) {
				// Without Web Audio the recording still works, just without a wave.
				audioContext = null;
			}
		}

		return {
			isRecording: function () {
				return !! recorder && 'recording' === recorder.state;
			},
			stop: stop,
			cancel: function () {
				cancelled = true;

				if ( recorder && 'inactive' !== recorder.state ) {
					recorder.stop();
				} else {
					release();
				}
			},
			start: function () {
				cancelled = false;

				return window.navigator.mediaDevices.getUserMedia( { audio: true } ).then( function ( media ) {
					var mimeType = CANDIDATES.filter( function ( type ) {
						return window.MediaRecorder.isTypeSupported( type );
					} )[ 0 ];
					var config = { audioBitsPerSecond: 32000 };

					stream = media;

					if ( mimeType ) {
						config.mimeType = mimeType;
					}

					try {
						// Speech needs little: 32 kbit/s keeps the recording small.
						recorder = new window.MediaRecorder( stream, config );
					} catch ( error ) {
						release();
						options.onError( 'failed' );

						return;
					}

					chunks = [];

					recorder.ondataavailable = function ( event ) {
						if ( event.data && event.data.size ) {
							chunks.push( event.data );
						}
					};

					recorder.onstop = function () {
						var type = ( recorder && recorder.mimeType ) || mimeType || 'audio/webm';
						var blob = new window.Blob( chunks, { type: type } );
						var wasCancelled = cancelled;

						release();
						options.onStop();

						if ( ! wasCancelled && blob.size ) {
							options.onBlob( blob, type );
						}
					};

					if ( options.onLevel ) {
						startLevel();
					}

					elapsed = 0;
					recorder.start();
					options.onTick( 0 );

					timer = window.setInterval( function () {
						elapsed += 1;
						options.onTick( elapsed );

						if ( elapsed >= options.maxSeconds ) {
							stop();
						}
					}, 1000 );
				}, function () {
					release();
					options.onError( 'denied' );
				} );
			}
		};
	}

	/**
	 * Sends a recording to the site and returns the text.
	 *
	 * @param {Blob}   blob     The recording.
	 * @param {string} language Two-letter language code.
	 * @param {Object} strings  Translated texts.
	 * @return {Promise<string>} The text, possibly empty. Rejects with an Error carrying a readable message.
	 */
	function transcribe( blob, language, strings ) {
		var form = new window.FormData();

		form.append( 'audio', blob, 'recording' );
		form.append( 'language', language || '' );

		return client.postForm( '/transcribe', form ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				var max;

				if ( response.ok && data && 'string' === typeof data.text ) {
					return data.text.trim();
				}

				if ( data && 'bluebranch_chatbot_audio_too_long' === data.code ) {
					max = data.data && data.data.maxSeconds ? data.data.maxSeconds : 0;

					throw new Error( max ? strings.voiceTooLong.replace( '%d', max ) : strings.voiceError );
				}

				if ( data && 'bluebranch_chatbot_rate_limit' === data.code ) {
					throw new Error( data.message || strings.voiceLimit );
				}

				throw new Error( strings.voiceError );
			} );
		} );
	}

	function clock( seconds ) {
		return Math.floor( seconds / 60 ) + ':' + String( seconds % 60 ).padStart( 2, '0' );
	}

	/**
	 * Wires a microphone button to a field.
	 *
	 * @param {Object} o button, wrap (where the wave goes), waveBefore (optional), form, noteAfter (element the notes follow),
	 *                   toggleClass (class set on `wrap`), maxSeconds, language, onText(text).
	 * @return {Object|null} setBusy(), cancel(); null when the browser cannot record.
	 */
	function attach( o ) {
		var t = Object.assign( {
			voice: 'Ask by voice',
			voiceStop: 'Finish the recording and send',
			voiceCancel: 'Cancel the recording',
			voiceWorking: 'Turning your question into text …',
			voiceEmpty: 'Nothing was understood. Please try again.',
			voiceDenied: 'The browser does not allow access to the microphone.',
			voiceError: 'The recording could not be turned into text.',
			voiceTooLong: 'The recording was too long (at most %d seconds).',
			voiceLimit: 'Too many requests. Please try again in a moment.'
		}, client.strings || {} );
		var levels = [];
		var bars = [];
		var state = 'idle';
		var busy = false;
		var recorder = null;
		var peak = 0;
		var measured = false;
		var wave;
		var cancel;
		var barsEl;
		var time;
		var note;
		var i;
		var bar;

		if ( ! supported() || ! o.button || ! o.wrap || ! ( o.maxSeconds > 0 ) ) {
			return null;
		}

		for ( i = 0; i < BARS; i++ ) {
			levels.push( 0 );
		}

		wave = document.createElement( 'div' );
		wave.className = 'chatbot-widget__wave';
		wave.hidden = true;

		cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'chatbot-widget__wave-cancel';
		cancel.innerHTML = '&times;';
		cancel.setAttribute( 'aria-label', t.voiceCancel );
		cancel.setAttribute( 'title', t.voiceCancel );

		barsEl = document.createElement( 'div' );
		barsEl.className = 'chatbot-widget__wave-bars';
		barsEl.setAttribute( 'aria-hidden', 'true' );

		for ( i = 0; i < BARS; i++ ) {
			bar = document.createElement( 'span' );
			bar.style.animationDelay = ( i % 12 ) * 80 + 'ms';
			barsEl.appendChild( bar );
			bars.push( bar );
		}

		time = document.createElement( 'span' );
		time.className = 'chatbot-widget__wave-time';

		wave.appendChild( cancel );
		wave.appendChild( barsEl );
		wave.appendChild( time );
		// Where the bars go: in front of a given element (the chat window's
		// text field, so they take its place) or at the end.
		o.wrap.insertBefore( wave, o.waveBefore || null );

		// Feedback (nothing understood, microphone refused ...) below the form.
		note = document.createElement( 'div' );
		note.className = 'chatbot-voice-note';
		note.setAttribute( 'role', 'status' );
		note.hidden = true;

		if ( o.noteAfter && o.noteAfter.parentNode ) {
			o.noteAfter.parentNode.insertBefore( note, o.noteAfter.nextSibling );
		}

		function say( message ) {
			note.textContent = message || '';
			note.hidden = ! message;
		}

		function setState( next ) {
			var recording = 'recording' === next;
			var working = 'working' === next;
			var label = recording ? t.voiceStop : t.voice;

			state = next;

			o.button.classList.toggle( o.recordingClass, recording );
			o.button.setAttribute( 'aria-label', label );
			o.button.setAttribute( 'title', label );
			o.button.setAttribute( 'aria-pressed', recording ? 'true' : 'false' );
			o.button.disabled = working || ( 'idle' === next && busy );

			wave.hidden = ! ( recording || working );
			wave.classList.toggle( 'chatbot-widget__wave--working', working );
			cancel.hidden = working;
			o.wrap.classList.toggle( 'is-voice', recording || working );

			if ( o.form ) {
				o.form.classList.toggle( 'is-voice', recording || working );
			}

			if ( working ) {
				time.textContent = t.voiceWorking;
			}

			if ( 'idle' === next ) {
				levels.fill( 0 );
				bars.forEach( function ( element ) {
					element.style.transform = '';
				} );
			}
		}

		function push( level ) {
			levels.shift();
			levels.push( level );
			bars.forEach( function ( element, index ) {
				element.style.transform = 'scaleY(' + Math.max( 0.1, levels[ index ] ).toFixed( 2 ) + ')';
			} );
		}

		function handBlob( blob ) {
			setState( 'working' );

			transcribe( blob, o.language, t ).then( function ( text ) {
				setState( 'idle' );

				if ( ! text ) {
					say( t.voiceEmpty );

					return;
				}

				o.onText( text );
			} ).catch( function ( error ) {
				setState( 'idle' );
				say( ( error && error.message ) || t.voiceError );
			} );
		}

		function start() {
			if ( 'idle' !== state || busy ) {
				return;
			}

			say( '' );
			peak = 0;
			measured = false;

			recorder = record( {
				maxSeconds: o.maxSeconds,
				onTick: function ( seconds ) {
					time.textContent = clock( seconds ) + ' / ' + clock( o.maxSeconds );
				},
				onLevel: function ( level ) {
					measured = true;
					peak = Math.max( peak, level );
					push( level );
				},
				onStop: function () {
					if ( 'recording' === state ) {
						setState( 'idle' );
					}
				},
				onBlob: function ( blob ) {
					// Silence all the way through: nothing goes to the API.
					if ( measured && peak < 0.04 ) {
						say( t.voiceEmpty );

						return;
					}

					handBlob( blob );
				},
				onError: function ( kind ) {
					setState( 'idle' );
					say( 'denied' === kind ? t.voiceDenied : t.voiceError );
				}
			} );

			// Locked before the permission prompt, so a second click while it is
			// open cannot start a second recording.
			setState( 'recording' );
			recorder.start();
		}

		o.button.hidden = false;
		o.button.addEventListener( 'click', function () {
			if ( 'recording' === state ) {
				recorder.stop();

				return;
			}

			start();
		} );

		cancel.addEventListener( 'click', function () {
			if ( 'recording' === state && recorder ) {
				recorder.cancel();
			}
		} );

		o.wrap.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && 'recording' === state && recorder ) {
				// The chat window closes on Escape as well; this one is meant for the recording.
				event.stopPropagation();
				recorder.cancel();
			}
		} );

		window.addEventListener( 'pagehide', function () {
			if ( 'recording' === state && recorder ) {
				recorder.cancel();
			}
		} );

		return {
			setBusy: function ( value ) {
				busy = !! value;
				o.button.disabled = 'working' === state || ( 'idle' === state && busy );
			},
			cancel: function () {
				if ( 'recording' === state && recorder ) {
					recorder.cancel();
				}
			}
		};
	}

	window.BlueBranchChatbotVoice = {
		supported: supported,
		record: record,
		transcribe: transcribe,
		attach: attach
	};
}( window, document ) );
