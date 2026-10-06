<?php
/**
 * The plugin's own tables.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates the two tables the plugin keeps.
 *
 * The questions and the additional content are records, not settings: they
 * grow, they are searched and paged through, and some of them are large. An
 * option holding an array of them would be loaded and rewritten whole on every
 * change, so both get a table of their own.
 *
 * dbDelta() compares the definitions below with what exists and adds what is
 * missing. It is run on activation and again whenever the stored schema
 * version is behind -- an update through the plugin screen does not fire the
 * activation hook, so relying on it alone would leave updated sites without
 * the tables.
 */
class Schema {

	/**
	 * Version of the definitions below. Raise it whenever they change.
	 */
	const DB_VERSION = '2';

	/**
	 * Option holding the schema version a site is on.
	 */
	const VERSION_OPTION = 'bluebranch_chatbot_db_version';

	/**
	 * Hooks the version check into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		// On init rather than plugins_loaded: scheduling the housekeeping runs
		// the cron_schedules filter, whose labels need the translations loaded.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Table holding questions, answers and feedback.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'bluebranch_chatbot_log';
	}

	/**
	 * Table holding the additional content.
	 *
	 * @return string
	 */
	public static function content_table() {
		global $wpdb;

		return $wpdb->prefix . 'bluebranch_chatbot_content';
	}

	/**
	 * Brings the tables up to date if the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();

		// An update never runs the activation hook, so the daily housekeeping
		// that came with the tables is put on the calendar here as well.
		Cron::schedule_events();
	}

	/**
	 * Creates or updates both tables.
	 *
	 * The layout of the statements is dictated by dbDelta(): two spaces after
	 * PRIMARY KEY, one column per line, KEY rather than INDEX.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = self::log_table();
		$content = self::content_table();

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				ref char(32) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(16) NOT NULL DEFAULT '',
				language varchar(12) NOT NULL DEFAULT '',
				question text NOT NULL,
				answer mediumtext NOT NULL,
				sources text NOT NULL,
				rating varchar(4) NOT NULL DEFAULT '',
				comment text NOT NULL,
				feedback_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY ref (ref),
				KEY created_at (created_at),
				KEY rating (rating)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$content} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				kind varchar(8) NOT NULL DEFAULT 'text',
				content longtext NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				chars int(10) unsigned NOT NULL DEFAULT 0,
				checksum varchar(32) NOT NULL DEFAULT '',
				file_hash varchar(32) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'pending',
				last_error text NOT NULL,
				trained_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		// Version 2: the API key stops being autoloaded on installations that
		// stored it before Activation created the option without autoload.
		if ( function_exists( 'wp_set_option_autoload' ) && false !== get_option( Options::API_KEY, false ) ) {
			wp_set_option_autoload( Options::API_KEY, false );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Removes both tables. Only ever called from uninstall.php.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are built from the prefix, not from input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::log_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::content_table() );
		// phpcs:enable

		delete_option( self::VERSION_OPTION );
	}
}
