<?php
/**
 * The "Additional content" screen.
 *
 * @package BlueBranch\Chatbot\Admin
 */

namespace BlueBranch\Chatbot\Admin;

use BlueBranch\Chatbot\Extra_Content;
use BlueBranch\Chatbot\Options;
use BlueBranch\Chatbot\Text_Extractor;

defined( 'ABSPATH' ) || exit;

/**
 * Notes and documents for the knowledge base that have no page of their own.
 *
 * Every change goes through admin-post.php with a nonce of its own and the
 * capability check, and is answered with a redirect -- reloading the page
 * afterwards never repeats it.
 */
class Extra_Content_Page {

	/**
	 * Action name behind admin-post.php.
	 */
	const ACTION = 'bluebranch_chatbot_extra';

	/**
	 * Hooks the form handler into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Carries out one change and returns to the screen.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bluebranch-chatbot' ), '', array( 'response' => 403 ) );
		}

		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

		check_admin_referer( self::ACTION . '_' . $do . '_' . $id );

		$message = 'saved';
		$error   = '';

		switch ( $do ) {
			case 'save':
				$result = Extra_Content::save(
					$id,
					isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'text',
					isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
					// Sanitised in Extra_Content::save() with sanitize_textarea_field().
					isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0
				);

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$id = $result;
				}
				break;
			case 'activate':
			case 'deactivate':
				Extra_Content::set_active( $id, 'activate' === $do );
				break;
			case 'retrain':
				Extra_Content::train( $id, true );
				break;
			case 'delete':
				Extra_Content::delete( $id );
				$message = 'deleted';
				break;
		}

		$args = array( 'page' => Admin::PAGE_EXTRA );

		if ( '' !== $error ) {
			// Kept for one request so the form can show it; never put in the URL.
			set_transient( 'bbchat_extra_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );
			$args['error'] = 1;
		} else {
			$args['message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$failed  = isset( $_GET['error'] );
		// phpcs:enable

		$editing = $edit_id > 0 ? Extra_Content::find( $edit_id ) : null;
		$entries = Extra_Content::all();
		?>
		<div class="wrap bluebranch-chatbot-admin bluebranch-chatbot-admin--extra">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Information that is on no page of the site: opening hours, conditions, background for the chatbot, or documents. Files are converted to text on this site; only that text is sent to the API. Answers based on these entries show no link. Everything entered here can be asked about by any visitor and may be quoted in answers -- do not add confidential or internal information.', 'bluebranch-chatbot' ); ?></p>

			<?php
			Admin::render_missing_key_notice();

			if ( $failed ) {
				$error = get_transient( 'bbchat_extra_error_' . get_current_user_id() );
				delete_transient( 'bbchat_extra_error_' . get_current_user_id() );

				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( is_string( $error ) ? $error : __( 'That did not work.', 'bluebranch-chatbot' ) ) );
			} elseif ( 'saved' === $message ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Saved.', 'bluebranch-chatbot' ) );
			} elseif ( 'deleted' === $message ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Entry deleted.', 'bluebranch-chatbot' ) );
			}

			$this->render_form( $editing );
			$this->render_list( $entries );
			?>
		</div>
		<?php
	}

	/**
	 * The form for a new entry or the one being edited.
	 *
	 * @param array|null $entry Entry being edited.
	 * @return void
	 */
	private function render_form( $entry ) {
		$id       = $entry ? (int) $entry['id'] : 0;
		$kind     = $entry ? (string) $entry['kind'] : 'text';
		$file     = $entry && (int) $entry['attachment_id'] > 0 ? get_attached_file( (int) $entry['attachment_id'] ) : '';
		$types    = Text_Extractor::mime_types();
		$accepted = strtoupper( implode( ', ', array_keys( $types ) ) );
		?>
		<div class="card bluebranch-chatbot-extra__card">
			<h2><?php echo $entry ? esc_html__( 'Edit entry', 'bluebranch-chatbot' ) : esc_html__( 'Add entry', 'bluebranch-chatbot' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bluebranch-chatbot-extra-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="save">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( self::ACTION . '_save_' . $id ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Type', 'bluebranch-chatbot' ); ?></th>
						<td>
							<label><input type="radio" name="kind" value="text"<?php checked( 'text', $kind ); ?>> <?php esc_html_e( 'Text', 'bluebranch-chatbot' ); ?></label>
							&nbsp;
							<label><input type="radio" name="kind" value="file"<?php checked( 'file', $kind ); ?>> <?php esc_html_e( 'File', 'bluebranch-chatbot' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bluebranch-chatbot-extra-title"><?php esc_html_e( 'Title', 'bluebranch-chatbot' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="bluebranch-chatbot-extra-title" name="title" maxlength="255" value="<?php echo esc_attr( $entry ? (string) $entry['title'] : '' ); ?>">
							<p class="description"><?php esc_html_e( 'Tells the chatbot what the text is about. For a file the file name is used when left empty.', 'bluebranch-chatbot' ); ?></p>
						</td>
					</tr>
					<tr data-kind="text">
						<th scope="row"><label for="bluebranch-chatbot-extra-content"><?php esc_html_e( 'Text', 'bluebranch-chatbot' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="8" id="bluebranch-chatbot-extra-content" name="content"><?php echo esc_textarea( $entry ? (string) $entry['content'] : '' ); ?></textarea>
						</td>
					</tr>
					<tr data-kind="file">
						<th scope="row"><?php esc_html_e( 'File', 'bluebranch-chatbot' ); ?></th>
						<td>
							<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $entry ? (string) (int) $entry['attachment_id'] : '0' ); ?>">
							<button type="button" class="button bluebranch-chatbot-extra__select"><?php esc_html_e( 'Choose file', 'bluebranch-chatbot' ); ?></button>
							<code class="bluebranch-chatbot-extra__file"><?php echo esc_html( is_string( $file ) && '' !== $file ? wp_basename( $file ) : '' ); ?></code>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: list of file extensions. */
										__( 'Allowed: %s, up to 20 MB. Scanned PDFs without a text layer cannot be read.', 'bluebranch-chatbot' ),
										$accepted
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( $entry ? __( 'Save and train', 'bluebranch-chatbot' ) : __( 'Add and train', 'bluebranch-chatbot' ), 'primary', 'submit', false ); ?>
				<?php if ( $entry ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_EXTRA ) ); ?>"><?php esc_html_e( 'Cancel', 'bluebranch-chatbot' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The list of entries.
	 *
	 * @param array[] $entries Entries.
	 * @return void
	 */
	private function render_list( array $entries ) {
		$labels = array(
			'pending'  => __( 'waiting', 'bluebranch-chatbot' ),
			'trained'  => __( 'trained', 'bluebranch-chatbot' ),
			'failed'   => __( 'failed', 'bluebranch-chatbot' ),
			'inactive' => __( 'switched off', 'bluebranch-chatbot' ),
		);
		?>
		<h2><?php esc_html_e( 'Entries', 'bluebranch-chatbot' ); ?></h2>
		<table class="widefat striped bluebranch-chatbot-extra__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Title', 'bluebranch-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'bluebranch-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Characters', 'bluebranch-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'bluebranch-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trained', 'bluebranch-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'bluebranch-chatbot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $entries ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No additional content yet.', 'bluebranch-chatbot' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $entry ) : ?>
				<?php
				$id     = (int) $entry['id'];
				$active = (bool) (int) $entry['active'];
				$status = (string) $entry['status'];
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( (string) $entry['title'] ); ?></strong>
						<br><code><?php echo esc_html( Extra_Content::EXTERNAL_PREFIX . $id ); ?></code>
					</td>
					<td>
						<?php
						if ( 'file' === $entry['kind'] ) {
							$path = get_attached_file( (int) $entry['attachment_id'] );
							echo esc_html( is_string( $path ) && '' !== $path ? wp_basename( $path ) : __( 'File missing', 'bluebranch-chatbot' ) );
						} else {
							esc_html_e( 'Text', 'bluebranch-chatbot' );
						}
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $entry['chars'] ) ); ?></td>
					<td>
						<?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?>
						<?php if ( '' !== (string) $entry['last_error'] ) : ?>
							<br><span class="bluebranch-chatbot-warning"><?php echo esc_html( (string) $entry['last_error'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo $entry['trained_at'] ? esc_html( get_date_from_gmt( (string) $entry['trained_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '&ndash;'; ?></td>
					<td class="bluebranch-chatbot-extra__actions">
						<a href="<?php echo esc_url( add_query_arg( 'edit', $id, admin_url( 'admin.php?page=' . Admin::PAGE_EXTRA ) ) ); ?>"><?php esc_html_e( 'Edit', 'bluebranch-chatbot' ); ?></a>
						| <a href="<?php echo esc_url( $this->action_url( $active ? 'deactivate' : 'activate', $id ) ); ?>"><?php echo $active ? esc_html__( 'Switch off', 'bluebranch-chatbot' ) : esc_html__( 'Switch on', 'bluebranch-chatbot' ); ?></a>
						<?php if ( $active && Options::has_api_key() ) : ?>
							| <a href="<?php echo esc_url( $this->action_url( 'retrain', $id ) ); ?>"><?php esc_html_e( 'Train again', 'bluebranch-chatbot' ); ?></a>
						<?php endif; ?>
						| <a class="bluebranch-chatbot-danger" href="<?php echo esc_url( $this->action_url( 'delete', $id ) ); ?>" onclick="return window.confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Delete this entry and remove it from the knowledge base?', 'bluebranch-chatbot' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'bluebranch-chatbot' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A nonce-protected link to one action on one entry.
	 *
	 * @param string $action Action.
	 * @param int    $id     Entry id.
	 * @return string
	 */
	private function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $action,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $action . '_' . $id
		);
	}
}
