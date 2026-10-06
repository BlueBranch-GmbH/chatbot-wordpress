<?php
/**
 * Content that has no page of its own.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Notes and documents added by hand on the "Additional content" screen.
 *
 * An entry is either a text written into a field -- opening hours, a price
 * note, anything a page does not say -- or a file from the media library that
 * is turned into text on this site. Either way only text goes to the API, as
 * `extra_<id>`, with no address: there is no page to link to, so the answers
 * cite nothing for it.
 */
class Extra_Content {

	/**
	 * Prefix of the external id at the API.
	 */
	const EXTERNAL_PREFIX = 'extra_';

	/**
	 * One entry.
	 *
	 * @param int $id Entry id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::content_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table, no input.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::content_table() . ' ORDER BY id DESC', ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Creates or updates an entry and trains it.
	 *
	 * @param int    $id            Entry id, 0 for a new one.
	 * @param string $kind          Either text or file.
	 * @param string $title         Title shown to the model.
	 * @param string $content       Text of a text entry.
	 * @param int    $attachment_id Media library id of a file entry.
	 * @return int|WP_Error The entry id.
	 */
	public static function save( $id, $kind, $title, $content, $attachment_id ) {
		global $wpdb;

		$kind  = 'file' === $kind ? 'file' : 'text';
		$title = sanitize_text_field( (string) $title );
		$now   = current_time( 'mysql', true );

		if ( 'file' === $kind ) {
			$attachment_id = absint( $attachment_id );
			$check         = self::check_attachment( $attachment_id );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$content = '';

			if ( '' === $title ) {
				$title = (string) get_the_title( $attachment_id );
			}
		} else {
			$attachment_id = 0;
			$content       = ( new Text_Extractor() )->normalise( sanitize_textarea_field( (string) $content ) );

			if ( '' === $content ) {
				return new WP_Error( 'bluebranch_chatbot_extra', __( 'Please enter a text.', 'bluebranch-chatbot' ) );
			}
		}

		if ( '' === $title ) {
			return new WP_Error( 'bluebranch_chatbot_extra', __( 'Please enter a title.', 'bluebranch-chatbot' ) );
		}

		$data = array(
			'title'         => $title,
			'kind'          => $kind,
			'content'       => $content,
			'attachment_id' => $attachment_id,
			'updated_at'    => $now,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		if ( $id > 0 && null !== self::find( $id ) ) {
			$wpdb->update( Schema::content_table(), $data, array( 'id' => (int) $id ) );
		} else {
			$data['created_at'] = $now;
			$data['active']     = 1;
			$data['last_error'] = '';
			$data['status']     = 'pending';
			$wpdb->insert( Schema::content_table(), $data );
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable

		if ( $id < 1 ) {
			return new WP_Error( 'bluebranch_chatbot_extra', __( 'The entry could not be saved.', 'bluebranch-chatbot' ) );
		}

		self::train( $id, true );

		return (int) $id;
	}

	/**
	 * Switches an entry on or off; off withdraws it from the knowledge base.
	 *
	 * @param int  $id     Entry id.
	 * @param bool $active New state.
	 * @return void
	 */
	public static function set_active( $id, $active ) {
		self::update( $id, array( 'active' => $active ? 1 : 0 ) );

		if ( $active ) {
			self::train( $id, true );
		} else {
			self::withdraw( $id );
		}
	}

	/**
	 * Deletes an entry here and at the API.
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		self::withdraw( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::content_table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Hands an entry's text to the API.
	 *
	 * @param int  $id    Entry id.
	 * @param bool $force Send even when the text has not changed.
	 * @return bool Whether the entry is now trained.
	 */
	public static function train( $id, $force = false ) {
		$row = self::find( $id );

		if ( null === $row || ! (int) $row['active'] ) {
			return false;
		}

		$text = self::text_of( $row );

		if ( is_wp_error( $text ) ) {
			// The text that was trained before is no longer what the entry says --
			// typically the file was deleted from the media library. Leaving it in
			// the knowledge base would keep answering from a file that is gone.
			if ( '' !== (string) $row['checksum'] ) {
				self::withdraw( $id );
			}

			// The fingerprint of the file that failed: refresh_changed() tries it
			// again only once the file is a different one. Parsing the same
			// broken or oversized PDF every day would only cost memory and time.
			self::update(
				$id,
				array(
					'status'     => 'failed',
					'last_error' => $text->get_error_message(),
					'file_hash'  => self::file_hash( $row ),
				)
			);

			return false;
		}

		$checksum = md5( $row['title'] . "\n" . $text );

		if ( ! $force && 'trained' === $row['status'] && $checksum === $row['checksum'] ) {
			return true;
		}

		$result = ( new Api_Client() )->train_content(
			array(
				'externalId' => self::EXTERNAL_PREFIX . (int) $row['id'],
				'title'      => (string) $row['title'],
				'content'    => $text,
				'language'   => self::language(),
				'type'       => 'file' === $row['kind'] ? 'document' : 'note',
				'tstamp'     => time(),
			)
		);

		$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		if ( is_wp_error( $result ) ) {
			// The text was fine, the API was not: no fingerprint, so the next
			// run tries again.
			self::update(
				$id,
				array(
					'status'     => 'failed',
					'last_error' => $result->get_error_message(),
					'chars'      => $chars,
					'file_hash'  => '',
				)
			);

			return false;
		}

		self::update(
			$id,
			array(
				'status'     => 'trained',
				'last_error' => '',
				'chars'      => $chars,
				'checksum'   => $checksum,
				'file_hash'  => self::file_hash( $row ),
				'trained_at' => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Retrains file entries whose file changed since they were trained.
	 *
	 * The media library can replace a file under the same attachment, and
	 * nothing tells this plugin about it. The daily run compares fingerprints.
	 *
	 * @return int Entries retrained.
	 */
	public static function refresh_changed() {
		if ( ! Options::has_api_key() ) {
			return 0;
		}

		$count = 0;

		foreach ( self::all() as $row ) {
			if ( ! (int) $row['active'] ) {
				continue;
			}

			$changed = 'file' === $row['kind'] && self::file_hash( $row ) !== $row['file_hash'];

			// Failed on this very file before: wait until it is replaced.
			if ( 'failed' === $row['status'] && 'file' === $row['kind'] && '' !== (string) $row['file_hash'] && ! $changed ) {
				continue;
			}

			if ( $changed || 'trained' !== $row['status'] ) {
				if ( self::train( (int) $row['id'], $changed ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Marks every entry as untrained, after the knowledge base was emptied.
	 *
	 * @return void
	 */
	public static function forget_trained() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table, no input.
		$wpdb->query( 'UPDATE ' . Schema::content_table() . " SET status = 'pending', checksum = '' WHERE status = 'trained'" );
	}

	/**
	 * Marks one entry as untrained, after it was removed elsewhere.
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	public static function mark_pending( $id ) {
		// Deleted on purpose under "Trained content": deactivate the entry too,
		// or the daily run would put it straight back.
		self::update(
			$id,
			array(
				'status'   => 'inactive',
				'checksum' => '',
				'active'   => 0,
			)
		);
	}

	/**
	 * Withdraws every entry built from an attachment that is being deleted.
	 *
	 * Hooked to delete_attachment, so the knowledge base loses the text at the
	 * same moment the media library loses the file -- not a day later.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 * @return void
	 */
	public static function on_delete_attachment( $attachment_id ) {
		foreach ( self::all() as $row ) {
			if ( 'file' !== $row['kind'] || (int) $row['attachment_id'] !== (int) $attachment_id ) {
				continue;
			}

			if ( '' !== (string) $row['checksum'] ) {
				self::withdraw( (int) $row['id'] );
			}

			self::update(
				(int) $row['id'],
				array(
					'status'     => 'failed',
					'last_error' => __( 'The file was deleted from the media library.', 'bluebranch-chatbot' ),
				)
			);
		}
	}

	/**
	 * The text of an entry, extracted afresh for files.
	 *
	 * @param array $row Entry.
	 * @return string|WP_Error
	 */
	public static function text_of( array $row ) {
		if ( 'file' === $row['kind'] ) {
			return ( new Text_Extractor() )->from_attachment( (int) $row['attachment_id'] );
		}

		return (string) $row['content'];
	}

	/**
	 * Whether an attachment is a file the extractor accepts.
	 *
	 * Checked against the file on disk, not against what the browser sent:
	 * the media picker can be told to show anything.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return true|WP_Error
	 */
	public static function check_attachment( $attachment_id ) {
		$path = $attachment_id > 0 ? get_attached_file( $attachment_id ) : false;

		if ( ! is_string( $path ) || '' === $path ) {
			return new WP_Error( 'bluebranch_chatbot_extra', __( 'Please choose a file.', 'bluebranch-chatbot' ) );
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, Text_Extractor::mime_types() ) ) {
			return new WP_Error( 'bluebranch_chatbot_extra', __( 'This file type is not supported. Allowed: TXT, MD, CSV, HTML, PDF, DOCX, ODT.', 'bluebranch-chatbot' ) );
		}

		return true;
	}

	/**
	 * Withdraws an entry from the knowledge base.
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	private static function withdraw( $id ) {
		$result = ( new Api_Client() )->delete_content( self::EXTERNAL_PREFIX . (int) $id );
		$status = is_wp_error( $result ) ? (int) $result->get_error_data( 'status' ) : 200;

		self::update(
			$id,
			array(
				'status'     => ( is_wp_error( $result ) && 404 !== $status ) ? 'failed' : 'inactive',
				'last_error' => ( is_wp_error( $result ) && 404 !== $status ) ? $result->get_error_message() : '',
				'checksum'   => '',
			)
		);
	}

	/**
	 * Fingerprint of an entry's file, or an empty string.
	 *
	 * @param array $row Entry.
	 * @return string
	 */
	private static function file_hash( array $row ) {
		if ( 'file' !== $row['kind'] ) {
			return '';
		}

		$path = get_attached_file( (int) $row['attachment_id'] );

		return is_string( $path ) && is_readable( $path ) ? (string) md5_file( $path ) : '';
	}

	/**
	 * Writes some columns of an entry.
	 *
	 * @param int   $id   Entry id.
	 * @param array $data Columns.
	 * @return void
	 */
	private static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::content_table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Two-letter language of the site.
	 *
	 * @return string
	 */
	private static function language() {
		$language = strtolower( substr( (string) get_bloginfo( 'language' ), 0, 2 ) );

		return '' !== $language ? $language : 'de';
	}
}
