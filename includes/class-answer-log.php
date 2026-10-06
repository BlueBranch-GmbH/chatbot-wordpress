<?php
/**
 * Questions, answers and the feedback given on them.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps what visitors asked and what they were told, for later evaluation.
 *
 * Nothing that identifies the visitor is stored: no address, no user agent,
 * no session or user id. What a visitor types may still name a person, which
 * is why e-mail addresses, phone numbers and IBANs are masked in the question
 * and the comment before they reach the table.
 *
 * Every answer gets a random reference. It is the only handle the browser is
 * given for its feedback -- a running id would let anybody rate every answer
 * in the table.
 */
class Answer_Log {

	/**
	 * Prefix of the transient holding an answer while saving is switched off.
	 */
	const TRANSIENT_PREFIX = 'bbchat_ans_';

	/**
	 * How long an unsaved answer can still receive feedback.
	 */
	const TRANSIENT_LIFETIME = 2 * HOUR_IN_SECONDS;

	/**
	 * Longest comment accepted, in characters.
	 */
	const MAX_COMMENT = 1000;

	/**
	 * Stored in place of the page text a summary request carries.
	 */
	const SUMMARY_QUESTION = '[Seite zusammenfassen]';

	/**
	 * Where a question may come from.
	 *
	 * @var string[]
	 */
	public static $sources = array( 'widget', 'ask', 'search' );

	/**
	 * Whether questions and answers are saved as a matter of course.
	 *
	 * @return bool
	 */
	public static function logging_enabled() {
		return (bool) Options::get( 'log_enabled' );
	}

	/**
	 * Whether visitors are asked for feedback.
	 *
	 * @return bool
	 */
	public static function feedback_enabled() {
		return (bool) Options::get( 'feedback_enabled' );
	}

	/**
	 * Takes note of a finished answer.
	 *
	 * With saving switched on the row is written at once. With it off but
	 * feedback on, the answer waits in a transient: only if feedback arrives
	 * does it become a row.
	 *
	 * @param array $entry question, answer, sources, source, post_id, language, summarize.
	 * @return string|null The reference, or null when nothing was kept.
	 */
	public static function record( array $entry ) {
		$answer = isset( $entry['answer'] ) ? trim( (string) $entry['answer'] ) : '';

		if ( '' === $answer ) {
			return null;
		}

		if ( ! self::logging_enabled() && ! self::feedback_enabled() ) {
			return null;
		}

		$row = self::prepare_row( $entry );

		if ( self::logging_enabled() ) {
			return self::insert( $row ) ? $row['ref'] : null;
		}

		set_transient( self::TRANSIENT_PREFIX . $row['ref'], $row, self::TRANSIENT_LIFETIME );

		return $row['ref'];
	}

	/**
	 * Stores the feedback for one answer.
	 *
	 * @param string $ref     Reference handed out with the answer.
	 * @param string $rating  Either up or down.
	 * @param string $comment Free text, only kept with a thumbs down.
	 * @return true|WP_Error
	 */
	public static function feedback( $ref, $rating, $comment = '' ) {
		global $wpdb;

		if ( ! self::is_ref( $ref ) ) {
			return new WP_Error( 'bluebranch_chatbot_feedback', __( 'Unknown answer.', 'bluebranch-chatbot' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $rating, array( 'up', 'down' ), true ) ) {
			return new WP_Error( 'bluebranch_chatbot_feedback', __( 'Invalid rating.', 'bluebranch-chatbot' ), array( 'status' => 400 ) );
		}

		$comment = 'down' === $rating ? self::clean_comment( $comment ) : '';
		$table   = Schema::log_table();
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$updated = $wpdb->update(
			$table,
			array(
				'rating'      => $rating,
				'comment'     => $comment,
				'feedback_at' => $now,
			),
			array( 'ref' => $ref )
		);

		if ( $updated ) {
			return true;
		}

		// update() reports 0 both for "no such row" and for "nothing changed".
		if ( self::exists( $ref ) ) {
			return true;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $ref );

		if ( ! is_array( $pending ) ) {
			return new WP_Error( 'bluebranch_chatbot_feedback', __( 'This answer can no longer be rated.', 'bluebranch-chatbot' ), array( 'status' => 404 ) );
		}

		$pending['rating']      = $rating;
		$pending['comment']     = $comment;
		$pending['feedback_at'] = $now;

		if ( ! self::insert( $pending ) ) {
			return new WP_Error( 'bluebranch_chatbot_feedback', __( 'The feedback could not be saved.', 'bluebranch-chatbot' ), array( 'status' => 500 ) );
		}

		delete_transient( self::TRANSIENT_PREFIX . $ref );

		return true;
	}

	/**
	 * Deletes rows older than the configured retention.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_expired() {
		global $wpdb;

		$days = (int) Options::get( 'log_retention_days' );

		if ( $days < 1 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table, name from the prefix.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::log_table() . ' WHERE created_at < %s', $cutoff ) );
	}

	/**
	 * Deletes the given rows.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows removed.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; one placeholder per id.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::log_table() . " WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Builds the WHERE clause shared by the list and the export.
	 *
	 * @param array $filters search and rating.
	 * @return string Prepared SQL fragment, starting with WHERE.
	 */
	public static function where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		$rating = isset( $filters['rating'] ) ? (string) $filters['rating'] : '';

		if ( in_array( $rating, array( 'up', 'down' ), true ) ) {
			$where[] = $wpdb->prepare( 'rating = %s', $rating );
		} elseif ( 'none' === $rating ) {
			$where[] = "rating = ''";
		}

		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(question LIKE %s OR answer LIKE %s OR comment LIKE %s)', $like, $like, $like );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * Turns an entry into a row, masking what the visitor typed.
	 *
	 * @param array $entry Raw entry.
	 * @return array
	 */
	private static function prepare_row( array $entry ) {
		$question = ! empty( $entry['summarize'] ) ? self::SUMMARY_QUESTION : (string) ( isset( $entry['question'] ) ? $entry['question'] : '' );
		$source   = isset( $entry['source'] ) ? (string) $entry['source'] : '';
		$sources  = array();

		if ( isset( $entry['sources'] ) && is_array( $entry['sources'] ) ) {
			foreach ( array_slice( $entry['sources'], 0, 10 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$sources[] = array(
					'title' => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
					'url'   => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
				);
			}
		}

		return array(
			'created_at'  => current_time( 'mysql', true ),
			'ref'         => bin2hex( random_bytes( 16 ) ),
			'post_id'     => isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0,
			'source'      => in_array( $source, self::$sources, true ) ? $source : '',
			'language'    => substr( sanitize_key( isset( $entry['language'] ) ? (string) $entry['language'] : '' ), 0, 12 ),
			'question'    => self::mask( $question ),
			'answer'      => (string) $entry['answer'],
			'sources'     => (string) wp_json_encode( $sources ),
			'rating'      => '',
			'comment'     => '',
			'feedback_at' => null,
		);
	}

	/**
	 * Writes one row.
	 *
	 * @param array $row Column values.
	 * @return bool
	 */
	private static function insert( array $row ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		return false !== $wpdb->insert( Schema::log_table(), $row );
	}

	/**
	 * Whether a row with this reference exists.
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	private static function exists( $ref ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::log_table() . ' WHERE ref = %s', $ref ) );
	}

	/**
	 * Whether a value looks like a reference this class hands out.
	 *
	 * @param mixed $ref Value to check.
	 * @return bool
	 */
	public static function is_ref( $ref ) {
		return is_string( $ref ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $ref );
	}

	/**
	 * Cleans a visitor comment: no markup, no control characters, bounded.
	 *
	 * @param mixed $comment Raw comment.
	 * @return string
	 */
	public static function clean_comment( $comment ) {
		$comment = wp_strip_all_tags( (string) $comment );
		$comment = str_replace( array( "\r\n", "\r" ), "\n", $comment );
		$comment = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', '', $comment );
		$comment = (string) preg_replace( "/\n{3,}/", "\n\n", $comment );

		if ( function_exists( 'mb_substr' ) ) {
			$comment = mb_substr( $comment, 0, self::MAX_COMMENT, 'UTF-8' );
		} else {
			$comment = substr( $comment, 0, self::MAX_COMMENT );
		}

		return self::mask( trim( $comment ) );
	}

	/**
	 * Masks e-mail addresses, phone numbers and IBANs.
	 *
	 * Deliberately conservative about phone numbers: they have to start with
	 * +, 00 or 0 and carry at least seven digits, and a full stop does not
	 * count as a separator -- otherwise every date would be masked as well.
	 *
	 * @param string $text Text typed by a visitor.
	 * @return string
	 */
	public static function mask( $text ) {
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[E-Mail]', (string) $text );
		$text = (string) preg_replace( '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}\b/', '[IBAN]', $text );

		return (string) preg_replace_callback(
			'/(?<![\w])(?:\+|0)[\d \/()\-]{6,}\d/',
			static function ( $matches ) {
				return preg_match_all( '/\d/', $matches[0] ) >= 7 ? '[Telefon]' : $matches[0];
			},
			$text
		);
	}
}
