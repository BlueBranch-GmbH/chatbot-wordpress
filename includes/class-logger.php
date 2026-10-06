<?php
/**
 * Where the plugin says what went wrong.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the PHP error log and keeps the last error for the admin screens.
 *
 * WordPress has no log an administrator can read, so an API failure would
 * otherwise be invisible: the chat simply stays silent and nobody can say why.
 */
class Logger {

	/**
	 * Records an error and remembers it for the admin notice.
	 *
	 * @param string $message What happened.
	 * @param array  $context Extra values worth keeping.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		$stored = get_option( Options::LAST_ERROR );

		// Written only when it says something new: the answer routes are public,
		// and a failing API would otherwise mean one database write per visitor.
		if ( ! is_array( $stored ) || ( $stored['message'] ?? '' ) !== (string) $message || time() - (int) ( $stored['time'] ?? 0 ) > 5 * MINUTE_IN_SECONDS ) {
			update_option(
				Options::LAST_ERROR,
				array(
					'message' => (string) $message,
					'time'    => time(),
				),
				false
			);
		}

		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Records something that is only of interest while debugging.
	 *
	 * @param string $message What happened.
	 * @param array  $context Extra values worth keeping.
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		if ( ! Options::get( 'debug_logging' ) ) {
			return;
		}

		self::write( 'DEBUG', $message, $context );
	}

	/**
	 * Clears the remembered error.
	 *
	 * @return void
	 */
	public static function clear_last_error() {
		// Checked first: delete_option() costs a query even when there is nothing
		// to delete, and this runs on every successful public request.
		if ( false !== get_option( Options::LAST_ERROR ) ) {
			delete_option( Options::LAST_ERROR );
		}
	}

	/**
	 * The last error, or null.
	 *
	 * @return array{message: string, time: int}|null
	 */
	public static function last_error() {
		$stored = get_option( Options::LAST_ERROR );

		return is_array( $stored ) && isset( $stored['message'] ) ? $stored : null;
	}

	/**
	 * Hands one line to the PHP error log.
	 *
	 * @param string $level   ERROR or DEBUG.
	 * @param string $message What happened.
	 * @param array  $context Extra values worth keeping.
	 * @return void
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			if ( 'DEBUG' === $level ) {
				return;
			}
		}

		$line = sprintf( '[BlueBranch Chatbot] %s: %s', $level, $message );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The PHP error log is the only log WordPress offers a plugin; a silent chatbot is otherwise undiagnosable.
		error_log( $line );
	}
}
