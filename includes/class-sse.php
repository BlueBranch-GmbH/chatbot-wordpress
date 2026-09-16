<?php
/**
 * Server-sent events, the shape the answer routes speak.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Opens an event stream and writes frames into it.
 *
 * The browser talks to WordPress and WordPress talks to the API, so the answer
 * has to be handed on piece by piece as it arrives. Everything that would hold
 * bytes back -- PHP's output buffers, zlib compression, an nginx in front --
 * has to be switched off first, otherwise the answer lands in one lump at the
 * end and the typing effect is gone.
 */
class Sse {

	/**
	 * The events this plugin emits. Anything else is dropped rather than
	 * written into the stream, so a frame name can never be attacker-chosen.
	 *
	 * @var string[]
	 */
	private static $allowed_events = array( 'error', 'end', 'sources' );

	/**
	 * Sends the headers and dismantles every output buffer.
	 *
	 * @return void
	 */
	public static function start() {
		if ( ! headers_sent() ) {
			// Replaces the application/json header the REST server has already queued.
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Connection: keep-alive' );
			// Tells nginx not to buffer this response; without it the stream arrives in one piece.
			header( 'X-Accel-Buffering: no' );
		}

		/*
		 * A compressed response cannot be flushed chunk by chunk: zlib holds the
		 * bytes back until it has enough of them to compress.
		 */
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Switching compression off for this one response is the only way to stream it at all.
		ini_set( 'zlib.output_compression', '0' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Writes one frame.
	 *
	 * @param string $event Event name, or an empty string for a plain message.
	 * @param array  $data  Payload, encoded as JSON.
	 * @return void
	 */
	public static function send( $event, array $data ) {
		$event = sanitize_key( $event );

		if ( '' !== $event && in_array( $event, self::$allowed_events, true ) ) {
			echo 'event: ' . esc_html( $event ) . "\n";
		}

		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		self::flush();
	}

	/**
	 * Hands the client an error and keeps the frame shape the scripts expect.
	 *
	 * @param string $message Text shown to the visitor.
	 * @param int    $status  Upstream status code, if there was one.
	 * @param string $code    Machine-readable reason, so the script can tell an
	 *                        expired token from a real failure and fetch a new one.
	 * @return void
	 */
	public static function error( $message, $status = 0, $code = '' ) {
		self::send(
			'error',
			array(
				'message' => (string) $message,
				'status'  => (int) $status,
				'code'    => (string) $code,
			)
		);
	}

	/**
	 * Closes the stream.
	 *
	 * @return void
	 */
	public static function end() {
		self::send( 'end', array( 'status' => 'completed' ) );
	}

	/**
	 * Pushes whatever is pending out to the client.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}

		flush();
	}
}
