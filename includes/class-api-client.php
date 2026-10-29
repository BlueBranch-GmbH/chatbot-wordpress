<?php
/**
 * The only place that talks to the Chatbot API.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests to api.chatbot.bluebranch.de through the WordPress HTTP API.
 *
 * The API key is read here and nowhere else, and it never reaches a template,
 * a script or a REST response: the browser asks WordPress, WordPress asks the
 * API.
 */
class Api_Client {

	/**
	 * Where the API lives.
	 */
	const API_BASE = 'https://api.chatbot.bluebranch.de';

	/**
	 * HTTP status of the streamed response, filled in by the write callback.
	 *
	 * @var int|null
	 */
	private $stream_status = null;

	/**
	 * Body of a rejected streamed response, collected for the log.
	 *
	 * @var string
	 */
	private $stream_error_body = '';

	/**
	 * Bytes of the streamed answer not yet split into events.
	 *
	 * @var string
	 */
	private $capture_buffer = '';

	/**
	 * The answer as assembled from the stream so far.
	 *
	 * @var string
	 */
	private $captured_answer = '';

	/**
	 * The sources the stream announced.
	 *
	 * @var array
	 */
	private $captured_sources = array();

	/**
	 * Hands one page to the knowledge base.
	 *
	 * @param array $payload Content to train.
	 * @return array|WP_Error
	 */
	public function train_content( array $payload ) {
		return $this->request( 'POST', '/api/v1/chatbot-ai/content', array( 'body' => $payload ), 45 );
	}

	/**
	 * Removes one entry from the knowledge base.
	 *
	 * @param string $external_id Our identifier for the entry, e.g. post_12.
	 * @return array|WP_Error
	 */
	public function delete_content( $external_id ) {
		return $this->request( 'DELETE', '/api/v1/chatbot-ai/content/' . rawurlencode( $external_id ) );
	}

	/**
	 * Empties the knowledge base.
	 *
	 * @return array|WP_Error
	 */
	public function delete_all_content() {
		return $this->request( 'DELETE', '/api/v1/chatbot-ai/content', array(), 60 );
	}

	/**
	 * Lists what the knowledge base holds.
	 *
	 * @param int $limit  How many chunks to fetch.
	 * @param int $offset Where to start.
	 * @return array|WP_Error
	 */
	public function list_content( $limit = 100, $offset = 0 ) {
		return $this->request(
			'GET',
			'/api/v1/chatbot-ai/content',
			array(
				'query' => array(
					'limit'  => (int) $limit,
					'offset' => (int) $offset,
				),
			),
			60
		);
	}

	/**
	 * Asks which usage tier the stored key belongs to.
	 *
	 * The answer carries the quota and a ready-made notice. Neither is
	 * reproduced in this plugin on purpose: when the quota changes, the API
	 * is deployed and the display follows -- no new plugin release needed.
	 *
	 * @return array|WP_Error
	 */
	public function get_tier() {
		return $this->request( 'GET', '/api/v1/user/me/tier' );
	}

	/**
	 * Turns a recording into text.
	 *
	 * The recording is held in memory only and sent on as a multipart upload;
	 * neither it nor the text that comes back is written to the log or kept. A
	 * request of its own rather than request(): the answer to a recording that
	 * is too long or in the wrong format is the visitor's business, not an API
	 * error the administrator needs to see in the admin notice.
	 *
	 * @param string $audio    Raw bytes of the recording.
	 * @param string $mime     Media type, one of Voice::mime_types().
	 * @param string $language Two-letter language code.
	 * @return array|WP_Error Text on success; on failure the error data carries
	 *                        status, reason and, for a recording that is too
	 *                        long, maxSeconds.
	 */
	public function transcribe( $audio, $mime, $language ) {
		$api_key = Options::api_key();

		if ( '' === trim( $api_key ) ) {
			return new WP_Error(
				'bluebranch_chatbot_no_api_key',
				__( 'No API key has been stored yet.', 'bluebranch-chatbot' )
			);
		}

		if ( ! in_array( $mime, Voice::mime_types(), true ) || 1 !== preg_match( '/^[a-z]{2}$/', (string) $language ) ) {
			return new WP_Error( 'bluebranch_chatbot_bad_audio', '', array( 'status' => 415 ) );
		}

		// The WordPress HTTP API has no multipart helper, so the body is built
		// by hand. The boundary is random and cannot occur in the audio by
		// chance; the media type was checked against a list above, so nothing
		// a visitor sent can end up in a header line.
		$boundary = 'bbchatbot' . wp_generate_password( 24, false, false );
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="language"' . $eol . $eol . $language . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="audio"; filename="recording"' . $eol
			. 'Content-Type: ' . $mime . $eol . $eol
			. $audio . $eol
			. '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			$this->base() . '/api/v1/chatbot-ai/transcribe',
			array(
				'timeout'    => 30,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'    => $api_key,
					'Accept'       => 'application/json',
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'       => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( __( 'The speech recognition of the Chatbot API could not be reached.', 'bluebranch-chatbot' ) );

			return new WP_Error( 'bluebranch_chatbot_unreachable', '', array( 'status' => 503 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		if ( $status >= 400 ) {
			if ( $status >= 500 ) {
				Logger::error(
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The speech recognition of the Chatbot API answered with HTTP %d.', 'bluebranch-chatbot' ),
						$status
					)
				);
			}

			return new WP_Error(
				'bluebranch_chatbot_api_error',
				'',
				array(
					'status'     => $status,
					'reason'     => isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : '',
					'maxSeconds' => isset( $data['maxSeconds'] ) ? (int) $data['maxSeconds'] : 0,
				)
			);
		}

		return array(
			'text' => isset( $data['text'] ) && is_string( $data['text'] ) ? trim( $data['text'] ) : '',
		);
	}

	/**
	 * Asks for an answer without streaming.
	 *
	 * @param array $payload Prompt and language.
	 * @return array|WP_Error
	 */
	public function generate_search( array $payload ) {
		return $this->request( 'POST', '/api/v1/chatbot-ai/generate/search', array( 'body' => $payload ), 120 );
	}

	/**
	 * Streams an answer in chat mode -- short and quick.
	 *
	 * @param array $payload Prompt, language and chat context.
	 * @return void
	 */
	public function stream_chat( array $payload ) {
		$this->stream( '/api/v1/chatbot-ai/generate/chat/stream', $payload, 'chat' );
	}

	/**
	 * Streams an answer in search mode -- longer and more detailed.
	 *
	 * @param array $payload Prompt and language.
	 * @return void
	 */
	public function stream_search( array $payload ) {
		$this->stream( '/api/v1/chatbot-ai/generate/chatbot/stream', $payload, 'search' );
	}

	/**
	 * The API address, so a test installation can point somewhere else.
	 *
	 * @return string
	 */
	private function base() {
		/**
		 * Filters the address of the Chatbot API.
		 *
		 * @param string $base Base URL without a trailing slash.
		 */
		$base = untrailingslashit( (string) apply_filters( 'bluebranch_chatbot_api_base', self::API_BASE ) );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$safe = 0 === strpos( $base, 'https://' );

		// Plain http only for a test API on this machine: the API key travels
		// with every request and must not cross a network unencrypted.
		if ( ! $safe && 0 === strpos( $base, 'http://' ) ) {
			$safe = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
				|| '.test' === substr( $host, -5 )
				|| '.localhost' === substr( $host, -10 );
		}

		if ( ! $safe ) {
			Logger::error( 'The filter bluebranch_chatbot_api_base returned an address without https; the default API is used instead.' );

			return self::API_BASE;
		}

		return $base;
	}

	/**
	 * Identifies this integration to the API.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'BlueBranch-Chatbot-WordPress/' . VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Sends one ordinary request and decodes the answer.
	 *
	 * @param string $method   HTTP verb.
	 * @param string $endpoint Path below the API base.
	 * @param array  $options  Optional 'body' array and 'query' array.
	 * @param int    $timeout  Seconds to wait.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, array $options = array(), $timeout = 30 ) {
		$api_key = Options::api_key();

		if ( '' === trim( $api_key ) ) {
			return new WP_Error(
				'bluebranch_chatbot_no_api_key',
				__( 'No API key has been stored yet.', 'bluebranch-chatbot' )
			);
		}

		$url = $this->base() . $endpoint;

		if ( ! empty( $options['query'] ) ) {
			$url = add_query_arg( self::encode_query( $options['query'] ), $url );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => (int) $timeout,
			'user-agent' => $this->user_agent(),
			'headers'    => array(
				'x-api-key' => $api_key,
				'Accept'    => 'application/json',
			),
		);

		if ( isset( $options['body'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $options['body'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: error message. */
					__( 'The Chatbot API could not be reached (%1$s %2$s): %3$s', 'bluebranch-chatbot' ),
					$method,
					$endpoint,
					$response->get_error_message()
				)
			);

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		$data   = is_array( $data ) ? $data : array();

		if ( $status >= 400 ) {
			$message = isset( $data['message'] ) && is_string( $data['message'] )
				? $data['message']
				: substr( wp_strip_all_tags( $body ), 0, 300 );

			Logger::error(
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: status code, 4: error message. */
					__( 'The Chatbot API rejected a request (%1$s %2$s, HTTP %3$d): %4$s', 'bluebranch-chatbot' ),
					$method,
					$endpoint,
					$status,
					$message
				)
			);

			return new WP_Error(
				'bluebranch_chatbot_api_error',
				$message,
				array(
					'status' => $status,
					'reason' => isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : '',
				)
			);
		}

		Logger::clear_last_error();

		return array_merge( array( 'status' => $status ), $data );
	}

	/**
	 * Opens a streamed request and forwards every chunk to the browser.
	 *
	 * @param string $endpoint Path below the API base.
	 * @param array  $payload  Prompt, language and chat context.
	 * @param string $mode     Either chat or search, for the length filter.
	 * @return void
	 */
	private function stream( $endpoint, array $payload, $mode = '' ) {
		$api_key = Options::api_key();

		if ( '' === trim( $api_key ) ) {
			Sse::error( __( 'The chatbot is not configured yet.', 'bluebranch-chatbot' ) );
			return;
		}

		$query = array(
			'prompt'   => isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '',
			'language' => isset( $payload['language'] ) ? (string) $payload['language'] : 'de',
		);

		if ( ! empty( $payload['chat_context'] ) ) {
			$query['chat_context'] = (string) $payload['chat_context'];
		}

		$max_tokens = $this->answer_tokens( $mode );

		if ( $max_tokens > 0 ) {
			$query['max_tokens'] = $max_tokens;
		}

		$url = add_query_arg( self::encode_query( $query ), $this->base() . $endpoint );

		/*
		 * Without cURL there is no way into the transfer while it runs, so the
		 * answer is fetched in one go and handed on as a single frame. The chat
		 * still works; only the typing effect is missing.
		 */
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_setopt' ) ) {
			$this->stream_in_one_piece( $payload );
			return;
		}

		$this->stream_status     = null;
		$this->stream_error_body = '';
		$this->capture_buffer    = '';
		$this->captured_answer   = '';
		$this->captured_sources  = array();

		add_action( 'http_api_curl', array( $this, 'configure_stream_handle' ), 10, 2 );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'                   => 180,
				'user-agent'                => $this->user_agent(),
				'headers'                   => array(
					'x-api-key' => $api_key,
					'Accept'    => 'text/event-stream',
				),
				// Read back in configure_stream_handle(); no other request carries it.
				'bluebranch_chatbot_stream' => true,
			)
		);

		remove_action( 'http_api_curl', array( $this, 'configure_stream_handle' ), 10 );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Streaming the chatbot answer failed: ' . $response->get_error_message() );
			Sse::error( __( 'The request could not be answered.', 'bluebranch-chatbot' ) );
			return;
		}

		if ( null !== $this->stream_status && $this->stream_status >= 400 ) {
			$this->report_stream_rejection();
			return;
		}

		// Whatever is left in the buffer is the last event, sent without the
		// blank line that would normally close it.
		$this->capture_events( true );
		$this->finish_answer( $payload, $this->captured_answer, $this->captured_sources );

		Sse::end();
	}

	/**
	 * Encodes query values for add_query_arg(), which leaves them as they are.
	 *
	 * Unencoded, a prompt breaks the URL it travels in: "?" and "&" cut it
	 * short, and a "#" turns the rest into a fragment. The page text sent by
	 * "summarise this page" regularly holds a "#" followed by a line break,
	 * and a fragment with a line break is something the Requests library
	 * refuses outright ("Cannot parse supplied IRI") -- the call never leaves
	 * WordPress.
	 *
	 * @param array $query Query arguments.
	 * @return array
	 */
	private static function encode_query( array $query ) {
		return array_map(
			static function ( $value ) {
				return rawurlencode( (string) $value );
			},
			$query
		);
	}

	/**
	 * Keeps the finished answer and tells the browser how to rate it.
	 *
	 * The `meta` event is only sent while feedback is switched on: without it
	 * the reference would be of no use to anybody.
	 *
	 * @param array  $payload Prompt and the context the REST route added under 'log'.
	 * @param string $answer  The complete answer.
	 * @param array  $sources The sources of the answer.
	 * @return void
	 */
	private function finish_answer( array $payload, $answer, array $sources ) {
		if ( empty( $payload['log'] ) || ! is_array( $payload['log'] ) ) {
			return;
		}

		$ref = Answer_Log::record(
			array_merge(
				$payload['log'],
				array(
					'question' => isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '',
					'answer'   => (string) $answer,
					'sources'  => $sources,
					'language' => isset( $payload['language'] ) ? (string) $payload['language'] : '',
				)
			)
		);

		if ( null !== $ref && Answer_Log::feedback_enabled() ) {
			Sse::send( 'meta', array( 'ref' => $ref ) );
		}
	}

	/**
	 * Splits the captured bytes into events and reads answer and sources.
	 *
	 * Only reads; the bytes themselves have long been passed on unchanged.
	 *
	 * @param bool $flush Treat whatever remains as a complete event.
	 * @return void
	 */
	private function capture_events( $flush = false ) {
		$buffer = str_replace( "\r\n", "\n", $this->capture_buffer );
		$blocks = explode( "\n\n", $buffer );

		$this->capture_buffer = $flush ? '' : (string) array_pop( $blocks );

		foreach ( $blocks as $block ) {
			$data = array();

			foreach ( explode( "\n", $block ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data[] = ltrim( substr( $line, 5 ), ' ' );
				}
			}

			if ( array() === $data ) {
				continue;
			}

			$decoded = json_decode( implode( "\n", $data ), true );

			if ( ! is_array( $decoded ) ) {
				continue;
			}

			if ( isset( $decoded['answer'] ) && is_string( $decoded['answer'] ) ) {
				$this->captured_answer .= $decoded['answer'];
			}

			if ( isset( $decoded['sources'] ) && is_array( $decoded['sources'] ) ) {
				$this->captured_sources = $decoded['sources'];
			}
		}
	}

	/**
	 * How many tokens an answer may run to, when anybody has said.
	 *
	 * Nothing is sent by default, which leaves the API's own figures: 250
	 * tokens in chat mode, 800 in search mode. Those are not arbitrary -- the
	 * API sets them out in chat-ai.config.ts, weighing answer length against
	 * the time until a chat reply is finished, on knowledge of the model and
	 * the hardware it runs on.
	 *
	 * So there is no field for this in the settings. Turning that dial up in
	 * wp-admin overrules a decision made with information the person turning
	 * it does not have, and mostly buys latency: on a knowledge base that has
	 * said all it has to say, a larger budget produces the same answer more
	 * slowly. What limits an answer is nearly always the content behind it.
	 *
	 * The filter is here for the installation that genuinely differs. It is
	 * deliberately PHP rather than a setting -- the people who should be
	 * making this call are the ones who can write a line of it.
	 *
	 * @param string $mode Either chat or search.
	 * @return int Tokens, or 0 to leave the API's default alone.
	 */
	private function answer_tokens( $mode ) {
		if ( '' === $mode ) {
			return 0;
		}

		/**
		 * Filters the answer length in tokens for one mode.
		 *
		 * @param int    $tokens 0 leaves the API's own default in place.
		 * @param string $mode   Either 'chat' or 'search'.
		 */
		$tokens = (int) apply_filters( 'bluebranch_chatbot_answer_tokens', 0, $mode );

		// Out of range means "say nothing", not "send something the API will
		// refuse" -- a refused request is an answer nobody gets.
		return ( $tokens >= 32 && $tokens <= 2000 ) ? $tokens : 0;
	}

	/**
	 * Redirects the running transfer into the browser instead of into a buffer.
	 *
	 * WP_Http_Curl fires http_api_curl after it has set its own write callback
	 * and before curl_exec(), which makes this hook the documented way to take
	 * the bytes as they arrive while staying inside the WordPress HTTP API.
	 *
	 * @param resource|\CurlHandle $handle      The cURL handle, by reference.
	 * @param array                $parsed_args Arguments the request was made with.
	 * @return void
	 */
	public function configure_stream_handle( &$handle, $parsed_args ) {
		if ( empty( $parsed_args['bluebranch_chatbot_stream'] ) ) {
			return;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- This is the payload of the http_api_curl hook, which exists to configure exactly this handle.
		curl_setopt( $handle, CURLOPT_WRITEFUNCTION, array( $this, 'write_stream_chunk' ) );
		curl_setopt( $handle, CURLOPT_BUFFERSIZE, 1024 );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
	}

	/**
	 * Writes one chunk of the API answer straight to the client.
	 *
	 * The status is read on the first chunk. Checking it later would be too
	 * late: the browser has long since been handed a 200 with an event stream
	 * and would see the connection break off for no visible reason.
	 *
	 * @param resource|\CurlHandle $handle The cURL handle.
	 * @param string               $chunk  Bytes received.
	 * @return int Bytes handled; anything else aborts the transfer.
	 */
	public function write_stream_chunk( $handle, $chunk ) {
		$length = strlen( $chunk );

		if ( null === $this->stream_status ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Reading the status of the handle the HTTP API handed us.
			$this->stream_status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		}

		if ( $this->stream_status >= 400 ) {
			// Collected for the log, never forwarded: a rejection carries the
			// operator's tier and contact address, which is nothing a website
			// visitor should be reading in a chat window.
			if ( strlen( $this->stream_error_body ) < 2000 ) {
				$this->stream_error_body .= $chunk;
			}

			return $length;
		}

		/*
		 * Passed through unescaped and on purpose: these bytes are an
		 * already-framed text/event-stream body on its way to an EventSource,
		 * not markup. Escaping here would corrupt the framing. The answer is
		 * escaped in the browser, before it reaches the DOM.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verbatim SSE frames; see above.
		echo $chunk;

		// Read along so the finished answer can be kept for feedback. Capped,
		// so a runaway stream cannot grow the buffer without limit.
		if ( strlen( $this->captured_answer ) < 200000 ) {
			$this->capture_buffer .= $chunk;
			$this->capture_events();
		}

		Sse::flush();

		return $length;
	}

	/**
	 * What a visitor reads when the API turned a question down over quota.
	 *
	 * The API names the reason: a full minute clears itself, a used-up
	 * monthly quota only when the next month starts. Telling a visitor to
	 * try again in a minute would be wrong for the second case. Neither
	 * message names the plan or the contact address -- that is the
	 * operator's business, and it sits in the log and on the admin screens.
	 *
	 * @param string $reason `rate_limit`, `monthly_quota` or empty.
	 * @return string
	 */
	public static function limit_message( $reason ) {
		if ( 'monthly_quota' === $reason ) {
			return __( 'The chat is not available at the moment. Please try again later.', 'bluebranch-chatbot' );
		}

		return __( 'There are too many open requests right now. Please try again in a minute.', 'bluebranch-chatbot' );
	}

	/**
	 * Turns a rejected stream into a log entry and a neutral message.
	 *
	 * @return void
	 */
	private function report_stream_rejection() {
		$detail = trim( wp_strip_all_tags( $this->stream_error_body ) );
		$parsed = json_decode( $detail, true );
		$reason = '';

		if ( is_array( $parsed ) && isset( $parsed['message'] ) && is_string( $parsed['message'] ) ) {
			$detail = $parsed['message'];
		}

		if ( is_array( $parsed ) && isset( $parsed['reason'] ) && is_string( $parsed['reason'] ) ) {
			$reason = $parsed['reason'];
		}

		Logger::error(
			sprintf(
				/* translators: 1: status code, 2: message from the API. */
				__( 'The Chatbot API answered a stream request with HTTP %1$d: %2$s', 'bluebranch-chatbot' ),
				$this->stream_status,
				substr( $detail, 0, 300 )
			)
		);

		$message = 429 === $this->stream_status
			? self::limit_message( $reason )
			: __( 'The request could not be answered.', 'bluebranch-chatbot' );

		Sse::error( $message, $this->stream_status );
	}

	/**
	 * Fetches the answer in one request and emits it as a single frame.
	 *
	 * @param array $payload Prompt and language.
	 * @return void
	 */
	private function stream_in_one_piece( array $payload ) {
		// Only what the API knows: the log context is this plugin's business.
		$result = $this->generate_search( array_intersect_key( $payload, array_flip( array( 'prompt', 'language' ) ) ) );

		if ( is_wp_error( $result ) ) {
			$data    = (array) $result->get_error_data();
			$status  = isset( $data['status'] ) ? (int) $data['status'] : 0;
			$message = 429 === $status
				? self::limit_message( isset( $data['reason'] ) ? (string) $data['reason'] : '' )
				: __( 'The request could not be answered.', 'bluebranch-chatbot' );

			Sse::error( $message, $status );
			return;
		}

		if ( ! empty( $result['sources'] ) && is_array( $result['sources'] ) ) {
			Sse::send( '', array( 'sources' => $result['sources'] ) );
		}

		if ( ! empty( $result['answer'] ) ) {
			Sse::send( '', array( 'answer' => (string) $result['answer'] ) );
		}

		$this->finish_answer(
			$payload,
			isset( $result['answer'] ) ? (string) $result['answer'] : '',
			isset( $result['sources'] ) && is_array( $result['sources'] ) ? $result['sources'] : array()
		);

		Sse::end();
	}
}
