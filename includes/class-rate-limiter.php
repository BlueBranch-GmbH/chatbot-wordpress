<?php
/**
 * Keeps one visitor from spending the whole request quota.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per client in a transient.
 *
 * The answer routes are open to everybody, because the chat has to work for
 * visitors who are not logged in. The token they carry proves only that the
 * request came from a browser that asked this site for one -- anyone can ask.
 * What actually protects the operator's quota is this counter.
 */
class Rate_Limiter {

	/**
	 * Whether another request may go through, and books it if so.
	 *
	 * @param string $bucket What is being limited, e.g. "answer".
	 * @param int    $limit  How many are allowed within the window.
	 * @param int    $window Length of the window in seconds.
	 * @return bool
	 */
	public static function allow( $bucket, $limit, $window ) {
		$limit = (int) $limit;

		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'bbchat_rl_' . md5( $bucket . '|' . self::fingerprint() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, (int) $window );

		return true;
	}

	/**
	 * A stand-in for the client, kept as a hash.
	 *
	 * The address itself is never written anywhere: a plugin that advertises
	 * processing in Germany and no tracking should not be the thing that
	 * quietly stores visitor IP addresses in the options table.
	 *
	 * @return string
	 */
	private static function fingerprint() {
		$address = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return wp_hash( $address );
	}
}
