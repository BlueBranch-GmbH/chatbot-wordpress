<?php
/**
 * Voice input: whether it is offered, and how long a recording may be.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a microphone button is shown.
 *
 * Two things have to agree: the site owner switched voice input on, and the API
 * has speech recognition set up for the key (`transcriptionEnabled` in the tier
 * answer). The API also names the longest recording its plan allows; it is
 * handed on to the browser, which stops recording by itself at that point.
 *
 * Nothing about a recording is kept: it travels from the browser through this
 * site to the API in memory and is gone afterwards.
 */
class Voice {

	/**
	 * Largest upload accepted, in bytes. At 32 kbit/s the API's hard limit of
	 * 120 seconds is about 500 KB; this leaves ample room.
	 */
	const MAX_BYTES = 4194304;

	/**
	 * How long the API's answer is remembered.
	 */
	const CACHE_TTL = 600;

	/**
	 * How long a failed lookup is remembered, so an unreachable API does not
	 * slow every page down.
	 */
	const CACHE_TTL_FAILED = 60;

	/**
	 * Length assumed when the API names none: the shortest plan.
	 */
	const DEFAULT_SECONDS = 10;

	/**
	 * Audio types accepted, without parameters such as `;codecs=opus`.
	 *
	 * @return string[]
	 */
	public static function mime_types() {
		return array(
			'audio/webm',
			'video/webm',
			'audio/ogg',
			'audio/mp4',
			'video/mp4',
			'audio/mpeg',
			'audio/mp3',
			'audio/aac',
			'audio/x-m4a',
			'audio/wav',
			'audio/x-wav',
		);
	}

	/**
	 * Whether the site owner has switched voice input on.
	 *
	 * @return bool
	 */
	public static function site_enabled() {
		return (bool) Options::get( 'voice_enabled' );
	}

	/**
	 * What the API allows for the stored key.
	 *
	 * @return array{enabled: bool, maxSeconds: int}
	 */
	public static function capability() {
		$key = Options::api_key();

		if ( '' === trim( $key ) ) {
			return array(
				'enabled'    => false,
				'maxSeconds' => self::DEFAULT_SECONDS,
			);
		}

		// Keyed by the key itself, hashed: a new key must not inherit the answer
		// given for the old one.
		$cache_key = 'bbchat_voice_' . substr( hash( 'sha256', $key ), 0, 24 );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['enabled'], $cached['maxSeconds'] ) ) {
			return $cached;
		}

		$tier    = ( new Api_Client() )->get_tier();
		$success = ! is_wp_error( $tier );
		$seconds = $success && isset( $tier['maxAudioSeconds'] ) ? (int) $tier['maxAudioSeconds'] : 0;
		$result  = array(
			'enabled'    => $success && ! empty( $tier['transcriptionEnabled'] ),
			'maxSeconds' => $seconds > 0 ? $seconds : self::DEFAULT_SECONDS,
		);

		set_transient( $cache_key, $result, $success ? self::CACHE_TTL : self::CACHE_TTL_FAILED );

		return $result;
	}

	/**
	 * What a template needs to show the microphone, or false for no microphone.
	 *
	 * @param string $override Shortcode attribute: "no" hides the microphone in this one placement.
	 * @return array{maxSeconds: int, language: string}|false
	 */
	public static function config( $override = '' ) {
		$override = strtolower( trim( (string) $override ) );

		if ( 'no' === $override || 'false' === $override || '0' === $override ) {
			return false;
		}

		if ( ! self::site_enabled() || ! Options::has_api_key() ) {
			return false;
		}

		$capability = self::capability();

		if ( empty( $capability['enabled'] ) ) {
			return false;
		}

		$language = strtolower( substr( (string) get_bloginfo( 'language' ), 0, 2 ) );

		return array(
			'maxSeconds' => (int) $capability['maxSeconds'],
			'language'   => 1 === preg_match( '/^[a-z]{2}$/', $language ) ? $language : 'de',
		);
	}
}
