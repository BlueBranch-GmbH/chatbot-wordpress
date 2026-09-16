<?php
/**
 * The screen that hands existing content to the knowledge base.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Crawler;
use BlueBranch\Chatbot\Indexer;
use BlueBranch\Chatbot\Options;
use BlueBranch\Chatbot\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the site's own sitemap, crawling each page in small batches.
 *
 * Contao fills its knowledge base through the search index crawler. WordPress
 * has no crawler, so this screen is it: the sitemap says what the site has,
 * each of those pages is fetched over HTTP, and what a reader would see is
 * what gets trained.
 *
 * The run goes through the browser in batches of a few pages rather than in
 * one request. A site with a thousand pages would otherwise need a request
 * that outlives every PHP time limit, and would have nothing to show for
 * itself until it either finished or died.
 *
 * Re-running is cheap on purpose. Each page is hashed as it is sent, and a
 * page whose hash has not moved is not sent again -- so a second run costs one
 * API call per page that actually changed, not one per page.
 */
class Training_Page {

	/**
	 * Draws the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ) );
		}

		$indexer = new Indexer();
		$trained = $indexer->trained_count();
		?>
		<div class="wrap bluebranch-chatbot-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php Admin::render_missing_key_notice(); ?>

			<?php if ( Options::has_api_key() ) : ?>

				<?php $this->render_diagnostics( $indexer, $trained ); ?>

				<div class="bluebranch-chatbot-card">
					<h2><?php esc_html_e( 'Train content', 'bluebranch-chatbot' ); ?></h2>

					<p class="description">
						<?php esc_html_e( 'Only pages whose content has changed since the last run are sent. Leave this tab open until the run finishes.', 'bluebranch-chatbot' ); ?>
					</p>

					<p>
						<label>
							<input type="checkbox" id="bluebranch-chatbot-force">
							<?php esc_html_e( 'Send everything again, even where nothing has changed', 'bluebranch-chatbot' ); ?>
						</label>
					</p>

					<p>
						<button type="button" class="button button-primary" id="bluebranch-chatbot-train">
							<?php esc_html_e( 'Start the run', 'bluebranch-chatbot' ); ?>
						</button>
					</p>

					<div id="bluebranch-chatbot-progress" class="bluebranch-chatbot-progress" hidden>
						<div class="bluebranch-chatbot-progress__bar"><span></span></div>
						<p id="bluebranch-chatbot-progress-text" class="description"></p>
					</div>

					<ul id="bluebranch-chatbot-log" class="bluebranch-chatbot-log"></ul>
				</div>

				<?php if ( Options::crawls() ) : ?>
					<div class="bluebranch-chatbot-card">
						<h2><?php esc_html_e( 'Check one page', 'bluebranch-chatbot' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Shows exactly what would be trained for one address, so you can see for yourself that the header, the footer and the menus are gone.', 'bluebranch-chatbot' ); ?>
						</p>

						<p>
							<input type="url" id="bluebranch-chatbot-preview-url" class="regular-text code"
								value="<?php echo esc_url( home_url( '/' ) ); ?>">
							<button type="button" class="button" id="bluebranch-chatbot-preview">
								<?php esc_html_e( 'Show what would be trained', 'bluebranch-chatbot' ); ?>
							</button>
						</p>

						<div id="bluebranch-chatbot-preview-result" hidden>
							<p id="bluebranch-chatbot-preview-meta" class="description"></p>
							<pre id="bluebranch-chatbot-preview-text" class="bluebranch-chatbot-preview"></pre>
						</div>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Says what the run will find before anybody starts it.
	 *
	 * A crawl has two things that can be wrong before a single page is read:
	 * the server may not be allowed to call itself, and there may be no
	 * sitemap to read. Both produce the same symptom -- nothing gets trained --
	 * and neither is guessable from that symptom, so both are checked here and
	 * named.
	 *
	 * @param Indexer $indexer The indexer.
	 * @param int     $trained How many posts carry the trained marker.
	 * @return void
	 */
	private function render_diagnostics( Indexer $indexer, $trained ) {
		echo '<div class="bluebranch-chatbot-card">';

		printf( '<h2>%s</h2>', esc_html__( 'What will be trained', 'bluebranch-chatbot' ) );

		if ( ! Options::crawls() ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of eligible posts, 2: number already trained. */
						__( 'Rendered content: %1$d posts qualify, %2$d have been handed over at least once.', 'bluebranch-chatbot' ),
						$indexer->trainable_count(),
						$trained
					)
				)
			);

			echo '</div>';

			return;
		}

		$loopback = ( new Crawler() )->check_loopback();

		if ( is_wp_error( $loopback ) ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p><p>%s</p></div></div>',
				esc_html__( 'This site cannot fetch its own pages.', 'bluebranch-chatbot' ),
				esc_html( $loopback->get_error_message() ),
				esc_html__( 'Crawling will not work here. Switch "Where the content comes from" to rendered content, or have the host allow loopback requests.', 'bluebranch-chatbot' )
			);

			return;
		}

		$entry_points = ( new Sitemap() )->entry_points();

		if ( array() === $entry_points ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div></div>',
				esc_html__( 'No XML sitemap was found. Enter its address in the settings, or switch to rendered content.', 'bluebranch-chatbot' )
			);

			return;
		}

		$found = $indexer->crawl_targets();

		if ( is_wp_error( $found ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div></div>',
				esc_html( $found->get_error_message() )
			);

			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: URLs in the sitemap, 2: pages that will be crawled, 3: pages already trained. */
					__( 'The sitemap lists %1$d addresses. %2$d of them will be crawled; %3$d are already in the knowledge base.', 'bluebranch-chatbot' ),
					(int) $found['total'],
					count( $found['targets'] ),
					(int) $trained
				)
			)
		);

		printf(
			'<p class="description"><code>%s</code></p>',
			esc_html( implode( ', ', $entry_points ) )
		);

		if ( array() === $found['unresolved'] ) {
			echo '</div>';

			return;
		}

		/*
		 * Named rather than silently dropped. An operator comparing the two
		 * numbers will notice the gap immediately, and "where did the other
		 * forty go" is a much worse question than a sentence explaining it.
		 */
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of addresses. */
					__( '%d addresses are listed but belong to no single post -- category archives, author pages, the blog home. They are not trained, because an entry needs a post behind it to be updated and withdrawn again later.', 'bluebranch-chatbot' ),
					count( $found['unresolved'] )
				)
			)
		);

		echo '</div>';
	}
}
