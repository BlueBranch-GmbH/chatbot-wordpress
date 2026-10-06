<?php
/**
 * The table of saved questions.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Answer_Log;
use BlueBranch\Chatbot\Schema;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists questions, answers and feedback with search, rating filter and paging.
 *
 * Everything shown here was typed by visitors or written by the model, so
 * every cell is escaped -- the answer included, which is shown as text and not
 * rendered as markdown.
 */
class Log_List_Table extends \WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Sets the names WP_List_Table builds its nonces and classes from.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bluebranch_chatbot_question',
				'plural'   => 'bluebranch_chatbot_questions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The active rating filter.
	 *
	 * @return string
	 */
	public static function current_rating() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter, nothing is changed.
		$rating = isset( $_GET['rating'] ) ? sanitize_key( wp_unslash( $_GET['rating'] ) ) : '';

		return in_array( $rating, array( 'up', 'down', 'none' ), true ) ? $rating : '';
	}

	/**
	 * The active search term.
	 *
	 * @return string
	 */
	public static function current_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter, nothing is changed.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	/**
	 * Column headings.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox">',
			'created_at' => __( 'Date', 'bluebranch-chatbot' ),
			'question'   => __( 'Question', 'bluebranch-chatbot' ),
			'answer'     => __( 'Answer', 'bluebranch-chatbot' ),
			'rating'     => __( 'Rating', 'bluebranch-chatbot' ),
			'comment'    => __( 'Comment', 'bluebranch-chatbot' ),
			'source'     => __( 'Where', 'bluebranch-chatbot' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'bluebranch-chatbot' ) );
	}

	/**
	 * The filter links above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = self::current_rating();
		$base    = admin_url( 'admin.php?page=' . Admin::PAGE_LOG );
		$views   = array(
			''     => __( 'All', 'bluebranch-chatbot' ),
			'up'   => __( 'Helpful', 'bluebranch-chatbot' ),
			'down' => __( 'Not helpful', 'bluebranch-chatbot' ),
			'none' => __( 'Not rated', 'bluebranch-chatbot' ),
		);
		$links   = array();

		foreach ( $views as $key => $label ) {
			$links[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( '' === $key ? $base : add_query_arg( 'rating', $key, $base ) ),
				$key === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return $links;
	}

	/**
	 * Loads the rows of the current page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$where  = Answer_Log::where(
			array(
				'rating' => self::current_rating(),
				'search' => self::current_search(),
			)
		);
		$table  = Schema::log_table();
		$offset = ( $this->get_pagenum() - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; $where is prepared in Answer_Log::where().
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$this->items = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * The checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d">', (int) $item['id'] );
	}

	/**
	 * The date column, in the site's time zone.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( (string) $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * The question.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_question( $item ) {
		return $this->expandable( (string) $item['question'], 160 );
	}

	/**
	 * The answer, as text.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_answer( $item ) {
		return $this->expandable( (string) $item['answer'], 160 );
	}

	/**
	 * The rating as a symbol with a readable label.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_rating( $item ) {
		if ( 'up' === $item['rating'] ) {
			return '<span title="' . esc_attr__( 'Helpful', 'bluebranch-chatbot' ) . '">👍</span><span class="screen-reader-text">' . esc_html__( 'Helpful', 'bluebranch-chatbot' ) . '</span>';
		}

		if ( 'down' === $item['rating'] ) {
			return '<span title="' . esc_attr__( 'Not helpful', 'bluebranch-chatbot' ) . '">👎</span><span class="screen-reader-text">' . esc_html__( 'Not helpful', 'bluebranch-chatbot' ) . '</span>';
		}

		return '&ndash;';
	}

	/**
	 * The comment left with a thumbs down.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_comment( $item ) {
		return '' === (string) $item['comment'] ? '&ndash;' : $this->expandable( (string) $item['comment'], 120 );
	}

	/**
	 * Which module, and on which page.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$labels = array(
			'widget' => __( 'Chat widget', 'bluebranch-chatbot' ),
			'ask'    => __( 'Ask field', 'bluebranch-chatbot' ),
			'search' => __( 'Search answer', 'bluebranch-chatbot' ),
		);

		$out     = esc_html( isset( $labels[ $item['source'] ] ) ? $labels[ $item['source'] ] : (string) $item['source'] );
		$post_id = (int) $item['post_id'];

		if ( $post_id > 0 && get_post( $post_id ) ) {
			$out .= '<br><a href="' . esc_url( (string) get_permalink( $post_id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( get_the_title( $post_id ) ) . '</a>';
		}

		return $out;
	}

	/**
	 * Fallback for columns without a method of their own.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * What to say when there is nothing to show.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No questions saved yet.', 'bluebranch-chatbot' );
	}

	/**
	 * Shortens a long text and offers the rest behind a disclosure.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters shown before the disclosure.
	 * @return string
	 */
	private function expandable( $text, $limit ) {
		$short = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );

		if ( $short === $text ) {
			return nl2br( esc_html( $text ) );
		}

		return sprintf(
			'<details><summary>%s …</summary><div class="bluebranch-chatbot-log__full">%s</div></details>',
			esc_html( $short ),
			nl2br( esc_html( $text ) )
		);
	}
}
