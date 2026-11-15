<?php
/**
 * Access settings UI for galleries.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\GalleryMeta;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Media\Watermark;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Block editor sidebar panel, plus a meta box for the classic editor.
 */
final class AccessSettings {

	public const SCRIPT = 'slashphoto-editor-access';
	public const NONCE  = 'slashphoto_access_nonce';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
		add_action( 'add_meta_boxes_' . PostType::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . PostType::POST_TYPE, [ $this, 'save_meta_box' ] );
	}

	/**
	 * Loads the sidebar panel for galleries.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || PostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT,
			SLASHPHOTO_URL . 'assets/js/editor-access.js',
			[ 'wp-api-fetch', 'wp-components', 'wp-core-data', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ],
			Assets::version( 'assets/js/editor-access.js' ),
			true
		);
		wp_set_script_translations( self::SCRIPT, 'slashphoto', SLASHPHOTO_DIR . 'languages' );
	}

	/**
	 * Adds the classic-editor meta box (hidden in the block editor).
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'slashphoto-access',
			__( 'Access', 'slashphoto' ),
			[ $this, 'render_meta_box' ],
			PostType::POST_TYPE,
			'side',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Renders the classic-editor meta box.
	 *
	 * @param WP_Post $post Gallery.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$mode    = Access::mode( $post );
		$clients = array_filter( array_map( 'get_userdata', Access::clients( $post ) ) );
		$logins  = implode( ', ', wp_list_pluck( $clients, 'user_login' ) );
		wp_nonce_field( 'slashphoto_access_' . $post->ID, self::NONCE );
		?>
		<p>
			<label for="slashphoto-access-mode"><?php esc_html_e( 'Who can view this gallery', 'slashphoto' ); ?></label>
			<select id="slashphoto-access-mode" name="slashphoto_access" class="widefat">
				<option value="public" <?php selected( $mode, Access::MODE_PUBLIC ); ?>><?php esc_html_e( 'Everyone with the link', 'slashphoto' ); ?></option>
				<option value="link" <?php selected( $mode, Access::MODE_LINK ); ?>><?php esc_html_e( 'Only people with the secret link', 'slashphoto' ); ?></option>
				<option value="clients" <?php selected( $mode, Access::MODE_CLIENTS ); ?>><?php esc_html_e( 'Only selected clients (log-in required)', 'slashphoto' ); ?></option>
			</select>
		</p>
		<?php if ( Access::MODE_LINK === $mode ) : ?>
			<p>
				<label for="slashphoto-share-url"><?php esc_html_e( 'Secret link', 'slashphoto' ); ?></label>
				<input type="text" id="slashphoto-share-url" class="widefat" readonly value="<?php echo esc_attr( Access::share_url( $post ) ); ?>" onfocus="this.select()">
				<label><input type="checkbox" name="slashphoto_new_link" value="1"> <?php esc_html_e( 'Create a new link (the current one stops working)', 'slashphoto' ); ?></label>
			</p>
		<?php endif; ?>
		<p>
			<label for="slashphoto-watermark"><?php esc_html_e( 'Watermark', 'slashphoto' ); ?></label>
			<select id="slashphoto-watermark" name="slashphoto_watermark" class="widefat">
				<?php $slashphoto_wm = (string) get_post_meta( $post->ID, Watermark::META, true ); ?>
				<option value="" <?php selected( $slashphoto_wm, '' ); ?>><?php esc_html_e( 'Site default or parent gallery', 'slashphoto' ); ?></option>
				<option value="on" <?php selected( $slashphoto_wm, 'on' ); ?>><?php esc_html_e( 'On', 'slashphoto' ); ?></option>
				<option value="off" <?php selected( $slashphoto_wm, 'off' ); ?>><?php esc_html_e( 'Off', 'slashphoto' ); ?></option>
			</select>
		</p>
		<p>
			<label for="slashphoto-clients"><?php esc_html_e( 'Clients (user names or emails, comma separated)', 'slashphoto' ); ?></label>
			<input type="text" id="slashphoto-clients" name="slashphoto_clients" class="widefat" value="<?php echo esc_attr( $logins ); ?>">
		</p>
		<p>
			<label for="slashphoto-expires"><?php esc_html_e( 'Available until', 'slashphoto' ); ?></label>
			<input type="date" id="slashphoto-expires" name="slashphoto_expires" class="widefat" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Access::META_EXPIRES, true ) ); ?>">
		</p>
		<p>
			<label for="slashphoto-terms"><?php esc_html_e( 'Terms to accept before viewing', 'slashphoto' ); ?></label>
			<textarea id="slashphoto-terms" name="slashphoto_terms" class="widefat" rows="4"><?php echo esc_textarea( Terms::text( $post ) ); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e( 'Set a password under Visibility. Sub-galleries also follow the rules of the galleries above them.', 'slashphoto' ); ?></p>
		<?php
	}

	/**
	 * Saves the classic-editor meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, 'slashphoto_access_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = isset( $_POST['slashphoto_access'] ) ? sanitize_key( wp_unslash( $_POST['slashphoto_access'] ) ) : '';
		$mode = in_array( $mode, [ Access::MODE_CLIENTS, Access::MODE_LINK ], true ) ? $mode : Access::MODE_PUBLIC;
		update_post_meta( $post_id, Access::META_MODE, $mode );
		if ( Access::MODE_LINK === $mode && ! empty( $_POST['slashphoto_new_link'] ) ) {
			Access::rotate_link_key( get_post( $post_id ) );
		}

		$watermark = isset( $_POST['slashphoto_watermark'] ) ? sanitize_key( wp_unslash( $_POST['slashphoto_watermark'] ) ) : '';
		update_post_meta( $post_id, Watermark::META, in_array( $watermark, [ 'on', 'off' ], true ) ? $watermark : '' );

		$ids = [];
		$raw = isset( $_POST['slashphoto_clients'] ) ? sanitize_text_field( wp_unslash( $_POST['slashphoto_clients'] ) ) : '';
		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $login ) {
			$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
			if ( $user ) {
				$ids[] = $user->ID;
			}
		}
		update_post_meta( $post_id, Access::META_CLIENTS, GalleryMeta::sanitize_clients( $ids ) );

		$expires = isset( $_POST['slashphoto_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['slashphoto_expires'] ) ) : '';
		update_post_meta( $post_id, Access::META_EXPIRES, GalleryMeta::sanitize_date( $expires ) );

		$terms = isset( $_POST['slashphoto_terms'] ) ? wp_kses_post( wp_unslash( $_POST['slashphoto_terms'] ) ) : '';
		update_post_meta( $post_id, Terms::META_TERMS, $terms );
	}
}
