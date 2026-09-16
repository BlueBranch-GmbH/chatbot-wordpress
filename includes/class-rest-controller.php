<?php
/**
 * The routes the browser is allowed to call.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Puts WordPress between the browser and the API.
 *
 * The browser never sees the API key, and never speaks to the API: it calls
 * these routes, and they do the talking. That is the whole reason the answer
 * goes the long way round instead of being fetched from the page.
 */
class Rest_Controller {

	/**
	 * Route namespace.
	 */
	const REST_NAMESPACE = 'bluebranch-chatbot/v1';

	/**
	 * How many posts one training batch handles.
	 */
	const TRAIN_BATCH_SIZE = 3;

	/**
	 * Transient holding the queue of a running training run.
	 */
	const TRAIN_QUEUE = 'bluebranch_chatbot_train_queue';

	/**
	 * Hooks the routes into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$prompt_args = array(
			'prompt'            => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_prompt' ),
				'validate_callback' => array( $this, 'validate_prompt' ),
			),
			'language'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'chat_context'      => array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_prompt' ),
			),
			Answer_Token::PARAM => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		/*
		 * A token the page can fetch at the moment somebody starts typing.
		 * Printing it into the markup instead would put it in the page cache,
		 * where it goes stale and every visitor gets the same expired one.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat/stream',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stream_chat' ),
				'permission_callback' => '__return_true',
				'args'                => $prompt_args,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/generate/stream',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stream_generate' ),
				'permission_callback' => '__return_true',
				'args'                => $prompt_args,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/generate/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_search' ),
				'permission_callback' => '__return_true',
				'args'                => $prompt_args,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_content' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_all_content' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/content/(?P<external_id>[A-Za-z0-9_.\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_content' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'external_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/tier',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tier' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/train',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'train_batch' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'reset'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'force'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/crawl/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'crawl_preview' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Shows what the crawler would actually train for one page.
	 *
	 * Whether the furniture really was removed is not something anybody should
	 * have to take on trust, or find out by reading a chatbot answer that
	 * quotes the cookie notice. This route answers it directly.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function crawl_preview( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );

		if ( '' === $url ) {
			$url = (string) home_url( '/' );
		}

		$html = ( new Crawler() )->fetch( $url );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$boilerplate = ( new Boilerplate() )->stored();
		$extracted   = ( new Page_Extractor() )->extract( $html, $boilerplate );
		$markdown    = ( new Html_To_Markdown() )->convert( $extracted );
		$post_id     = Indexer::post_id_for_url( $url );

		return new WP_REST_Response(
			array(
				'url'         => $url,
				'post_id'     => $post_id,
				'marked'      => false !== strpos( $html, Crawl_Marker::ATTRIBUTE ),
				'boilerplate' => count( $boilerplate ),
				'page_length' => strlen( wp_strip_all_tags( $html ) ),
				'kept_length' => strlen( wp_strip_all_tags( $extracted ) ),
				'markdown'    => $markdown,
			)
		);
	}

	/**
	 * Trims a prompt to something a URL can carry.
	 *
	 * The "summarise this page" button sends the page text along, and a long
	 * page would otherwise run past the request line limit of the server in
	 * front -- where it is refused before any of this code runs.
	 *
	 * @param string $value Raw prompt.
	 * @return string
	 */
	public function sanitize_prompt( $value ) {
		$value = wp_strip_all_tags( (string) $value );

		/**
		 * Filters how many characters a prompt may carry.
		 *
		 * @param int $length Maximum number of characters.
		 */
		$length = (int) apply_filters( 'bluebranch_chatbot_max_prompt_length', 4000 );

		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $value, 0, $length, 'UTF-8' ) );
		}

		return trim( substr( $value, 0, $length ) );
	}

	/**
	 * Refuses an empty question.
	 *
	 * @param string $value Sanitised prompt.
	 * @return bool
	 */
	public function validate_prompt( $value ) {
		return '' !== trim( (string) $value );
	}

	/**
	 * Hands out a fresh token.
	 *
	 * @return WP_REST_Response
	 */
	public function get_token() {
		if ( ! Rate_Limiter::allow( 'token', 60, MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Too many requests.', 'bluebranch-chatbot' ) ), 429 );
		}

		return new WP_REST_Response(
			array(
				'token' => Answer_Token::issue(),
				'param' => Answer_Token::PARAM,
			)
		);
	}

	/**
	 * Streams an answer in chat mode.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return void
	 */
	public function stream_chat( WP_REST_Request $request ) {
		$this->stream( $request, 'chat' );
	}

	/**
	 * Streams an answer in search mode.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return void
	 */
	public function stream_generate( WP_REST_Request $request ) {
		$this->stream( $request, 'search' );
	}

	/**
	 * Opens the event stream and lets the API client fill it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $mode    Either chat or search.
	 * @return void
	 */
	private function stream( WP_REST_Request $request, $mode ) {
		Sse::start();

		$refusal = $this->public_request_refusal( $request );

		if ( null !== $refusal ) {
			// Answered inside the stream rather than as an HTTP error: an
			// EventSource shown a 403 only reports "connection failed", which
			// tells the visitor nothing and the operator even less.
			Sse::error( $refusal->get_error_message(), 403, $refusal->get_error_code() );
			exit;
		}

		$payload = array(
			'prompt'       => (string) $request->get_param( 'prompt' ),
			'language'     => $this->request_language( $request ),
			'chat_context' => (string) $request->get_param( 'chat_context' ),
		);

		$client = new Api_Client();

		if ( 'chat' === $mode ) {
			$client->stream_chat( $payload );
		} else {
			$client->stream_search( $payload );
		}

		exit;
	}

	/**
	 * Answers without streaming, for callers that cannot use an event stream.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_search( WP_REST_Request $request ) {
		$refusal = $this->public_request_refusal( $request );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$result = ( new Api_Client() )->generate_search(
			array(
				'prompt'   => (string) $request->get_param( 'prompt' ),
				'language' => $this->request_language( $request ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->public_error( $result );
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Lists the trained content, grouped by entry rather than by chunk.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_content() {
		$result = ( new Api_Client() )->list_content( 5000, 0 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows    = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$grouped = array();

		// The API returns one row per chunk; the screen shows one row per page,
		// with the chunk count as a column.
		foreach ( $rows as $row ) {
			$external_id = isset( $row['externalId'] ) ? (string) $row['externalId'] : 'unknown';

			if ( ! isset( $grouped[ $external_id ] ) ) {
				$row['chunkCount']       = 1;
				$grouped[ $external_id ] = $row;

				continue;
			}

			++$grouped[ $external_id ]['chunkCount'];
		}

		return new WP_REST_Response( array( 'items' => array_values( $grouped ) ) );
	}

	/**
	 * Removes one entry from the knowledge base.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_content( WP_REST_Request $request ) {
		$external_id = (string) $request->get_param( 'external_id' );
		$result      = ( new Api_Client() )->delete_content( $external_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Keeps the local marker honest: without this the clean-up run would
		// believe the entry is still there and the training page would not
		// offer the post again.
		if ( preg_match( '/^post_(\d+)$/', $external_id, $matches ) ) {
			delete_post_meta( (int) $matches[1], Indexer::META_TRAINED );
		}

		return new WP_REST_Response( array( 'deleted' => $external_id ) );
	}

	/**
	 * Empties the knowledge base.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_all_content() {
		$result = ( new Api_Client() )->delete_all_content();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->forget_all_trained_markers();

		return new WP_REST_Response( array( 'deleted' => 'all' ) );
	}

	/**
	 * Reports the usage tier of the stored key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tier() {
		$result = ( new Api_Client() )->get_tier();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Trains the next few posts of a run.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function train_batch( WP_REST_Request $request ) {
		if ( ! Options::has_api_key() ) {
			return new WP_Error(
				'bluebranch_chatbot_no_api_key',
				__( 'No API key has been stored yet.', 'bluebranch-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$indexer = new Indexer();

		if ( $request->get_param( 'reset' ) ) {
			return $this->start_training_run( $indexer );
		}

		$queue = get_transient( self::TRAIN_QUEUE );

		if ( ! is_array( $queue ) ) {
			return new WP_Error(
				'bluebranch_chatbot_no_queue',
				__( 'The training run has expired. Please start it again.', 'bluebranch-chatbot' ),
				array( 'status' => 409 )
			);
		}

		$offset  = (int) $request->get_param( 'offset' );
		$force   = (bool) $request->get_param( 'force' );
		$slice   = array_slice( $queue, $offset, self::TRAIN_BATCH_SIZE, true );
		$trained = array();

		foreach ( $slice as $post_id => $url ) {
			$outcome = $indexer->train( (int) $post_id, is_string( $url ) ? $url : '', $force );

			$trained[] = array(
				'id'      => (int) $post_id,
				'title'   => get_the_title( (int) $post_id ),
				'outcome' => $outcome,
				'success' => in_array( $outcome, array( 'trained', 'unchanged' ), true ),
			);
		}

		$next = $offset + count( $slice );

		return new WP_REST_Response(
			array(
				'total'   => count( $queue ),
				'offset'  => $next,
				'trained' => $trained,
				'done'    => $next >= count( $queue ),
			)
		);
	}

	/**
	 * Works out what a run will visit and remembers it for the batches.
	 *
	 * The list is derived once. Re-deriving it per batch would let a post that
	 * stopped qualifying halfway through shift every later offset by one, and
	 * posts would be passed over with nothing to show it had happened.
	 *
	 * @param Indexer $indexer The indexer.
	 * @return WP_REST_Response|WP_Error
	 */
	private function start_training_run( Indexer $indexer ) {
		$unresolved = array();

		if ( Options::crawls() ) {
			$loopback = ( new Crawler() )->check_loopback();

			if ( is_wp_error( $loopback ) ) {
				return new WP_Error(
					'bluebranch_chatbot_loopback',
					sprintf(
						/* translators: %s: the underlying error message. */
						__( 'This site cannot fetch its own pages, so crawling is not possible here: %s', 'bluebranch-chatbot' ),
						$loopback->get_error_message()
					),
					array( 'status' => 502 )
				);
			}

			$found = $indexer->crawl_targets();

			if ( is_wp_error( $found ) ) {
				return $found;
			}

			$queue      = $found['targets'];
			$unresolved = $found['unresolved'];

			// Sampled before the run rather than during it, so every page of
			// the run is measured against the same idea of what the furniture is.
			( new Boilerplate() )->learn( array_values( $queue ) );
		} else {
			$queue = array_fill_keys( $indexer->trainable_ids(), '' );
		}

		set_transient( self::TRAIN_QUEUE, $queue, HOUR_IN_SECONDS );

		return new WP_REST_Response(
			array(
				'total'      => count( $queue ),
				'offset'     => 0,
				'trained'    => array(),
				'unresolved' => count( $unresolved ),
				'source'     => Options::crawls() ? 'crawl' : 'render',
				'done'       => array() === $queue,
			)
		);
	}

	/**
	 * Whether the caller may manage the knowledge base.
	 *
	 * @return bool
	 */
	public function check_manage_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Why a public request must be turned away, or null when it may proceed.
	 *
	 * The error code matters: a token that has simply aged out is not a
	 * failure the visitor should be told about, it is one the script fixes by
	 * fetching a new one. Only a code distinguishes that from a real refusal.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Error|null
	 */
	private function public_request_refusal( WP_REST_Request $request ) {
		if ( ! Answer_Token::verify( $request->get_param( Answer_Token::PARAM ) ) ) {
			return new WP_Error(
				'bluebranch_chatbot_token',
				__( 'Your session has expired. Please reload the page and try again.', 'bluebranch-chatbot' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filters how many answers one client may ask for per minute.
		 *
		 * @param int $limit Number of requests.
		 */
		$limit = (int) apply_filters( 'bluebranch_chatbot_rate_limit', 20 );

		if ( ! Rate_Limiter::allow( 'answer', $limit, MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'bluebranch_chatbot_rate_limit',
				__( 'There are too many open requests right now. Please try again in a minute.', 'bluebranch-chatbot' ),
				array( 'status' => 429 )
			);
		}

		if ( ! Options::has_api_key() ) {
			return new WP_Error(
				'bluebranch_chatbot_not_configured',
				__( 'The chatbot is not configured yet.', 'bluebranch-chatbot' ),
				array( 'status' => 503 )
			);
		}

		return null;
	}

	/**
	 * The language sent to the API.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string
	 */
	private function request_language( WP_REST_Request $request ) {
		$language = (string) $request->get_param( 'language' );

		if ( '' === $language ) {
			$language = strtolower( substr( (string) get_bloginfo( 'language' ), 0, 2 ) );
		}

		if ( '' === $language ) {
			$language = 'de';
		}

		/**
		 * Filters the language code sent with an answer request.
		 *
		 * @param string $language Two-letter code.
		 */
		return (string) apply_filters( 'bluebranch_chatbot_request_language', $language );
	}

	/**
	 * Turns an API error into something a visitor may read.
	 *
	 * The detailed reason stays in the log: a rejection names the operator's
	 * tier and contact address, which belongs in the operations view and not
	 * in front of website visitors.
	 *
	 * @param WP_Error $error The error from the API client.
	 * @return WP_Error
	 */
	private function public_error( WP_Error $error ) {
		$status = (int) $error->get_error_data( 'status' );

		$message = 429 === $status
			? __( 'There are too many open requests right now. Please try again in a minute.', 'bluebranch-chatbot' )
			: __( 'The request could not be answered.', 'bluebranch-chatbot' );

		return new WP_Error( 'bluebranch_chatbot_failed', $message, array( 'status' => 502 ) );
	}

	/**
	 * Drops the "trained" marker from every post.
	 *
	 * @return void
	 */
	private function forget_all_trained_markers() {
		global $wpdb;

		// No WP_Query equivalent exists for dropping a meta key across every
		// post, and doing it post by post would be thousands of queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => Indexer::META_TRAINED ) );

		// Rows were removed behind the object cache's back, so the meta cache
		// now disagrees with the database. Heavy, but this runs only when an
		// administrator empties the whole knowledge base.
		wp_cache_flush();
	}
}
