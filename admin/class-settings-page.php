<?php
/**
 * The settings screen.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Cron;
use BlueBranch\Chatbot\Options;
use BlueBranch\Chatbot\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Declares every setting through the Settings API and draws the form.
 *
 * Going through register_setting() rather than handling the POST directly
 * means WordPress takes care of the nonce, the capability check and the
 * "Settings saved" message, and every value passes a sanitise callback on its
 * way into the database whether it came from this form or from anywhere else.
 */
class Settings_Page {

	/**
	 * Option group both settings belong to.
	 */
	const GROUP = 'bluebranch_chatbot';

	/**
	 * What stands in the key field once a key is stored.
	 *
	 * No real key can look like this: keys are letters, digits, underscore and
	 * full stop.
	 */
	const KEY_MASK = '••••••••••••••••';

	/**
	 * Hooks the settings into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Declares the options, the sections and the fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			Options::API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::GROUP,
			Options::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Options::defaults(),
				'show_in_rest'      => false,
			)
		);

		$page = Admin::PAGE_SETTINGS;

		/*
		 * The order answers "what am I looking for?" rather than mirroring how
		 * the code is arranged. The key first, because without it nothing
		 * happens at all. Then what applies everywhere, so it is not hidden
		 * inside one module's box. Then the two modules, each with only what
		 * belongs to it -- a colour that turns out to be widget-only, or a
		 * greeting sitting next to search settings, is how a settings screen
		 * starts lying about what a field does.
		 */
		$sections = array(
			'access'      => array(
				'title'  => __( 'API access', 'bluebranch-chatbot' ),
				'intro'  => 'describe_access',
				'fields' => array(
					'api_key' => __( 'API key', 'bluebranch-chatbot' ),
				),
			),
			'general'     => array(
				'title'  => __( 'General', 'bluebranch-chatbot' ),
				'intro'  => 'describe_general',
				'fields' => array(
					'accent_color' => __( 'Accent colour', 'bluebranch-chatbot' ),
					'suggestions'  => __( 'Suggested questions', 'bluebranch-chatbot' ),
					'styling'      => __( 'Styling', 'bluebranch-chatbot' ),
				),
			),
			'widget'      => array(
				'title'  => __( 'Chat widget', 'bluebranch-chatbot' ),
				'intro'  => 'describe_widget',
				'fields' => array(
					'bot_name'           => __( 'Chatbot name', 'bluebranch-chatbot' ),
					'greeting'           => __( 'Greeting', 'bluebranch-chatbot' ),
					'icon_attachment_id' => __( 'Icon', 'bluebranch-chatbot' ),
					'widget_position'    => __( 'Position', 'bluebranch-chatbot' ),
					'widget_display'     => __( 'Display', 'bluebranch-chatbot' ),
				),
			),
			'ask'         => array(
				'title'  => __( 'Search answer', 'bluebranch-chatbot' ),
				'intro'  => 'describe_ask',
				'fields' => array(
					'ask_labels'      => __( 'Wording', 'bluebranch-chatbot' ),
					'typed_questions' => __( 'Typed questions', 'bluebranch-chatbot' ),
					'search_answer'   => __( 'Search results', 'bluebranch-chatbot' ),
				),
			),
			'content'     => array(
				'title'  => __( 'Content', 'bluebranch-chatbot' ),
				'intro'  => 'describe_content',
				'fields' => array(
					'post_types'        => __( 'Post types', 'bluebranch-chatbot' ),
					'training_source'   => __( 'Where the content comes from', 'bluebranch-chatbot' ),
					'sitemap_url'       => __( 'Sitemap address', 'bluebranch-chatbot' ),
					'content_behaviour' => __( 'Behaviour', 'bluebranch-chatbot' ),
				),
			),
			'maintenance' => array(
				'title'  => __( 'Maintenance', 'bluebranch-chatbot' ),
				'intro'  => 'describe_maintenance',
				'fields' => array(
					'cleanup'        => __( 'Automatic clean-up', 'bluebranch-chatbot' ),
					'purge_interval' => __( 'Interval', 'bluebranch-chatbot' ),
					'debug_logging'  => __( 'Debug logging', 'bluebranch-chatbot' ),
				),
			),
		);

		foreach ( $sections as $name => $section ) {
			$id = 'bluebranch_chatbot_' . $name;

			add_settings_section( $id, $section['title'], array( $this, $section['intro'] ), $page );

			foreach ( $section['fields'] as $key => $label ) {
				add_settings_field( $key, $label, array( $this, 'field_' . $key ), $page, $id );
			}
		}
	}

	/**
	 * Draws the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ) );
		}
		?>
		<div class="wrap bluebranch-chatbot-admin bluebranch-chatbot-admin--settings">
			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<div id="bluebranch-chatbot-tier" class="bluebranch-chatbot-tier" hidden></div>

				<div class="bluebranch-chatbot-titlebar">
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<?php
					/*
					 * The same button as at the foot of the form. The page is
					 * long enough that a change made near the top otherwise
					 * means scrolling past everything else to keep it.
					 *
					 * Named differently so the two do not collide; the Settings
					 * API only cares that something called "submit" arrives.
					 */
					submit_button( __( 'Save changes', 'bluebranch-chatbot' ), 'primary', 'submit-top', false );
					?>
				</div>

				<?php
				do_settings_sections( Admin::PAGE_SETTINGS );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Decides what is stored when the key field is submitted.
	 *
	 * The danger here is deleting a customer's key: their chatbot then goes
	 * quiet and nobody can say why. So the rule is spelled out rather than
	 * inferred -- leaving the mask keeps the key, emptying the field is a
	 * deliberate deletion, anything else is a new key.
	 *
	 * @param string $value What the form submitted.
	 * @return string What to store.
	 */
	public function sanitize_api_key( $value ) {
		$value    = trim( (string) $value );
		$existing = Options::api_key();

		if ( '' === $value ) {
			return '';
		}

		if ( self::is_mask( $value ) ) {
			return $existing;
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Whether an entry consists of nothing but masking characters.
	 *
	 * Not matched character for character: should a browser or an entity
	 * conversion render the mask differently, the result would be a key made
	 * of bullet points -- and the customer's chatbot would fall silent from
	 * the next save on.
	 *
	 * @param string $value What the form submitted.
	 * @return bool
	 */
	public static function is_mask( $value ) {
		return 1 === preg_match( '/^[\x{2022}\x{00B7}\x{2219}*.]+$/u', $value );
	}

	/**
	 * Cleans every setting on its way into the database.
	 *
	 * @param mixed $input What the form submitted.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Options::defaults();
		$clean    = array();

		$clean['bot_name']         = sanitize_text_field( isset( $input['bot_name'] ) ? $input['bot_name'] : '' );
		$clean['ask_button_label'] = sanitize_text_field( isset( $input['ask_button_label'] ) ? $input['ask_button_label'] : '' );
		$clean['ask_placeholder']  = sanitize_text_field( isset( $input['ask_placeholder'] ) ? $input['ask_placeholder'] : '' );
		$clean['ask_stop_label']   = sanitize_text_field( isset( $input['ask_stop_label'] ) ? $input['ask_stop_label'] : '' );
		$clean['greeting']         = sanitize_text_field( isset( $input['greeting'] ) ? $input['greeting'] : '' );

		$color                 = isset( $input['accent_color'] ) ? trim( (string) $input['accent_color'] ) : '';
		$color                 = '' === $color ? '' : sanitize_hex_color( '#' . ltrim( $color, '#' ) );
		$clean['accent_color'] = is_string( $color ) ? $color : '';

		$clean['icon_attachment_id'] = isset( $input['icon_attachment_id'] ) ? absint( $input['icon_attachment_id'] ) : 0;

		$clean['suggestions']     = $this->lines_to_array( isset( $input['suggestions'] ) ? $input['suggestions'] : '' );
		$clean['typed_questions'] = $this->lines_to_array( isset( $input['typed_questions'] ) ? $input['typed_questions'] : '' );

		$position                 = isset( $input['widget_position'] ) ? sanitize_key( $input['widget_position'] ) : '';
		$clean['widget_position'] = array_key_exists( $position, Options::widget_positions() ) ? $position : $defaults['widget_position'];

		$interval                = isset( $input['purge_interval'] ) ? sanitize_key( $input['purge_interval'] ) : '';
		$clean['purge_interval'] = array_key_exists( $interval, Options::purge_intervals() ) ? $interval : $defaults['purge_interval'];

		$source                   = isset( $input['training_source'] ) ? sanitize_key( $input['training_source'] ) : '';
		$clean['training_source'] = array_key_exists( $source, Options::training_sources() ) ? $source : $defaults['training_source'];

		$clean['sitemap_url'] = esc_url_raw( trim( (string) ( isset( $input['sitemap_url'] ) ? $input['sitemap_url'] : '' ) ) );

		foreach ( array( 'hide_summarize', 'hide_disclaimer', 'widget_auto_display', 'unstyled', 'auto_train', 'purge_enabled', 'search_integration', 'debug_logging', 'strip_boilerplate', 'reconcile_sitemap' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		// Only types that actually exist and are public: anything else would
		// train content nobody can open, and the link in the answer would 404.
		$available           = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$requested           = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : array();
		$clean['post_types'] = array_values( array_intersect( $requested, $available ) );

		return $clean;
	}

	/**
	 * Splits a textarea into trimmed, non-empty lines.
	 *
	 * @param string $value Raw textarea content.
	 * @return string[]
	 */
	private function lines_to_array( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $line ) {
						return sanitize_text_field( trim( $line ) );
					},
					$lines
				),
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
	}

	/**
	 * Introduces the access section.
	 *
	 * @return void
	 */
	public function describe_access() {
		printf(
			'<p>%s</p>',
			wp_kses(
				sprintf(
					/* translators: %s: link to the sign-up page. */
					__( 'Register at <a href="%s" target="_blank" rel="noopener">chatbot.bluebranch.de</a>, create a key and store it here. The key never leaves the server: the browser only ever talks to WordPress, and WordPress talks to the API.', 'bluebranch-chatbot' ),
					'https://chatbot.bluebranch.de'
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);
	}

	/**
	 * Introduces the general section.
	 *
	 * @return void
	 */
	public function describe_general() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Applies to the chat widget and the search answer alike. Anything below can still be overridden per placement with a shortcode attribute.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Introduces the chat widget section.
	 *
	 * @return void
	 */
	public function describe_widget() {
		printf(
			'<p>%s</p>',
			esc_html__( 'The collapsible chat button and the panel behind it. These settings reach nothing else.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Introduces the ask and search section.
	 *
	 * @return void
	 */
	public function describe_ask() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Placed on a page it brings its own question field and answers below it. Above a set of search results it answers the term that was searched for instead, using the field the theme already has.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Introduces the content section.
	 *
	 * @return void
	 */
	public function describe_content() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Which content the chatbot may learn from, and where the answers appear.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Introduces the maintenance section.
	 *
	 * @return void
	 */
	public function describe_maintenance() {
		$next = wp_next_scheduled( Cron::EVENT_PURGE );

		printf(
			'<p>%s</p>',
			esc_html__( 'Posts that are no longer published, have been excluded or have been set to noindex do not belong in the AI index. The clean-up run hooks into WordPress cron, so no server cronjob is needed -- it is enough that the site is visited now and then.', 'bluebranch-chatbot' )
		);

		if ( $next ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference, e.g. "3 hours". */
						__( 'Next run in %s.', 'bluebranch-chatbot' ),
						human_time_diff( $next )
					)
				)
			);
		}
	}

	/**
	 * The API key field.
	 *
	 * A stored key is never written into the form. hideInput-style masking
	 * alone would still put the value in the page source, in the browser
	 * history and in every cache that records page content.
	 *
	 * @return void
	 */
	public function field_api_key() {
		$stored = Options::has_api_key();
		?>
		<input type="password" class="regular-text" id="bluebranch_chatbot_api_key"
			name="<?php echo esc_attr( Options::API_KEY ); ?>"
			value="<?php echo $stored ? esc_attr( self::KEY_MASK ) : ''; ?>"
			autocomplete="off" spellcheck="false">
		<p class="description">
			<?php if ( $stored ) : ?>
				<?php esc_html_e( 'A key is stored. Leave the dots to keep it, empty the field to delete it, or type a new key to replace it.', 'bluebranch-chatbot' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'No key stored yet.', 'bluebranch-chatbot' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * The chatbot name field.
	 *
	 * @return void
	 */
	public function field_bot_name() {
		$this->text_field( 'bot_name', __( 'Shown in the chat header. Defaults to "Chat".', 'bluebranch-chatbot' ) );
	}

	/**
	 * The greeting field.
	 *
	 * @return void
	 */
	public function field_greeting() {
		$this->text_field( 'greeting', __( 'Shown when the chat is opened for the first time.', 'bluebranch-chatbot' ) );
	}

	/**
	 * The accent colour field.
	 *
	 * @return void
	 */
	public function field_accent_color() {
		printf(
			'<input type="text" class="bluebranch-chatbot-color" name="%1$s[accent_color]" value="%2$s" data-default-color="#2b6cb0"><p class="description">%3$s</p>',
			esc_attr( Options::SETTINGS ),
			esc_attr( (string) Options::get( 'accent_color' ) ),
			esc_html__( 'Used by the chat button, the ask field and the search answer. Leave empty for the built-in blue.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The icon field, backed by the media library.
	 *
	 * @return void
	 */
	public function field_icon_attachment_id() {
		$attachment_id = (int) Options::get( 'icon_attachment_id' );
		$url           = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : '';
		?>
		<div class="bluebranch-chatbot-media">
			<input type="hidden" id="bluebranch_chatbot_icon"
				name="<?php echo esc_attr( Options::SETTINGS ); ?>[icon_attachment_id]"
				value="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<img src="<?php echo esc_url( (string) $url ); ?>" alt="" class="bluebranch-chatbot-media__preview"
				<?php echo $url ? '' : 'hidden'; ?>>
			<button type="button" class="button bluebranch-chatbot-media__select">
				<?php esc_html_e( 'Choose icon', 'bluebranch-chatbot' ); ?>
			</button>
			<button type="button" class="button-link bluebranch-chatbot-media__clear" <?php echo $url ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'Remove', 'bluebranch-chatbot' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Replaces the built-in AI icon on the chat button. An SVG or a small square PNG works best.', 'bluebranch-chatbot' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The suggested questions field.
	 *
	 * @return void
	 */
	public function field_suggestions() {
		$this->textarea_field(
			'suggestions',
			__( 'One per line. Shown as clickable pills in the chat panel and under the question field; clicking one sends it straight away.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The typed questions field.
	 *
	 * @return void
	 */
	public function field_typed_questions() {
		$this->textarea_field(
			'typed_questions',
			__( 'One per line. Typed into the question field character by character as a placeholder. The animation rests on focus, while typing, in a background tab and with reduced motion switched on -- the pills above show the same questions without any movement.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The wording of the ask field.
	 *
	 * @return void
	 */
	public function field_ask_labels() {
		printf(
			'<p><label>%1$s<br><input type="text" class="regular-text" name="%2$s[ask_button_label]" value="%3$s" placeholder="%4$s"></label></p>',
			esc_html__( 'Button', 'bluebranch-chatbot' ),
			esc_attr( Options::SETTINGS ),
			esc_attr( (string) Options::get( 'ask_button_label' ) ),
			esc_attr__( 'Ask', 'bluebranch-chatbot' )
		);

		printf(
			'<p><label>%1$s<br><input type="text" class="regular-text" name="%2$s[ask_placeholder]" value="%3$s" placeholder="%4$s"></label></p>',
			esc_html__( 'Placeholder', 'bluebranch-chatbot' ),
			esc_attr( Options::SETTINGS ),
			esc_attr( (string) Options::get( 'ask_placeholder' ) ),
			esc_attr__( 'Ask your question …', 'bluebranch-chatbot' )
		);

		printf(
			'<p><label>%1$s<br><input type="text" class="regular-text" name="%2$s[ask_stop_label]" value="%3$s" placeholder="%4$s"></label></p>',
			esc_html__( 'Button while an answer is running', 'bluebranch-chatbot' ),
			esc_attr( Options::SETTINGS ),
			esc_attr( (string) Options::get( 'ask_stop_label' ) ),
			esc_attr__( 'Stop', 'bluebranch-chatbot' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Leave empty for the wording shown in grey. Applies to the question field on the site and to the test field in the back end alike.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The widget position field.
	 *
	 * @return void
	 */
	public function field_widget_position() {
		$this->select_field( 'widget_position', Options::widget_positions(), __( 'Which corner the chat button sits in.', 'bluebranch-chatbot' ) );
	}

	/**
	 * The clean-up interval field.
	 *
	 * @return void
	 */
	public function field_purge_interval() {
		$this->select_field( 'purge_interval', Options::purge_intervals(), __( 'How often the clean-up runs at most.', 'bluebranch-chatbot' ) );
	}

	/**
	 * Where the chat button appears and what it shows.
	 *
	 * @return void
	 */
	public function field_widget_display() {
		$this->checkbox_group(
			array(
				'widget_auto_display' => __( 'Show the chat button on every page', 'bluebranch-chatbot' ),
				'hide_summarize'      => __( 'Hide the "summarise this page" pill', 'bluebranch-chatbot' ),
				'hide_disclaimer'     => __( 'Hide the "Private chats & hosted in Germany" note', 'bluebranch-chatbot' ),
			),
			__( 'Without the first, the button only appears where the shortcode or the block is placed.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The stylesheet switch.
	 *
	 * @return void
	 */
	public function field_styling() {
		$this->checkbox_field(
			'unstyled',
			__( 'Do not load the plugin stylesheet', 'bluebranch-chatbot' ),
			__( 'Applies to the chat button, the ask field and the search answer alike. Everything keeps working; the appearance then has to come entirely from your own CSS. Every colour and size is a CSS custom property on the containers, so restyling rarely needs this switched off.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * What happens to content on its own.
	 *
	 * @return void
	 */
	public function field_content_behaviour() {
		$this->checkbox_group(
			array(
				'auto_train'        => __( 'Train a post when it is published or updated', 'bluebranch-chatbot' ),
				'strip_boilerplate' => __( 'Leave out blocks that appear on most pages', 'bluebranch-chatbot' ),
			),
			__( 'Training runs shortly after the save through WordPress cron, so saving stays fast. Repeated blocks are sampled at the start of a run -- a cookie notice, a breadcrumb bar, a newsletter box under every article.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Whether the answer joins the search results by itself.
	 *
	 * @return void
	 */
	public function field_search_answer() {
		$this->checkbox_field(
			'search_integration',
			__( 'Show a summarising AI answer above the search results', 'bluebranch-chatbot' ),
			__( 'Added to the theme\'s own search results page. For full control over where it sits, place the block or the shortcode instead.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * What the clean-up run is allowed to do.
	 *
	 * @return void
	 */
	public function field_cleanup() {
		$this->checkbox_group(
			array(
				'purge_enabled'     => __( 'Remove content that no longer qualifies', 'bluebranch-chatbot' ),
				'reconcile_sitemap' => __( 'Withdraw content the sitemap no longer lists', 'bluebranch-chatbot' ),
			),
			__( 'The second catches what the rules cannot see: a post an SEO plugin dropped from the sitemap. A sitemap that fails to load, or that would empty a large part of the index, is ignored rather than acted on.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The debug logging switch.
	 *
	 * @return void
	 */
	public function field_debug_logging() {
		$this->checkbox_field(
			'debug_logging',
			__( 'Write detailed entries to the PHP error log', 'bluebranch-chatbot' ),
			__( 'For troubleshooting only. Errors are logged either way; this adds a line for every training and deletion.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The field choosing between crawling and rendering.
	 *
	 * @return void
	 */
	public function field_training_source() {
		$this->select_field(
			'training_source',
			Options::training_sources(),
			__( 'Crawling fetches each page over HTTP and trains what a reader sees, including anything the template renders outside the post content. Rendering runs the post content through the_content instead, which is faster and works on servers that refuse to let a site call itself. A failed crawl falls back to rendering on its own.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The sitemap address field.
	 *
	 * @return void
	 */
	public function field_sitemap_url() {
		$sitemap = new Sitemap();
		$found   = $sitemap->entry_points();

		printf(
			'<input type="url" class="regular-text code" name="%1$s[sitemap_url]" value="%2$s" placeholder="%3$s">',
			esc_attr( Options::SETTINGS ),
			esc_attr( (string) Options::get( 'sitemap_url' ) ),
			esc_attr( home_url( '/wp-sitemap.xml' ) )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Leave empty to find it automatically: robots.txt is read first, which is where every SEO plugin announces its own sitemap.', 'bluebranch-chatbot' )
		);

		if ( array() !== $found ) {
			printf(
				'<p class="description"><strong>%s</strong> <code>%s</code></p>',
				esc_html__( 'Found:', 'bluebranch-chatbot' ),
				esc_html( implode( ', ', $found ) )
			);

			return;
		}

		printf(
			'<p class="description bluebranch-chatbot-warning">%s</p>',
			esc_html__( 'No sitemap could be found. Crawling needs one; enter its address here or switch to rendered content.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * The post types field.
	 *
	 * @return void
	 */
	public function field_post_types() {
		$selected = (array) Options::get( 'post_types' );
		$types    = get_post_types( array( 'public' => true ), 'objects' );

		echo '<fieldset>';

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s"%3$s> %4$s</label><br>',
				esc_attr( Options::SETTINGS ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}

		printf(
			'</fieldset><p class="description">%s</p>',
			esc_html__( 'Only published, publicly visible entries without a password are ever trained.', 'bluebranch-chatbot' )
		);
	}

	/**
	 * Draws a single line text field.
	 *
	 * @param string $key         Setting name.
	 * @param string $description Help text below the field.
	 * @return void
	 */
	private function text_field( $key, $description ) {
		printf(
			'<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s"><p class="description">%4$s</p>',
			esc_attr( Options::SETTINGS ),
			esc_attr( $key ),
			esc_attr( (string) Options::get( $key ) ),
			esc_html( $description )
		);
	}

	/**
	 * Draws a textarea holding one value per line.
	 *
	 * @param string $key         Setting name.
	 * @param string $description Help text below the field.
	 * @return void
	 */
	private function textarea_field( $key, $description ) {
		printf(
			'<textarea class="large-text code" rows="4" name="%1$s[%2$s]">%3$s</textarea><p class="description">%4$s</p>',
			esc_attr( Options::SETTINGS ),
			esc_attr( $key ),
			esc_textarea( implode( "\n", (array) Options::get( $key ) ) ),
			esc_html( $description )
		);
	}

	/**
	 * Draws a select.
	 *
	 * @param string   $key         Setting name.
	 * @param string[] $choices     Value to label.
	 * @param string   $description Help text below the field.
	 * @return void
	 */
	private function select_field( $key, array $choices, $description ) {
		$current = (string) Options::get( $key );

		printf( '<select name="%1$s[%2$s]">', esc_attr( Options::SETTINGS ), esc_attr( $key ) );

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		printf( '</select><p class="description">%s</p>', esc_html( $description ) );
	}

	/**
	 * Draws several related checkboxes as one row.
	 *
	 * @param array<string, string> $items       Setting name to label.
	 * @param string                $description Help text below them.
	 * @return void
	 */
	private function checkbox_group( array $items, $description ) {
		echo '<fieldset class="bluebranch-chatbot-group">';

		foreach ( $items as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
				esc_attr( Options::SETTINGS ),
				esc_attr( $key ),
				checked( (bool) Options::get( $key ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * Draws a checkbox.
	 *
	 * @param string $key         Setting name.
	 * @param string $label       Text next to the box.
	 * @param string $description Help text below it.
	 * @return void
	 */
	private function checkbox_field( $key, $label, $description ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
			esc_attr( Options::SETTINGS ),
			esc_attr( $key ),
			checked( (bool) Options::get( $key ), true, false ),
			esc_html( $label )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}
}
