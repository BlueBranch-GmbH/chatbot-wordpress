<?php
/**
 * Every stored setting, in one place.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's two options.
 *
 * The API key lives in an option of its own and not in the settings array,
 * for two reasons: it must not be autoloaded into every single request, and
 * deleting it has to be possible without touching anything else.
 */
class Options {

	/**
	 * Option holding every setting except the API key.
	 */
	const SETTINGS = 'bluebranch_chatbot_settings';

	/**
	 * Option holding the API key.
	 */
	const API_KEY = 'bluebranch_chatbot_api_key';

	/**
	 * Option holding the timestamp of the last successful clean-up run.
	 */
	const PURGE_LAST_RUN = 'bluebranch_chatbot_purge_last_run';

	/**
	 * Option holding the last API error, shown as an admin notice.
	 */
	const LAST_ERROR = 'bluebranch_chatbot_last_error';

	/**
	 * What the settings look like before anybody has touched them.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'bot_name'            => '',
			'accent_color'        => '',
			'icon_attachment_id'  => 0,
			'greeting'            => '',
			'suggestions'         => array(),
			'typed_questions'     => array(),
			'ask_button_label'    => '',
			'ask_placeholder'     => '',
			'ask_stop_label'      => '',
			'hide_summarize'      => false,
			'hide_disclaimer'     => false,
			'widget_position'     => 'bottom-right',
			'widget_auto_display' => false,
			'unstyled'            => false,
			'post_types'          => array( 'post', 'page' ),
			'auto_train'          => true,
			'training_source'     => 'crawl',
			'sitemap_url'         => '',
			'strip_boilerplate'   => true,
			'reconcile_sitemap'   => true,
			'purge_enabled'       => true,
			'purge_interval'      => 'daily',
			'search_integration'  => false,
			'debug_logging'       => false,
		);
	}

	/**
	 * The clean-up intervals offered in the settings, mapped to cron schedules.
	 *
	 * @return array<string, string>
	 */
	public static function purge_intervals() {
		return array(
			'hourly'    => __( 'Hourly', 'bluebranch-chatbot' ),
			'six_hours' => __( 'Every 6 hours', 'bluebranch-chatbot' ),
			'daily'     => __( 'Daily', 'bluebranch-chatbot' ),
			'weekly'    => __( 'Weekly', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * Where the content handed to the API comes from.
	 *
	 * @return array<string, string>
	 */
	public static function training_sources() {
		return array(
			'crawl'  => __( 'Crawl the page (recommended)', 'bluebranch-chatbot' ),
			'render' => __( 'Render the post content', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * Whether pages are fetched over HTTP rather than rendered internally.
	 *
	 * @return bool
	 */
	public static function crawls() {
		return 'render' !== self::get( 'training_source' );
	}

	/**
	 * Where the chat button may sit.
	 *
	 * @return array<string, string>
	 */
	public static function widget_positions() {
		return array(
			'bottom-right' => __( 'Bottom right', 'bluebranch-chatbot' ),
			'bottom-left'  => __( 'Bottom left', 'bluebranch-chatbot' ),
			'top-right'    => __( 'Top right', 'bluebranch-chatbot' ),
			'top-left'     => __( 'Top left', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::SETTINGS, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default_value Returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default_value = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Writes one setting without disturbing the others.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;

		update_option( self::SETTINGS, $all );
	}

	/**
	 * The API key, or an empty string.
	 *
	 * @return string
	 */
	public static function api_key() {
		return (string) get_option( self::API_KEY, '' );
	}

	/**
	 * Whether a key is stored at all.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== trim( self::api_key() );
	}

	/**
	 * The post types the plugin is allowed to train, narrowed to types that
	 * actually exist and are publicly queryable.
	 *
	 * A type may vanish when its plugin is deactivated; keeping the stored
	 * name would make every eligibility check pass for content nobody can
	 * reach any more.
	 *
	 * @return string[]
	 */
	public static function trainable_post_types() {
		$configured = self::get( 'post_types' );
		$configured = is_array( $configured ) ? $configured : array();
		$available  = get_post_types( array( 'public' => true ), 'names' );

		$types = array_values( array_intersect( $configured, $available ) );

		/**
		 * Filters the post types handed to the knowledge base.
		 *
		 * @param string[] $types Post type names.
		 */
		return (array) apply_filters( 'bluebranch_chatbot_post_types', $types );
	}
}
