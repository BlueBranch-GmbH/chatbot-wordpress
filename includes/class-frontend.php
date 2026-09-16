<?php
/**
 * Everything the visitor gets to see.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes, templates and the scripts behind them.
 *
 * Contao offers the three pieces as front end modules that an editor drops
 * into a layout. WordPress has no such place, so each one is a shortcode and
 * a block, and the chat button can additionally be switched on site-wide.
 */
class Frontend {

	/**
	 * The shortcodes this plugin owns.
	 *
	 * @var string[]
	 */
	private static $shortcodes = array(
		'bluebranch_chatbot_widget',
		'bluebranch_chatbot_search',
		'bluebranch_chatbot_exclude',
	);

	/**
	 * Counts rendered instances so each one gets its own id.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Hooks the front end into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// Registered in the admin too: the answer preview on the trained
		// content screen runs the same stylesheet and the same scripts a
		// visitor gets, which is the only way it can prove anything.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		add_shortcode( 'bluebranch_chatbot_widget', array( $this, 'render_widget' ) );
		add_shortcode( 'bluebranch_chatbot_search', array( $this, 'render_search' ) );
		add_shortcode( 'bluebranch_chatbot_exclude', array( $this, 'render_exclude' ) );

		add_action( 'wp_footer', array( $this, 'maybe_render_auto_widget' ) );

		/*
		 * Two ways in, because the two kinds of theme put their results on the
		 * page by completely different means.
		 *
		 * A block theme renders its template into a string, and does so once
		 * before the document is printed so it can work out which block styles
		 * are needed. Anything echoed from loop_start during that pass lands
		 * ahead of the doctype, outside the page altogether. So there the
		 * answer is returned into the query block instead of echoed.
		 */
		add_filter( 'render_block', array( $this, 'prepend_to_query_block' ), 10, 2 );
		add_action( 'loop_start', array( $this, 'maybe_render_search_answer' ) );
	}

	/**
	 * Declares the styles and scripts without putting them on the page yet.
	 *
	 * Registering is not enqueuing: nothing is loaded until something actually
	 * renders. A site using the chat on one page only should not be made to
	 * carry the scripts on every other one.
	 *
	 * @return void
	 */
	public function register_assets() {
		$url = BLUEBRANCH_CHATBOT_URL . 'assets/';

		wp_register_style( 'bluebranch-chatbot', $url . 'css/chatbot.css', array(), VERSION );

		wp_register_script( 'bluebranch-chatbot-markdown', $url . 'js/markdown.js', array(), VERSION, true );
		wp_register_script( 'bluebranch-chatbot-typed-placeholder', $url . 'js/typed-placeholder.js', array(), VERSION, true );
		wp_register_script( 'bluebranch-chatbot-client', $url . 'js/client.js', array(), VERSION, true );

		wp_register_script(
			'bluebranch-chatbot-widget',
			$url . 'js/widget.js',
			array( 'bluebranch-chatbot-markdown', 'bluebranch-chatbot-client' ),
			VERSION,
			true
		);

		wp_register_script(
			'bluebranch-chatbot-answer',
			$url . 'js/answer.js',
			array( 'bluebranch-chatbot-markdown', 'bluebranch-chatbot-client', 'bluebranch-chatbot-typed-placeholder' ),
			VERSION,
			true
		);

		$settings = array(
			'restUrl' => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE ) ),
			'strings' => self::strings(),
		);

		wp_localize_script( 'bluebranch-chatbot-client', 'bluebranchChatbotSettings', $settings );
	}

	/**
	 * The texts the scripts show.
	 *
	 * They are handed over from PHP rather than written into the scripts so
	 * they go through the site's translations like everything else.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'greeting'                => __( 'How can I help you today?', 'bluebranch-chatbot' ),
			'summarize'               => __( 'Summarise this page', 'bluebranch-chatbot' ),
			'summarizePrompt'         => __( 'Summarise the following page content briefly and precisely. Do not use any other sources or pages:', 'bluebranch-chatbot' ),
			'summarizeFallbackPrompt' => __( 'Please summarise the content of this page briefly.', 'bluebranch-chatbot' ),
			'noAnswer'                => __( 'Sorry, no answer could be generated.', 'bluebranch-chatbot' ),
			'requestError'            => __( 'Something went wrong with the request.', 'bluebranch-chatbot' ),
			'source'                  => __( 'Source', 'bluebranch-chatbot' ),
			'sources'                 => __( 'Sources:', 'bluebranch-chatbot' ),
			'newTab'                  => __( 'opens in a new tab', 'bluebranch-chatbot' ),
			'stop'                    => __( 'Stop the answer', 'bluebranch-chatbot' ),
			'stopped'                 => __( 'Answer stopped.', 'bluebranch-chatbot' ),
			'generating'              => __( 'Generating the answer …', 'bluebranch-chatbot' ),
			'generated'               => __( 'Answer generated', 'bluebranch-chatbot' ),
			/* translators: %s: elapsed time, for example "2:30 seconds". */
			'elapsed'                 => __( '%s seconds', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * The accent colour as inline custom properties, or nothing.
	 *
	 * The chat button set these on its own container, which meant the ask and
	 * search modules never saw them: a sibling is not an ancestor, so nothing
	 * inherited. The colour read as a widget setting while being presented as
	 * a general one.
	 *
	 * @param string $override Shortcode attribute, if the caller has one.
	 * @return array{0: string, 1: string, 2: string} Colour, contrast, style attribute.
	 */
	private function accent( $override = '' ) {
		$color = $this->normalise_color( '' !== $override ? $override : (string) Options::get( 'accent_color' ) );

		if ( '' === $color ) {
			return array( '', '', '' );
		}

		$contrast = $this->contrast_color( $color );

		return array(
			$color,
			$contrast,
			'--chatbot-widget-accent: ' . $color . '; --chatbot-widget-accent-contrast: ' . $contrast . ';',
		);
	}

	/**
	 * Renders the chat button.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_widget( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'position'        => '',
				'color'           => '',
				'name'            => '',
				'greeting'        => '',
				'suggestions'     => '',
				'hide_summarize'  => '',
				'hide_disclaimer' => '',
				'unstyled'        => '',
			),
			$atts,
			'bluebranch_chatbot_widget'
		);

		if ( ! Options::has_api_key() ) {
			return $this->missing_key_notice();
		}

		$settings    = Options::all();
		$positions   = Options::widget_positions();
		$position    = '' !== $atts['position'] ? $atts['position'] : $settings['widget_position'];
		$position    = isset( $positions[ $position ] ) ? $position : 'bottom-right';
		$accent      = $this->accent( $atts['color'] );
		$suggestions = '' !== $atts['suggestions']
			? array_values( array_filter( array_map( 'trim', explode( '|', $atts['suggestions'] ) ) ) )
			: (array) $settings['suggestions'];

		$bot_name = '' !== $atts['name'] ? $atts['name'] : $settings['bot_name'];
		$greeting = '' !== $atts['greeting'] ? $atts['greeting'] : $settings['greeting'];

		$this->maybe_enqueue_style( $atts['unstyled'] );

		wp_enqueue_script( 'bluebranch-chatbot-widget' );

		++self::$instance;

		return $this->render_template(
			'widget.php',
			array(
				'id'              => 'bluebranch-chatbot-widget-' . self::$instance,
				'position'        => $position,
				'color'           => $accent[0],
				'color_contrast'  => $accent[1],
				'icon_url'        => $this->icon_url( (int) $settings['icon_attachment_id'] ),
				'bot_name'        => '' !== $bot_name ? $bot_name : __( 'Chat', 'bluebranch-chatbot' ),
				'greeting'        => '' !== $greeting ? $greeting : __( 'How can I help you today?', 'bluebranch-chatbot' ),
				'suggestions'     => $suggestions,
				'show_summarize'  => ! ( '' !== $atts['hide_summarize'] ? $this->to_bool( $atts['hide_summarize'] ) : (bool) $settings['hide_summarize'] ),
				'show_disclaimer' => ! ( '' !== $atts['hide_disclaimer'] ? $this->to_bool( $atts['hide_disclaimer'] ) : (bool) $settings['hide_disclaimer'] ),
			)
		);
	}

	/**
	 * Renders the search answer, with or without a field to ask from.
	 *
	 * This was two modules until it was one. The ask field and the search
	 * answer sent the same request to the same route and rendered the result
	 * the same way; all that differed was where the question came from -- a
	 * field of its own, or the query string. Keeping them apart meant two
	 * blocks, two shortcodes and two templates for one behaviour, and an
	 * editor having to know which of two near-identical things to reach for.
	 *
	 * So the question can now come from either. Placed on a page, it brings
	 * its own field. Injected above a set of search results, the theme's field
	 * is already there and it uses the term from the URL instead.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_search( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'param'       => 's',
				'questions'   => '',
				'suggestions' => '',
				'placeholder' => '',
				'button'      => '',
				'stop'        => '',
				'form'        => 'yes',
				'autostart'   => 'yes',
			),
			$atts,
			'bluebranch_chatbot_search'
		);

		if ( ! Options::has_api_key() ) {
			return $this->missing_key_notice();
		}

		$param = sanitize_key( $atts['param'] );
		$param = '' !== $param ? $param : 's';
		$query = '';

		if ( $this->to_bool( $atts['autostart'] ) ) {
			// Reading the search term straight from the query string, which is
			// what a search result page is: there is no form submission to
			// verify here, and the value is only ever handed on as a question.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';

			if ( 's' === $param && '' === $query ) {
				$query = get_search_query();
			}
		}

		$accent = $this->accent();

		$this->maybe_enqueue_style();
		wp_enqueue_script( 'bluebranch-chatbot-answer' );

		++self::$instance;

		return $this->render_template(
			'search.php',
			array(
				'id'          => 'bluebranch-chatbot-search-' . self::$instance,
				'query'       => $query,
				'show_form'   => $this->to_bool( $atts['form'] ),
				'questions'   => $this->list_attribute( $atts['questions'], (array) Options::get( 'typed_questions' ) ),
				'suggestions' => $this->list_attribute( $atts['suggestions'], (array) Options::get( 'suggestions' ) ),
				'placeholder' => $this->wording( $atts['placeholder'], 'ask_placeholder', __( 'Ask your question …', 'bluebranch-chatbot' ) ),
				'button'      => $this->wording( $atts['button'], 'ask_button_label', __( 'Ask', 'bluebranch-chatbot' ) ),
				'stop'        => $this->wording( $atts['stop'], 'ask_stop_label', __( 'Stop', 'bluebranch-chatbot' ) ),
				'style'       => $accent[2],
			)
		);
	}

	/**
	 * Renders the field used to try the chatbot from wp-admin.
	 *
	 * Deliberately the very same template a visitor gets. A test against a
	 * second implementation would prove only that the second one works.
	 *
	 * @return string
	 */
	public function render_test_field() {
		return $this->render_search(
			array(
				'form'      => 'yes',
				'autostart' => 'no',
			)
		);
	}

	/**
	 * Renders a region that is kept out of the knowledge base.
	 *
	 * The content stays on the page exactly as it was written; it is only the
	 * extraction that skips it. A disclaimer, a cookie notice or a list of
	 * opening hours repeated in every footer would otherwise be trained over
	 * and over and start turning up as the answer to unrelated questions.
	 *
	 * @param array|string $atts    Shortcode attributes, unused.
	 * @param string|null  $content Enclosed content.
	 * @return string
	 */
	public function render_exclude( $atts = array(), $content = null ) {
		return do_shortcode( (string) $content );
	}

	/**
	 * Puts the chat button on every page when the setting says so.
	 *
	 * @return void
	 */
	public function maybe_render_auto_widget() {
		if ( ! Options::get( 'widget_auto_display' ) || ! Options::has_api_key() ) {
			return;
		}

		/**
		 * Filters whether the site-wide chat button appears on this request.
		 *
		 * @param bool $show Whether to render it.
		 */
		if ( ! apply_filters( 'bluebranch_chatbot_show_auto_widget', true ) ) {
			return;
		}

		// Escaping happens in the template; the markup is assembled there.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_widget();
	}

	/**
	 * Puts the AI answer above the results of a classic theme's search loop.
	 *
	 * Skipped on block themes: there the answer goes in through
	 * prepend_to_query_block(), and echoing it here as well would both
	 * duplicate it and print it outside the document.
	 *
	 * @param \WP_Query $query The query that is starting.
	 * @return void
	 */
	public function maybe_render_search_answer( $query ) {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return;
		}

		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		// Escaping happens in the template; the markup is assembled there.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->search_answer_once();
	}

	/**
	 * Puts the AI answer above the results of a block theme's query block.
	 *
	 * Only the block that inherits the main query is touched. A search
	 * template is free to hold other query blocks -- a "related" or "popular"
	 * list -- and an answer about the search term has no business sitting on
	 * top of those.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public function prepend_to_query_block( $block_content, $block ) {
		if ( ! isset( $block['blockName'] ) || 'core/query' !== $block['blockName'] ) {
			return $block_content;
		}

		if ( empty( $block['attrs']['query']['inherit'] ) || ! is_search() ) {
			return $block_content;
		}

		return $this->search_answer_once() . $block_content;
	}

	/**
	 * The search answer, and only the first time it is asked for.
	 *
	 * @return string
	 */
	private function search_answer_once() {
		static $rendered = false;

		if ( $rendered || is_admin() || ! Options::get( 'search_integration' ) || ! Options::has_api_key() ) {
			return '';
		}

		$rendered = true;

		// No field of its own here: the theme's search form is already on the
		// page, a second one right under it would only be confusing.
		return $this->render_search( array( 'form' => 'no' ) );
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- $args is the template contract; the included file reads it.
	/**
	 * Loads the stylesheet, unless it has been switched off.
	 *
	 * Asked by all three modules, not just the chat button. A setting called
	 * "do not load the stylesheet" that still loaded it for two of the three
	 * is not a setting, it is a trap.
	 *
	 * @param string $override Shortcode attribute, if the caller has one.
	 * @return void
	 */
	private function maybe_enqueue_style( $override = '' ) {
		$unstyled = '' !== $override ? $this->to_bool( $override ) : (bool) Options::get( 'unstyled' );

		if ( $unstyled ) {
			return;
		}

		wp_enqueue_style( 'bluebranch-chatbot' );
	}

	/**
	 * Loads a template, letting the theme override it.
	 *
	 * @param string $name Template file name.
	 * @param array  $args Values the template may use; read by the included file.
	 * @return string
	 */
	private function render_template( $name, array $args ) {
		$override = locate_template( array( 'bluebranch-chatbot/' . $name ) );
		$file     = '' !== $override ? $override : BLUEBRANCH_CHATBOT_DIR . 'templates/' . $name;

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();

		// The template reads $args from this scope.
		include $file;

		return (string) ob_get_clean();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * The note shown where a module would be, while no key is stored.
	 *
	 * Only administrators see it: a visitor can do nothing about a missing key,
	 * and a red box on a live site helps nobody.
	 *
	 * @return string
	 */
	private function missing_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<p class="bluebranch-chatbot-notice">' . esc_html__(
			'BlueBranch Chatbot: no API key has been stored yet. Only administrators see this note.',
			'bluebranch-chatbot'
		) . '</p>';
	}

	/**
	 * Picks the wording: shortcode first, then the setting, then the default.
	 *
	 * The default is translated, so a site that never touches these fields
	 * still speaks the language it is set to rather than English.
	 *
	 * @param string $override        Shortcode attribute, if the caller has one.
	 * @param string $key             Setting holding a site-wide override.
	 * @param string $default_wording What to say when neither is set.
	 * @return string
	 */
	private function wording( $override, $key, $default_wording ) {
		$override = trim( (string) $override );

		if ( '' !== $override ) {
			return $override;
		}

		$stored = trim( (string) Options::get( $key ) );

		return '' !== $stored ? $stored : $default_wording;
	}

	/**
	 * Reads a pipe-separated shortcode attribute, or falls back to a setting.
	 *
	 * @param string $value    Attribute as written in the shortcode.
	 * @param array  $fallback What the settings hold.
	 * @return string[]
	 */
	private function list_attribute( $value, array $fallback ) {
		if ( '' === $value ) {
			return array_values( array_filter( array_map( 'trim', $fallback ) ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( '|', $value ) ) ) );
	}

	/**
	 * Reduces a colour to six hex digits, or nothing.
	 *
	 * @param string $value Colour as written in the settings.
	 * @return string Hex colour including the hash, or an empty string.
	 */
	private function normalise_color( $value ) {
		$value = sanitize_hex_color( '#' . ltrim( trim( (string) $value ), '#' ) );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Picks black or white text for a freely chosen accent colour.
	 *
	 * @param string $hex Hex colour including the hash.
	 * @return string
	 */
	private function contrast_color( $hex ) {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return '#ffffff';
		}

		$red   = hexdec( substr( $hex, 0, 2 ) );
		$green = hexdec( substr( $hex, 2, 2 ) );
		$blue  = hexdec( substr( $hex, 4, 2 ) );

		// Perceived brightness (YIQ), see https://24ways.org/2010/calculating-color-contrast/.
		$brightness = ( ( $red * 299 ) + ( $green * 587 ) + ( $blue * 114 ) ) / 1000;

		return $brightness > 150 ? '#1a1a1a' : '#ffffff';
	}

	/**
	 * The URL of the icon chosen in the settings.
	 *
	 * @param int $attachment_id Media library id.
	 * @return string
	 */
	private function icon_url( $attachment_id ) {
		if ( $attachment_id < 1 ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Reads a shortcode attribute written as yes, true or 1.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Removes the regions marked as excluded, content and all.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	public static function strip_excluded_regions( $content ) {
		return (string) preg_replace(
			'/\[bluebranch_chatbot_exclude\b[^\]]*\].*?\[\/bluebranch_chatbot_exclude\]/s',
			'',
			(string) $content
		);
	}

	/**
	 * Removes this plugin's own shortcodes from content about to be trained.
	 *
	 * A chat widget quoted back as part of the answer would be nonsense, and
	 * rendering one during extraction would queue scripts for a request that
	 * has no page to put them on.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	public static function strip_own_shortcodes( $content ) {
		$pattern = get_shortcode_regex( self::$shortcodes );

		return (string) preg_replace( '/' . $pattern . '/', '', (string) $content );
	}
}
