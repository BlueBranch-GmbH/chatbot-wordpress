<?php
/**
 * The list of everything this plugin hooks into.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components to WordPress.
 *
 * Every component registers its own hooks; this class only decides which ones
 * exist and in which context. Keeping the admin-only pieces behind is_admin()
 * means a front end request never loads the settings screens.
 */
class Plugin {

	/**
	 * Registers the plugin with WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_meta' ) );

		( new Crawl_Marker() )->register();
		( new Indexer() )->register();
		( new Cron() )->register();
		( new Rest_Controller() )->register();
		( new Frontend() )->register();
		( new Blocks() )->register();

		if ( is_admin() ) {
			( new Admin\Admin() )->register();
		}
	}

	/**
	 * Loads the bundled translations.
	 *
	 * Called on init rather than at file level: loading a text domain before
	 * WordPress is ready triggers a _doing_it_wrong notice as of WordPress 6.7.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'bluebranch-chatbot',
			false,
			dirname( plugin_basename( BLUEBRANCH_CHATBOT_FILE ) ) . '/languages'
		);
	}

	/**
	 * Declares the post meta the plugin owns.
	 *
	 * Registering it makes the field known to the REST API and therefore to
	 * the block editor, which is what lets the exclusion survive a save made
	 * from the editor sidebar rather than from the classic meta box.
	 *
	 * @return void
	 */
	public function register_meta() {
		foreach ( Options::trainable_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Indexer::META_EXCLUDE,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}
}
