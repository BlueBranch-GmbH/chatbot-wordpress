<?php
/**
 * The "Getting started" screen: five steps from nothing to a working chatbot.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Api_Client;
use BlueBranch\Chatbot\Frontend;
use BlueBranch\Chatbot\Indexer;
use BlueBranch\Chatbot\Logger;
use BlueBranch\Chatbot\Options;
use BlueBranch\Chatbot\Voice;

defined( 'ABSPATH' ) || exit;

/**
 * Walks through the key, the first training, the chat button, a test question
 * and the optional extras, and shows which of them are already done.
 *
 * Everything on it can be done elsewhere too; this is the short way for
 * somebody who has just installed the plugin. The forms post to admin-post.php,
 * are protected by a nonce and send the visitor back here.
 */
class Start_Page {

	/**
	 * Action name of the key form.
	 */
	const ACTION_KEY = 'bluebranch_chatbot_start_key';

	/**
	 * Action name of the switches.
	 */
	const ACTION_OPTION = 'bluebranch_chatbot_start_option';

	/**
	 * The settings the switches may change, with the notice shown afterwards.
	 *
	 * @return array<string, string>
	 */
	private function switchable() {
		return array(
			'widget_auto_display' => __( 'The chat button is now shown on every page.', 'bluebranch-chatbot' ),
			'voice_enabled'       => __( 'Voice input is switched on.', 'bluebranch-chatbot' ),
			'feedback_enabled'    => __( 'Ratings under the answers are switched on.', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * Hooks the form handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION_KEY, array( $this, 'handle_key' ) );
		add_action( 'admin_post_' . self::ACTION_OPTION, array( $this, 'handle_option' ) );
	}

	/**
	 * Where a handler sends the visitor afterwards.
	 *
	 * @param string $notice Code of the message to show.
	 * @return void
	 */
	private function back( $notice ) {
		wp_safe_redirect( add_query_arg( 'bb_notice', $notice, admin_url( 'admin.php?page=' . Admin::PAGE_START ) ) );
		exit;
	}

	/**
	 * Stores a new key, but only keeps it when the API accepts it.
	 *
	 * @return void
	 */
	public function handle_key() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_KEY );

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$key = trim( $key );

		if ( '' === $key ) {
			$this->back( 'key_empty' );
		}

		$previous = Options::api_key();
		$earlier  = Logger::last_error();

		update_option( Options::API_KEY, $key );

		$tier = ( new Api_Client() )->get_tier();

		if ( is_wp_error( $tier ) ) {
			// Not kept: a key the API turns down would only make the chat go
			// quiet without telling anybody why.
			if ( '' === $previous ) {
				delete_option( Options::API_KEY );
			} else {
				update_option( Options::API_KEY, $previous );
			}

			// The refusal is answered on this screen; leaving it as the last
			// API error would show it again, as a warning, on every other one.
			if ( null === $earlier ) {
				Logger::clear_last_error();
			}

			$this->back( 'key_rejected' );
		}

		// An error from before the key was replaced no longer says anything.
		Logger::clear_last_error();

		$this->back( 'key_ok' );
	}

	/**
	 * Switches one of the settings on or off.
	 *
	 * @return void
	 */
	public function handle_option() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_OPTION );

		$name  = isset( $_POST['option'] ) ? sanitize_key( wp_unslash( $_POST['option'] ) ) : '';
		$value = isset( $_POST['value'] ) && '1' === $_POST['value'];

		if ( ! isset( $this->switchable()[ $name ] ) ) {
			$this->back( 'unknown' );
		}

		Options::set( $name, $value );

		$this->back( $value ? 'on_' . $name : 'off_' . $name );
	}

	/**
	 * The message for a notice code.
	 *
	 * @param string $code The code from the query string.
	 * @return array{0: string, 1: string} Kind and text, or empty strings.
	 */
	private function notice( $code ) {
		$fixed = array(
			'key_ok'       => array( 'success', __( 'The key was accepted and stored.', 'bluebranch-chatbot' ) ),
			'key_rejected' => array( 'error', __( 'The API did not accept this key, so it was not stored. Please check it and try again.', 'bluebranch-chatbot' ) ),
			'key_empty'    => array( 'error', __( 'Please enter a key.', 'bluebranch-chatbot' ) ),
		);

		if ( isset( $fixed[ $code ] ) ) {
			return $fixed[ $code ];
		}

		foreach ( $this->switchable() as $name => $message ) {
			if ( 'on_' . $name === $code ) {
				return array( 'success', $message );
			}

			if ( 'off_' . $name === $code ) {
				return array( 'success', __( 'The setting was switched off.', 'bluebranch-chatbot' ) );
			}
		}

		return array( '', '' );
	}

	/**
	 * One switch: a small form with the opposite value.
	 *
	 * @param string $name        Setting.
	 * @param string $title       Heading.
	 * @param string $description Explanation.
	 * @param bool   $available   False when the switch cannot work yet.
	 * @return void
	 */
	private function render_switch( $name, $title, $description, $available = true ) {
		$on = (bool) Options::get( $name );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bb-start__switch">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_OPTION ); ?>">
			<input type="hidden" name="option" value="<?php echo esc_attr( $name ); ?>">
			<input type="hidden" name="value" value="<?php echo $on ? '0' : '1'; ?>">
			<?php wp_nonce_field( self::ACTION_OPTION ); ?>
			<div>
				<strong><?php echo esc_html( $title ); ?></strong>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</div>
			<button type="submit" class="bb-start__toggle<?php echo $on ? ' is-on' : ''; ?>" role="switch"
				aria-checked="<?php echo $on ? 'true' : 'false'; ?>"
				aria-label="<?php echo esc_attr( $title ); ?>"
				<?php disabled( ! $available && ! $on ); ?>>
				<span></span>
			</button>
		</form>
		<?php
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

		$has_key   = Options::has_api_key();
		$indexer   = new Indexer();
		$trained   = $has_key ? $indexer->trained_count() : 0;
		$trainable = $has_key ? $indexer->trainable_count() : 0;
		$has_train = $trained > 0;
		$has_btn   = (bool) Options::get( 'widget_auto_display' );
		$voice     = $has_key ? Voice::capability() : array( 'enabled' => false );

		$steps = array(
			'key'   => $has_key,
			'train' => $has_train,
			'show'  => $has_btn,
			'test'  => false,
			'more'  => false,
		);
		$done  = count( array_filter( $steps ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message to show.
		$code = isset( $_GET['bb_notice'] ) ? sanitize_key( wp_unslash( $_GET['bb_notice'] ) ) : '';

		list( $kind, $text ) = $this->notice( $code );

		// The ring: 3 of 3 measurable steps (the test and the extras cannot be "done").
		$total   = 3;
		$counted = min( $total, $done );
		$percent = (int) round( $counted / $total * 100 );
		?>
		<div class="wrap bluebranch-chatbot-admin bb-start">
			<h1 class="screen-reader-text"><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( '' !== $text ) : ?>
				<div class="notice notice-<?php echo esc_attr( $kind ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
			<?php endif; ?>

			<div class="bb-start__hero">
				<div class="bb-start__ring" style="--bb-percent: <?php echo (int) $percent; ?>;" role="img"
					aria-label="<?php echo esc_attr( sprintf( /* translators: 1: steps done, 2: steps in total. */ __( '%1$d of %2$d steps done', 'bluebranch-chatbot' ), $counted, $total ) ); ?>">
					<span><?php echo esc_html( $counted . '/' . $total ); ?></span>
				</div>
				<div>
					<h2><?php esc_html_e( 'Getting started with the BlueBranch Chatbot', 'bluebranch-chatbot' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							$counted >= $total
								? __( 'Everything is set up. Ask a test question below, or switch on voice input.', 'bluebranch-chatbot' )
								: __( 'Three short steps and your visitors can ask questions about your site.', 'bluebranch-chatbot' )
						);
						?>
					</p>
				</div>
			</div>

			<ol class="bb-start__steps">

				<li class="bb-start__step<?php echo $steps['key'] ? ' is-done' : ''; ?>">
					<span class="bb-start__marker" aria-hidden="true"><?php echo $steps['key'] ? '&#10003;' : '1'; ?></span>
					<div class="bb-start__card">
						<h3><?php esc_html_e( 'Enter your API key', 'bluebranch-chatbot' ); ?></h3>
						<?php if ( $has_key ) : ?>
							<p class="description"><?php esc_html_e( 'A key is stored. Enter a new one here to replace it; it is checked before it is kept.', 'bluebranch-chatbot' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'You receive the key with your account. It is checked right away and is never shown again.', 'bluebranch-chatbot' ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bb-start__inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_KEY ); ?>">
							<?php wp_nonce_field( self::ACTION_KEY ); ?>
							<label class="screen-reader-text" for="bb-start-key"><?php esc_html_e( 'API key', 'bluebranch-chatbot' ); ?></label>
							<input type="password" id="bb-start-key" name="api_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( $has_key ? __( 'New key (optional)', 'bluebranch-chatbot' ) : __( 'Paste your key', 'bluebranch-chatbot' ) ); ?>">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Check and save', 'bluebranch-chatbot' ); ?></button>
						</form>
					</div>
				</li>

				<li class="bb-start__step<?php echo $steps['train'] ? ' is-done' : ''; ?><?php echo $has_key ? '' : ' is-locked'; ?>">
					<span class="bb-start__marker" aria-hidden="true"><?php echo $steps['train'] ? '&#10003;' : '2'; ?></span>
					<div class="bb-start__card">
						<h3><?php esc_html_e( 'Let the chatbot learn your site', 'bluebranch-chatbot' ); ?></h3>
						<?php if ( ! $has_key ) : ?>
							<p class="description"><?php esc_html_e( 'Possible once a key is stored.', 'bluebranch-chatbot' ); ?></p>
						<?php else : ?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: posts trained, 2: posts that could be trained. */
										__( '%1$d of %2$d posts are known to the chatbot. Only what has changed is sent again.', 'bluebranch-chatbot' ),
										$trained,
										max( $trained, $trainable )
									)
								);
								?>
							</p>
							<p>
								<button type="button" class="button button-primary" id="bluebranch-chatbot-train">
									<?php echo esc_html( $has_train ? __( 'Update now', 'bluebranch-chatbot' ) : __( 'Train now', 'bluebranch-chatbot' ) ); ?>
								</button>
								<span class="description"><?php esc_html_e( 'Leave this tab open until the run finishes.', 'bluebranch-chatbot' ); ?></span>
							</p>
							<div id="bluebranch-chatbot-progress" class="bluebranch-chatbot-progress" hidden>
								<div class="bluebranch-chatbot-progress__bar"><span></span></div>
								<p id="bluebranch-chatbot-progress-text" class="description"></p>
							</div>
							<ul id="bluebranch-chatbot-log" class="bluebranch-chatbot-log"></ul>
						<?php endif; ?>
					</div>
				</li>

				<li class="bb-start__step<?php echo $steps['show'] ? ' is-done' : ''; ?><?php echo $has_key ? '' : ' is-locked'; ?>">
					<span class="bb-start__marker" aria-hidden="true"><?php echo $steps['show'] ? '&#10003;' : '3'; ?></span>
					<div class="bb-start__card">
						<h3><?php esc_html_e( 'Show the chat button', 'bluebranch-chatbot' ); ?></h3>
						<?php
						$this->render_switch(
							'widget_auto_display',
							__( 'Chat button on every page', 'bluebranch-chatbot' ),
							__( 'A round button in the corner opens the chat. Alternatively place it yourself with the shortcode [bluebranch_chatbot_widget] or the block.', 'bluebranch-chatbot' )
						);
						?>
					</div>
				</li>

				<li class="bb-start__step<?php echo $has_key ? '' : ' is-locked'; ?>">
					<span class="bb-start__marker" aria-hidden="true">4</span>
					<div class="bb-start__card">
						<h3><?php esc_html_e( 'Ask a test question', 'bluebranch-chatbot' ); ?></h3>
						<p class="description"><?php esc_html_e( 'The very field your visitors get. Ask something your site can answer.', 'bluebranch-chatbot' ); ?></p>
						<?php
						if ( $has_key ) {
							// Escaping happens in the template; the markup is assembled there.
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo ( new Frontend() )->render_test_field();
						}
						?>
					</div>
				</li>

				<li class="bb-start__step<?php echo $has_key ? '' : ' is-locked'; ?>">
					<span class="bb-start__marker" aria-hidden="true">5</span>
					<div class="bb-start__card">
						<h3><?php esc_html_e( 'Extras', 'bluebranch-chatbot' ); ?></h3>
						<?php
						$this->render_switch(
							'voice_enabled',
							__( 'Voice input', 'bluebranch-chatbot' ),
							$has_key && empty( $voice['enabled'] )
								? __( 'Visitors speak their question. Your plan does not include speech recognition yet.', 'bluebranch-chatbot' )
								: __( 'Visitors speak their question into a microphone button. Needs HTTPS. Please mention it in your privacy policy.', 'bluebranch-chatbot' ),
							! empty( $voice['enabled'] )
						);
						$this->render_switch(
							'feedback_enabled',
							__( 'Ratings under the answers', 'bluebranch-chatbot' ),
							__( 'Thumbs up and down show you which answers helped. They appear under Questions & feedback.', 'bluebranch-chatbot' )
						);
						?>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ) ); ?>">
								<?php esc_html_e( 'All settings', 'bluebranch-chatbot' ); ?> &rarr;
							</a>
						</p>
					</div>
				</li>
			</ol>
		</div>
		<?php
	}
}
