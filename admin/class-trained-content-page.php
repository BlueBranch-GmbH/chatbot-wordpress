<?php
/**
 * The screen that shows what the knowledge base actually holds.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Frontend;
use BlueBranch\Chatbot\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the trained entries and lets an administrator ask a test question.
 *
 * The rows come from the API rather than from WordPress: what matters here is
 * what the knowledge base believes, which is not always what this site would
 * have sent. A page deleted from WordPress months ago and still answering
 * questions is exactly the kind of thing this screen exists to reveal.
 */
class Trained_Content_Page {

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
		<div class="wrap bluebranch-chatbot-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php Admin::render_missing_key_notice(); ?>

			<?php if ( Options::has_api_key() ) : ?>

				<div id="bluebranch-chatbot-tier" class="bluebranch-chatbot-tier" hidden></div>

				<div class="bluebranch-chatbot-card">
					<div class="bluebranch-chatbot-toolbar">
						<label class="bluebranch-chatbot-toolbar__field">
							<span><?php esc_html_e( 'Search', 'bluebranch-chatbot' ); ?></span>
							<input type="search" id="bluebranch-chatbot-filter" class="regular-text"
								placeholder="<?php esc_attr_e( 'Title, URL or ID …', 'bluebranch-chatbot' ); ?>">
						</label>

						<label class="bluebranch-chatbot-toolbar__field">
							<span><?php esc_html_e( 'Per page', 'bluebranch-chatbot' ); ?></span>
							<select id="bluebranch-chatbot-per-page">
								<?php foreach ( array( 10, 20, 50, 100, 250 ) as $size ) : ?>
									<option value="<?php echo esc_attr( (string) $size ); ?>"
										<?php selected( 20, $size ); ?>>
										<?php echo esc_html( (string) $size ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<button type="button" class="button button-link-delete" id="bluebranch-chatbot-delete-all">
							<?php esc_html_e( 'Delete everything', 'bluebranch-chatbot' ); ?>
						</button>
					</div>

					<p id="bluebranch-chatbot-count" class="description"></p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Title / URL', 'bluebranch-chatbot' ); ?></th>
								<th scope="col"><?php esc_html_e( 'External ID', 'bluebranch-chatbot' ); ?></th>
								<th scope="col" class="bluebranch-chatbot-col--narrow"><?php esc_html_e( 'Chunks', 'bluebranch-chatbot' ); ?></th>
								<th scope="col" class="bluebranch-chatbot-col--narrow"><?php esc_html_e( 'Language', 'bluebranch-chatbot' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Trained at', 'bluebranch-chatbot' ); ?></th>
								<th scope="col" class="bluebranch-chatbot-col--narrow"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'bluebranch-chatbot' ); ?></span></th>
							</tr>
						</thead>
						<tbody id="bluebranch-chatbot-rows">
							<tr>
								<td colspan="6"><?php esc_html_e( 'Loading …', 'bluebranch-chatbot' ); ?></td>
							</tr>
						</tbody>
					</table>

					<div id="bluebranch-chatbot-pagination" class="tablenav-pages"></div>
				</div>

				<div class="bluebranch-chatbot-card">
					<h2><?php esc_html_e( 'Test the chatbot', 'bluebranch-chatbot' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Ask a question and check the answer and its sources without opening the site. This runs through exactly the same route a visitor uses.', 'bluebranch-chatbot' ); ?>
					</p>

					<?php
					// Escaped inside the template that builds the markup.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo ( new Frontend() )->render_test_field();
					?>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}
}
