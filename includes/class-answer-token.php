<?php
/**
 * The short-lived token the public answer routes ask for.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and checks a signed, expiring token.
 *
 * A WordPress nonce would be the obvious choice and is the wrong one here, for
 * two reasons.
 *
 * A nonce is tied to the user it was created for. The answer routes are public
 * -- they have to work for visitors who are not logged in -- so the REST server
 * sets the current user to 0 for them. A token an administrator's browser
 * fetched while logged in would then be checked against a different user and
 * never verify, and the test field in wp-admin would refuse every question.
 *
 * WordPress also inspects `_wpnonce` itself and answers an invalid one with a
 * JSON error before the route is ever reached. On an event stream that arrives
 * as a bare connection failure: the visitor is told nothing, and the script
 * cannot tell an expired token -- which it should quietly replace -- from a
 * real fault.
 *
 * So this is what it actually is: a signed marker with a lifetime, keyed on
 * the site's own secret, bound to no user, which raises the cost of pointing a
 * script at somebody else's quota. The rate limiter is what enforces the
 * limit; see Rate_Limiter.
 */
class Answer_Token {

	/**
	 * How long an issued token stays valid.
	 */
	const LIFETIME = 12 * HOUR_IN_SECONDS;

	/**
	 * Query parameter the token travels in.
	 *
	 * Deliberately not `_wpnonce`: WordPress claims that name and would answer
	 * the request before this plugin sees it.
	 */
	const PARAM = 'bb_token';

	/**
	 * Hands out a fresh token.
	 *
	 * @return string
	 */
	public static function issue() {
		$expires = time() + self::LIFETIME;

		return $expires . '.' . self::signature( $expires );
	}

	/**
	 * Whether a token was issued by this site and has not expired.
	 *
	 * @param mixed $token What the request carried.
	 * @return bool
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $matches ) ) {
			return false;
		}

		$expires = (int) $matches[1];

		if ( $expires < time() ) {
			return false;
		}

		// Constant time, so the comparison cannot be used to work out the signature.
		return hash_equals( self::signature( $expires ), $matches[2] );
	}

	/**
	 * Signs an expiry with the site's own secret.
	 *
	 * @param int $expires Unix timestamp the token stops being valid at.
	 * @return string
	 */
	private static function signature( $expires ) {
		return hash_hmac( 'sha256', 'bluebranch_chatbot_answer|' . $expires, wp_salt( 'nonce' ) );
	}
}
