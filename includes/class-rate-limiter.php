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
	 * @param bool   $site   Count for the whole site instead of per client.
	 * @return bool
	 */
	public static function allow( $bucket, $limit, $window, $site = false ) {
		$limit = (int) $limit;

		if ( $limit < 1 ) {
			return true;
		}

		$key = 'bbchat_rl_' . md5( $bucket . '|' . ( $site ? 'site' : self::fingerprint() ) );

		return self::book( $key, $limit, (int) $window );
	}

	/**
	 * Counts one request against a key.
	 *
	 * With a persistent object cache the counter lives there and is raised
	 * atomically; without one, a transient is all there is. The transient path
	 * is not atomic -- parallel requests can slip past by a few -- which is why
	 * the global limit exists on top of the per-client one.
	 *
	 * @param string $key    Counter key.
	 * @param int    $limit  Allowed requests.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
	private static function book( $key, $limit, $window ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'bluebranch_chatbot', $window );
			$count = wp_cache_incr( $key, 1, 'bluebranch_chatbot' );

			return false !== $count && (int) $count <= $limit;
		}

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * A stand-in for the client, kept as a hash.
	 *
	 * The address itself is never written anywhere: a plugin that advertises
	 * processing in Germany and no tracking should not be the thing that
	 * quietly stores visitor IP addresses in the options table.
	 *
	 * IPv6 addresses count per /64: a single connection usually holds a whole
	 * /64, and counting every address in it separately would let one visitor
	 * rotate past the limit at will.
	 *
	 * @return string
	 */
	private static function fingerprint() {
		$address = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		/**
		 * Filters the client address the rate limit counts by.
		 *
		 * Behind a reverse proxy, CDN or load balancer every visitor arrives
		 * from the proxy's address and shares one counter. Return the real
		 * client address here -- but only from a header your proxy sets and
		 * overwrites, never from one a visitor can send.
		 *
		 * @param string $address REMOTE_ADDR.
		 */
		$address = (string) apply_filters( 'bluebranch_chatbot_client_ip', $address );

		if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $address );

			if ( false !== $packed ) {
				$address = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
			}
		}

		return wp_hash( $address );
	}
}
