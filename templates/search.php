<?php
/**
 * The search answer, with or without a field to ask from.
 *
 * Copy this file to your theme as bluebranch-chatbot/search.php to change it.
 * Available in $args: id, query, show_form, questions, suggestions,
 * placeholder, button, stop, style.
 *
 * @package BlueBranch\Chatbot
 */

defined( 'ABSPATH' ) || exit;

$bluebranch_chatbot_config = array(
	'mode'      => 'search',
	'query'     => $args['query'],
	'questions' => array_values( (array) $args['questions'] ),
	'askLabel'  => $args['button'],
	'stopLabel' => $args['stop'],
);
?>
<div class="chatbot-generate-search-container<?php echo '' === $args['query'] ? ' chatbot-empty' : ''; ?>"
	id="<?php echo esc_attr( $args['id'] ); ?>"
	data-bb-chatbot="search"
	data-bb-config="<?php echo esc_attr( wp_json_encode( $bluebranch_chatbot_config ) ); ?>"
	<?php
	if ( '' !== $args['style'] ) :
		?>
		style="<?php echo esc_attr( $args['style'] ); ?>"<?php endif; ?>>

	<?php if ( $args['show_form'] ) : ?>
		<form class="chatbot-ask-form" role="search">
			<div class="chatbot-field">
				<label class="screen-reader-text" for="<?php echo esc_attr( $args['id'] ); ?>-input">
					<?php esc_html_e( 'Your question', 'bluebranch-chatbot' ); ?>
				</label>
				<input type="search" class="chatbot-ask-input" id="<?php echo esc_attr( $args['id'] ); ?>-input"
					name="question" autocomplete="off"
					value="<?php echo esc_attr( $args['query'] ); ?>"
					placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>">
			</div>
			<div class="chatbot-field chatbot-field--submit">
				<button type="submit" class="chatbot-submit"><?php echo esc_html( $args['button'] ); ?></button>
			</div>
		</form>

		<?php if ( array() !== $args['suggestions'] ) : ?>
			<div class="chatbot-suggestions">
				<?php foreach ( $args['suggestions'] as $bluebranch_chatbot_suggestion ) : ?>
					<button type="button" class="chatbot-suggestion">
						<?php echo esc_html( $bluebranch_chatbot_suggestion ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="chatbot-response"<?php echo '' === $args['query'] ? ' hidden' : ''; ?>>
		<div class="chatbot-loading" hidden></div>
		<div class="chatbot-content" aria-live="polite"></div>
		<div class="chatbot-sources" hidden>
			<p><strong><?php esc_html_e( 'Sources:', 'bluebranch-chatbot' ); ?></strong></p>
			<ul></ul>
		</div>
	</div>
</div>
