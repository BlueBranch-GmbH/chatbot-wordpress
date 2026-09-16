<?php
/**
 * The three blocks, for editors who never touch a shortcode.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks and renders them on the server.
 *
 * Server-side rendering rather than a saved block: the markup depends on the
 * settings, and a block that saved its own HTML would keep showing yesterday's
 * greeting and yesterday's colour until somebody re-opened every post.
 *
 * The blocks carry no attributes of their own. Everything is configured once
 * on the settings screen; a shortcode is there for the rare page that needs
 * something different.
 */
class Blocks {

	/**
	 * Block names, mapped to the method that renders them.
	 *
	 * @var array<string, string>
	 */
	private static $blocks = array(
		'widget' => 'render_widget',
		'search' => 'render_search',
	);

	/**
	 * Hooks the blocks into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Declares the editor script and the blocks that use it.
	 *
	 * @return void
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'bluebranch-chatbot-blocks',
			BLUEBRANCH_CHATBOT_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n', 'wp-components' ),
			VERSION,
			true
		);

		wp_set_script_translations( 'bluebranch-chatbot-blocks', 'bluebranch-chatbot', BLUEBRANCH_CHATBOT_DIR . 'languages' );

		$frontend = new Frontend();

		foreach ( self::$blocks as $name => $method ) {
			register_block_type(
				BLUEBRANCH_CHATBOT_DIR . 'blocks/' . $name,
				array(
					'render_callback' => function () use ( $frontend, $method ) {
						return $frontend->$method();
					},
				)
			);
		}
	}
}
