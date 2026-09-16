<?php
/**
 * The plugin's corner of wp-admin.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Logger;
use BlueBranch\Chatbot\Options;
use BlueBranch\Chatbot\Rest_Controller;

use const BlueBranch\Chatbot\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu, the screens and the assets they need.
 */
class Admin {

	/**
	 * Slug of the menu itself, and of the screen it opens.
	 *
	 * The top level and its first submenu entry always share a slug in
	 * WordPress, so whichever screen sits here is the one the menu opens. The
	 * overview is the sensible landing place; the settings are somewhere you
	 * go once.
	 */
	const PAGE_CONTENT = 'bluebranch-chatbot';

	/**
	 * Menu slug of the training screen.
	 */
	const PAGE_TRAINING = 'bluebranch-chatbot-training';

	/**
	 * Menu slug of the settings screen.
	 */
	const PAGE_SETTINGS = 'bluebranch-chatbot-settings';

	/**
	 * Capability every screen requires.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The screens, kept as one instance each.
	 *
	 * Not a tidiness preference: WordPress identifies a hooked callback by the
	 * object it belongs to, so handing it two instances of the same class
	 * registers two callbacks that both run.
	 *
	 * @var array<string, object>
	 */
	private $screens = array();

	/**
	 * Screen identifiers of this plugin's own pages.
	 *
	 * Taken from what add_menu_page() and add_submenu_page() return rather
	 * than assembled by hand. WordPress builds those names out of the menu
	 * title, so a rewritten title would quietly stop the stylesheets loading.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Screen identifier of the settings page, which loads a little more.
	 *
	 * @var string
	 */
	private $settings_hook = '';

	/**
	 * Builds the screens.
	 */
	public function __construct() {
		$this->screens = array(
			'settings' => new Settings_Page(),
			'content'  => new Trained_Content_Page(),
			'training' => new Training_Page(),
		);
	}

	/**
	 * Hooks the admin into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BLUEBRANCH_CHATBOT_FILE ), array( $this, 'add_action_link' ) );

		$this->screens['settings']->register();
		( new Post_Meta_Box() )->register();
	}

	/**
	 * Adds the menu and its three screens.
	 *
	 * The order is the order somebody needs them in: what the knowledge base
	 * holds, how to add to it, and last the settings.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hooks[] = add_menu_page(
			__( 'BlueBranch Chatbot', 'bluebranch-chatbot' ),
			__( 'BlueBranch Chatbot', 'bluebranch-chatbot' ),
			self::CAPABILITY,
			self::PAGE_CONTENT,
			array( $this->screens['content'], 'render' ),
			'dashicons-format-chat',
			58
		);

		/*
		 * The first entry repeats the top level under its own name. Adding it
		 * explicitly is what stops WordPress inserting a generic "BlueBranch
		 * Chatbot" line above the others.
		 *
		 * It deliberately carries no callback. Sharing a slug with the parent
		 * means sharing its hook, where the callback already hangs; passing it
		 * again would register a second one and draw the screen twice.
		 */
		add_submenu_page(
			self::PAGE_CONTENT,
			__( 'Trained content', 'bluebranch-chatbot' ),
			__( 'Trained content', 'bluebranch-chatbot' ),
			self::CAPABILITY,
			self::PAGE_CONTENT
		);

		$this->hooks[] = add_submenu_page(
			self::PAGE_CONTENT,
			__( 'Train content', 'bluebranch-chatbot' ),
			__( 'Train content', 'bluebranch-chatbot' ),
			self::CAPABILITY,
			self::PAGE_TRAINING,
			array( $this->screens['training'], 'render' )
		);

		$this->settings_hook = add_submenu_page(
			self::PAGE_CONTENT,
			__( 'Settings', 'bluebranch-chatbot' ),
			__( 'Settings', 'bluebranch-chatbot' ),
			self::CAPABILITY,
			self::PAGE_SETTINGS,
			array( $this->screens['settings'], 'render' )
		);

		$this->hooks[] = $this->settings_hook;
	}

	/**
	 * Puts a settings link next to Deactivate on the plugins screen.
	 *
	 * @param string[] $links Links shown for this plugin.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
			esc_html__( 'Settings', 'bluebranch-chatbot' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Loads the styles and scripts, but only on this plugin's own screens.
	 *
	 * @param string $hook_suffix Screen identifier WordPress hands over.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'bluebranch-chatbot-admin',
			BLUEBRANCH_CHATBOT_URL . 'assets/css/admin.css',
			array(),
			VERSION
		);

		// The answer preview on the trained content screen renders exactly what
		// a visitor sees, using the same stylesheet and the same scripts.
		wp_enqueue_style( 'bluebranch-chatbot' );

		// Only the settings screen has a colour field and a media button. The
		// script checks for both before touching them, so they are enqueued
		// here rather than declared as dependencies -- a dependency would drag
		// the colour picker onto every screen of the plugin.
		if ( $hook_suffix === $this->settings_hook ) {
			wp_enqueue_media();
			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_style( 'wp-color-picker' );
		}

		wp_enqueue_script( 'bluebranch-chatbot-markdown' );
		wp_enqueue_script( 'bluebranch-chatbot-client' );

		wp_enqueue_script(
			'bluebranch-chatbot-admin',
			BLUEBRANCH_CHATBOT_URL . 'assets/js/admin.js',
			array( 'bluebranch-chatbot-markdown', 'bluebranch-chatbot-client' ),
			VERSION,
			true
		);

		wp_localize_script(
			'bluebranch-chatbot-admin',
			'bluebranchChatbotAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'confirmDelete'     => __( 'Remove this entry from the knowledge base?', 'bluebranch-chatbot' ),
					'confirmDeleteAll'  => __( 'Remove everything from the knowledge base? This cannot be undone.', 'bluebranch-chatbot' ),
					'deleting'          => __( 'Removing …', 'bluebranch-chatbot' ),
					'deleteLabel'       => __( 'Delete', 'bluebranch-chatbot' ),
					'failed'            => __( 'That did not work.', 'bluebranch-chatbot' ),
					'empty'             => __( 'No trained content found.', 'bluebranch-chatbot' ),
					'entries'           => __( 'entries', 'bluebranch-chatbot' ),
					/* translators: 1: number shown, 2: number in total. */
					'filtered'          => __( '%1$d of %2$d entries', 'bluebranch-chatbot' ),
					/* translators: 1: posts done, 2: posts in total. */
					'progress'          => __( '%1$d of %2$d posts trained', 'bluebranch-chatbot' ),
					'trainingDone'      => __( 'Run finished.', 'bluebranch-chatbot' ),
					'nothingToTrain'    => __( 'There is nothing to train.', 'bluebranch-chatbot' ),
					'preparing'         => __( 'Working out what to do …', 'bluebranch-chatbot' ),
					'outcome_trained'   => __( 'trained', 'bluebranch-chatbot' ),
					'outcome_unchanged' => __( 'unchanged', 'bluebranch-chatbot' ),
					'outcome_removed'   => __( 'withdrawn', 'bluebranch-chatbot' ),
					'outcome_skipped'   => __( 'skipped', 'bluebranch-chatbot' ),
					'outcome_failed'    => __( 'failed', 'bluebranch-chatbot' ),
					'yes'               => __( 'yes', 'bluebranch-chatbot' ),
					'no'                => __( 'no', 'bluebranch-chatbot' ),
					/* translators: 1: percentage dropped, 2: yes or no, 3: number of repeated blocks. */
					'previewMeta'       => __( '%1$d%% of the page was dropped as furniture. Marked by the site itself: %2$s. Repeated blocks known: %3$d.', 'bluebranch-chatbot' ),
				),
			)
		);
	}

	/**
	 * Shows the last API error, and only where it can be acted on.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! str_contains( (string) $screen->id, 'bluebranch-chatbot' ) ) {
			return;
		}

		$error = Logger::last_error();

		if ( null === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s<br><em>%s</em></p></div>',
			esc_html__( 'BlueBranch Chatbot:', 'bluebranch-chatbot' ),
			esc_html( $error['message'] ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference, e.g. "5 minutes". */
					__( '%s ago', 'bluebranch-chatbot' ),
					human_time_diff( (int) $error['time'] )
				)
			)
		);
	}

	/**
	 * A short line saying whether a key is stored, used on several screens.
	 *
	 * @return void
	 */
	public static function render_missing_key_notice() {
		if ( Options::has_api_key() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: %s: link to the settings screen. */
					__( 'No API key has been stored yet. Nothing is trained and no answers are given until one is set on the <a href="%s">settings screen</a>.', 'bluebranch-chatbot' ),
					esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}
}
