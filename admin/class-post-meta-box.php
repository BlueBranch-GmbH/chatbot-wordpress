<?php
/**
 * The box on the post editor that keeps a post out of the answers.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Eligibility;
use BlueBranch\Chatbot\Indexer;
use BlueBranch\Chatbot\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the exclusion checkbox and says what the index currently knows.
 *
 * The status line matters as much as the checkbox: an editor who has excluded
 * a page wants to see that it is gone, and "excluded, still in the index until
 * the next clean-up" is a different state from "excluded and withdrawn".
 */
class Post_Meta_Box {

	/**
	 * Name of the nonce field.
	 */
	const NONCE = 'bluebranch_chatbot_meta_box';

	/**
	 * Hooks the box into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Adds the box to every post type the plugin may train.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'bluebranch-chatbot',
			__( 'BlueBranch Chatbot', 'bluebranch-chatbot' ),
			array( $this, 'render' ),
			Options::trainable_post_types(),
			'side',
			'default'
		);
	}

	/**
	 * Draws the box.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render( $post ) {
		$excluded     = '1' === (string) get_post_meta( $post->ID, Indexer::META_EXCLUDE, true );
		$trained      = (int) get_post_meta( $post->ID, Indexer::META_TRAINED, true );
		$eligibility  = new Eligibility();
		$inherited    = ! $excluded && $eligibility->is_excluded( $post->ID );
		$hierarchical = is_post_type_hierarchical( $post->post_type );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label>
				<input type="checkbox" name="bluebranch_chatbot_exclude" value="1" <?php checked( $excluded ); ?>>
				<?php esc_html_e( 'Exclude from AI answers', 'bluebranch-chatbot' ); ?>
			</label>
		</p>

		<p class="description">
			<?php if ( $hierarchical ) : ?>
				<?php esc_html_e( 'This post is not used as a source for chatbot answers. The setting is inherited by every child page.', 'bluebranch-chatbot' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'This post is not used as a source for chatbot answers.', 'bluebranch-chatbot' ); ?>
			<?php endif; ?>
		</p>

		<?php if ( $inherited ) : ?>
			<p class="description">
				<strong><?php esc_html_e( 'Excluded by a parent page.', 'bluebranch-chatbot' ); ?></strong>
			</p>
		<?php endif; ?>

		<hr>

		<p class="description">
			<?php if ( $trained > 0 ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'In the knowledge base since %s.', 'bluebranch-chatbot' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $trained )
					)
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Not in the knowledge base yet.', 'bluebranch-chatbot' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Stores the checkbox.
	 *
	 * The removal from the index is not triggered here: Indexer listens on
	 * wp_after_insert_post, which runs once the meta written here is in place.
	 * Doing it from both would send the same request twice.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// The box is only drawn for these types; a save for anything else did
		// not come from this form.
		if ( ! in_array( $post->post_type, Options::trainable_post_types(), true ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		/*
		 * No nonce means this save did not come from the classic editor form --
		 * a REST save from the block editor, wp-cli, an importer. Those must
		 * not be treated as "the box was submitted with the checkbox cleared",
		 * which would silently drop an exclusion an editor had set.
		 */
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['bluebranch_chatbot_exclude'] ) ) {
			update_post_meta( $post_id, Indexer::META_EXCLUDE, '1' );

			return;
		}

		delete_post_meta( $post_id, Indexer::META_EXCLUDE );
	}
}
