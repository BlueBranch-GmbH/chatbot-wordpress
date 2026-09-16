<?php
/**
 * The chat button and its panel.
 *
 * Copy this file to your theme as bluebranch-chatbot/widget.php to change it.
 * Available in $args: id, position, color, color_contrast, icon_url, bot_name,
 * greeting, suggestions, show_summarize, show_disclaimer.
 *
 * @package BlueBranch\Chatbot
 */

defined( 'ABSPATH' ) || exit;

$bluebranch_chatbot_style = array();

if ( '' !== $args['color'] ) {
	$bluebranch_chatbot_style[] = '--chatbot-widget-accent: ' . $args['color'];
	$bluebranch_chatbot_style[] = '--chatbot-widget-accent-contrast: ' . $args['color_contrast'];
}

if ( '' !== $args['icon_url'] ) {
	$bluebranch_chatbot_style[] = "--chatbot-widget-icon: url('" . esc_url( $args['icon_url'] ) . "')";
}

$bluebranch_chatbot_config = array(
	'greeting'      => $args['greeting'],
	'suggestions'   => array_values( (array) $args['suggestions'] ),
	'showSummarize' => (bool) $args['show_summarize'],
);
?>
<div class="chatbot-widget"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	data-position="<?php echo esc_attr( $args['position'] ); ?>"
	data-bb-chatbot="widget"
	data-bb-config="<?php echo esc_attr( wp_json_encode( $bluebranch_chatbot_config ) ); ?>"
	<?php if ( array() !== $bluebranch_chatbot_style ) : ?>
		style="<?php echo esc_attr( implode( '; ', $bluebranch_chatbot_style ) . ';' ); ?>"
	<?php endif; ?>
	>
	<button type="button" class="chatbot-widget__toggle" aria-expanded="false"
		aria-controls="<?php echo esc_attr( $args['id'] ); ?>-panel"
		aria-label="<?php echo esc_attr( $args['bot_name'] ); ?>">
		<span class="chatbot-widget__toggle-icon" aria-hidden="true"></span>
		<span class="chatbot-widget__badge" hidden></span>
	</button>

	<div class="chatbot-widget__panel" id="<?php echo esc_attr( $args['id'] ); ?>-panel" hidden>
		<div class="chatbot-widget__header">
			<span class="chatbot-widget__header-title"><?php echo esc_html( $args['bot_name'] ); ?></span>
			<div class="chatbot-widget__header-actions">
				<div class="chatbot-widget__font-controls">
					<button type="button" class="chatbot-widget__font-btn chatbot-widget__font-btn--inc"
						aria-label="<?php esc_attr_e( 'Increase font size', 'bluebranch-chatbot' ); ?>"
						title="<?php esc_attr_e( 'Increase font size', 'bluebranch-chatbot' ); ?>">A</button>
					<button type="button" class="chatbot-widget__font-btn chatbot-widget__font-btn--dec"
						aria-label="<?php esc_attr_e( 'Decrease font size', 'bluebranch-chatbot' ); ?>"
						title="<?php esc_attr_e( 'Decrease font size', 'bluebranch-chatbot' ); ?>">A</button>
				</div>
				<button type="button" class="chatbot-widget__clear"
					aria-label="<?php esc_attr_e( 'Clear the chat', 'bluebranch-chatbot' ); ?>"
					title="<?php esc_attr_e( 'Clear the chat', 'bluebranch-chatbot' ); ?>">&#8635;</button>
				<button type="button" class="chatbot-widget__close"
					aria-label="<?php esc_attr_e( 'Close the chat', 'bluebranch-chatbot' ); ?>">&times;</button>
			</div>
		</div>

		<div class="chatbot-widget__messages" role="log" aria-live="polite"></div>
		<div class="chatbot-widget__suggestions" hidden></div>

		<form class="chatbot-widget__form">
			<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-input">
				<?php esc_html_e( 'Your message', 'bluebranch-chatbot' ); ?>
			</label>
			<textarea class="chatbot-widget__input" id="<?php echo esc_attr( $args['id'] ); ?>-input" rows="1"
				placeholder="<?php esc_attr_e( 'Your message …', 'bluebranch-chatbot' ); ?>" required></textarea>
			<button type="submit" class="chatbot-widget__send"
				aria-label="<?php esc_attr_e( 'Send message', 'bluebranch-chatbot' ); ?>">&#10148;</button>
		</form>

		<?php if ( $args['show_disclaimer'] ) : ?>
			<div class="chatbot-widget__footer">
				<?php esc_html_e( 'Private chats &amp; hosted in Germany', 'bluebranch-chatbot' ); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
