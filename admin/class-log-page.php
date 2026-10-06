<?php
/**
 * The "Questions & feedback" screen.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Answer_Log;
use BlueBranch\Chatbot\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the saved questions and hands them out as CSV.
 */
class Log_Page {

	/**
	 * Action name of the export behind admin-post.php.
	 */
	const EXPORT_ACTION = 'bluebranch_chatbot_export_log';

	/**
	 * Hooks the export into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
	}

	/**
	 * Handles the bulk delete before anything is printed.
	 *
	 * Runs on the screen's load hook so the redirect afterwards can still send
	 * headers; doing it in render() would be too late.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			return;
		}

		$table  = new Log_List_Table();
		$action = $table->current_action();

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-bluebranch_chatbot_questions' );

		$ids     = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
		$removed = Answer_Log::delete( $ids );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Admin::PAGE_LOG,
					'deleted' => $removed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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

		$table = new Log_List_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::EXPORT_ACTION,
					'rating' => Log_List_Table::current_rating(),
					's'      => Log_List_Table::current_search(),
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
		?>
		<div class="wrap bluebranch-chatbot-admin bluebranch-chatbot-admin--log">
			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export as CSV', 'bluebranch-chatbot' ); ?></a>
			<hr class="wp-header-end">

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reports the outcome of a verified action.
			if ( isset( $_GET['deleted'] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of entries. */
							_n( '%d entry deleted.', '%d entries deleted.', absint( $_GET['deleted'] ), 'bluebranch-chatbot' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							absint( $_GET['deleted'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						)
					)
				);
			}

			if ( ! Answer_Log::logging_enabled() ) {
				printf(
					'<div class="notice notice-info"><p>%s</p></div>',
					wp_kses(
						sprintf(
							/* translators: %s: link to the settings screen. */
							__( 'Questions are not saved at the moment. Only answers that received feedback appear here. Saving can be switched on in the <a href="%s">settings</a>.', 'bluebranch-chatbot' ),
							esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ) )
						),
						array( 'a' => array( 'href' => array() ) )
					)
				);
			}

			$table->views();
			?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_LOG ); ?>">
				<?php if ( '' !== Log_List_Table::current_rating() ) : ?>
					<input type="hidden" name="rating" value="<?php echo esc_attr( Log_List_Table::current_rating() ); ?>">
				<?php endif; ?>
				<?php
				$table->search_box( __( 'Search', 'bluebranch-chatbot' ), 'bluebranch-chatbot-log' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sends the rows matching the current filter as a CSV download.
	 *
	 * Semicolon and a byte order mark, because that is what a German Excel
	 * opens without an import dialogue. Cells starting with = + - @ are
	 * prefixed with an apostrophe: a visitor's comment must not turn into a
	 * formula on the evaluating colleague's machine.
	 *
	 * @return void
	 */
	public function export() {
		global $wpdb;

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		$where = Answer_Log::where(
			array(
				'rating' => Log_List_Table::current_rating(),
				'search' => Log_List_Table::current_search(),
			)
		);
		$table = Schema::log_table();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="chatbot-fragen-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the response.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array( 'id', 'created_at', 'source', 'post_id', 'page', 'language', 'question', 'answer', 'sources', 'rating', 'comment', 'feedback_at' ),
			';',
			'"',
			''
		);

		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; $where is prepared in Answer_Log::where().
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id ASC LIMIT 500 OFFSET %d", $offset ), ARRAY_A );

			foreach ( $rows as $row ) {
				$sources = json_decode( (string) $row['sources'], true );
				$urls    = array();

				foreach ( is_array( $sources ) ? $sources : array() as $source ) {
					if ( ! empty( $source['url'] ) ) {
						$urls[] = $source['url'];
					}
				}

				$post_id = (int) $row['post_id'];

				fputcsv(
					$out,
					array_map(
						array( $this, 'csv_cell' ),
						array(
							$row['id'],
							$row['created_at'],
							$row['source'],
							$post_id,
							$post_id > 0 ? (string) get_permalink( $post_id ) : '',
							$row['language'],
							$row['question'],
							$row['answer'],
							implode( ' ', $urls ),
							$row['rating'],
							$row['comment'],
							(string) $row['feedback_at'],
						)
					),
					';',
					'"',
					// No escape character: PHP's default backslash leaves `\"` unquoted,
					// which Excel reads as the end of the cell -- a question like
					// `x \";=1+2;` would then start a cell of its own with a formula.
					''
				);
			}

			$offset += 500;
			$fetched = count( $rows );
		} while ( 500 === $fetched );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the output stream.
		fclose( $out );
		exit;
	}

	/**
	 * Defuses a value Excel would read as a formula.
	 *
	 * Every line of a multi-line value is checked: a spreadsheet that splits
	 * cells on line breaks would otherwise see a formula at the start of the
	 * second line.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public function csv_cell( $value ) {
		$lines = preg_split( '/(\r\n|\n|\r)/', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE );

		foreach ( $lines as $index => $line ) {
			if ( 1 === $index % 2 ) {
				continue;
			}

			if ( '' !== $line && false !== strpos( "=+-@\t\r", $line[0] ) ) {
				$lines[ $index ] = "'" . $line;
			}
		}

		return implode( '', $lines );
	}
}
