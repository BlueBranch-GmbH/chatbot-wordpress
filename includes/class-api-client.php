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
		return untrailingslashit( (string) apply_filters( 'bluebranch_chatbot_api_base', self::API_BASE ) );
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
			$url = add_query_arg( $options['query'], $url );
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

			return new WP_Error( 'bluebranch_chatbot_api_error', $message, array( 'status' => $status ) );
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

		$url = add_query_arg( $query, $this->base() . $endpoint );

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

		Sse::end();
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

		Sse::flush();

		return $length;
	}

	/**
	 * Turns a rejected stream into a log entry and a neutral message.
	 *
	 * @return void
	 */
	private function report_stream_rejection() {
		$detail = trim( wp_strip_all_tags( $this->stream_error_body ) );
		$parsed = json_decode( $detail, true );

		if ( is_array( $parsed ) && isset( $parsed['message'] ) && is_string( $parsed['message'] ) ) {
			$detail = $parsed['message'];
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
			? __( 'There are too many open requests right now. Please try again in a minute.', 'bluebranch-chatbot' )
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
		$result = $this->generate_search( $payload );

		if ( is_wp_error( $result ) ) {
			$status  = (int) $result->get_error_data( 'status' );
			$message = 429 === $status
				? __( 'There are too many open requests right now. Please try again in a minute.', 'bluebranch-chatbot' )
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

		Sse::end();
	}
}
