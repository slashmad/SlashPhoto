<?php
/**
 * Send a gallery link using its existing access rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\SignInLinks;
use SlashPhoto\Gallery\PostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Lets gallery editors email client links. */
final class GalleryInvitations {

	private const ACTION     = 'slashphoto_invite';
	private const NOTE_LIMIT = 1000;

	/**
	 * Uses the same access rules as the public gallery.
	 *
	 * @param Access $access Inherited access rules.
	 */
	public function __construct( private Access $access ) {}

	/** Registers the authenticated form handler. */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'submit' ] );
	}

	/**
	 * Renders the invitation form.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function render( WP_Post $gallery ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice code set by the form redirect.
		$result   = isset( $_GET['invite'] ) ? sanitize_key( wp_unslash( $_GET['invite'] ) ) : '';
		$messages = [
			'sent'        => __( 'WordPress accepted the invitation for sending.', 'slashphoto' ),
			'mail_error'  => __( 'WordPress could not send this invitation. Check the site’s email settings and try again.', 'slashphoto' ),
			'closed'      => __( 'Publish the gallery and its parent galleries and check their expiry dates before sending an invitation.', 'slashphoto' ),
			'email'       => __( 'Enter a valid email address.', 'slashphoto' ),
			'account'     => __( 'This address needs a WordPress account that matches the gallery’s access settings.', 'slashphoto' ),
			'parent'      => __( 'An editor of the parent gallery must send invitations that use its secret link.', 'slashphoto' ),
			'links'       => __( 'This gallery requires multiple secret links. Share the links individually from each gallery’s settings.', 'slashphoto' ),
			'missing_key' => __( 'Open the gallery’s access settings to create a secret link before sending an invitation.', 'slashphoto' ),
		];
		?>
		<section class="slashphoto-workspace__panel">
			<h2><?php esc_html_e( 'Invite a client', 'slashphoto' ); ?></h2>
			<?php if ( isset( $messages[ $result ] ) ) : ?>
				<div class="notice inline <?php echo 'sent' === $result ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $messages[ $result ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->access->link_only( $gallery ) ) : ?>
				<p><?php esc_html_e( 'Anyone with the secret link can use it. A parent gallery’s link can also open that gallery and other galleries that inherit its link.', 'slashphoto' ); ?></p>
			<?php endif; ?>
			<?php if ( $this->access->password_source( $gallery ) ) : ?>
				<p><?php esc_html_e( 'Share the gallery password separately.', 'slashphoto' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="gallery_id" value="<?php echo esc_attr( $gallery->ID ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $gallery->ID ); ?>
				<p><label for="slashphoto-invite-email"><?php esc_html_e( 'Client email', 'slashphoto' ); ?></label><br>
					<input class="regular-text" type="email" id="slashphoto-invite-email" name="email" maxlength="254" required>
				</p>
				<p><label for="slashphoto-invite-note"><?php esc_html_e( 'Message (optional)', 'slashphoto' ); ?></label><br>
					<textarea class="large-text" id="slashphoto-invite-note" name="note" rows="4" maxlength="<?php echo esc_attr( self::NOTE_LIMIT ); ?>"></textarea>
				</p>
				<?php submit_button( __( 'Send invitation', 'slashphoto' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	/** Processes a deliberate send action and redirects without exposing mail content. */
	public function submit(): void {
		$id = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$gallery = $id ? get_post( $id ) : null;
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type || ! $this->access->is_editor( $gallery ) ) {
			wp_die( esc_html__( 'You cannot invite clients to this gallery.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		$email  = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = $this->send( $gallery, $email, $note );
		wp_safe_redirect( add_query_arg( 'invite', $result, GalleryWorkspace::url( $id, 'invite' ) ), 303 );
		exit;
	}

	/**
	 * Sends one email using the access already configured on the gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @param string  $email   Destination address.
	 * @param string  $note    Optional text from the photographer.
	 * @return string Form result code.
	 */
	public function send( WP_Post $gallery, string $email, string $note = '' ): string {
		if ( PostType::POST_TYPE !== $gallery->post_type || ! $this->access->is_editor( $gallery ) ) {
			return 'closed';
		}
		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			return 'email';
		}
		$expiry = $this->access->expires_at( $gallery );
		if ( null !== $expiry && time() > $expiry ) {
			return 'closed';
		}
		$sources = [];
		foreach ( $this->access->chain( $gallery ) as $post ) {
			if ( 'publish' !== $post->post_status ) {
				return 'closed';
			}
			if ( Access::MODE_LINK === Access::mode( $post ) ) {
				$sources[] = $post;
			}
		}
		$requires_account = ! $this->access->account_allowed( $gallery, 0 );
		$recipient        = null;
		if ( $requires_account ) {
			$recipient = get_user_by( 'email', $email );
			if ( ! $recipient || ! $this->access->account_allowed( $gallery, $recipient->ID ) ) {
				return 'account';
			}
		}
		$url = get_permalink( $gallery );
		if ( count( $sources ) > 1 ) {
			return 'links';
		}
		if ( $sources ) {
			if ( ! $this->access->is_editor( $sources[0] ) ) {
				return 'parent';
			}
			$key = Access::link_key( $sources[0] );
			if ( '' === $key ) {
				return 'missing_key';
			}
			$url = add_query_arg( 'key', $key, $url );
		}
		$title = trim( wp_strip_all_tags( wp_specialchars_decode( $gallery->post_title, ENT_QUOTES ), true ) );
		/* translators: %s: Gallery title. */
		$subject = sprintf( __( 'Gallery invitation: %s', 'slashphoto' ), $title );
		/* translators: %s: Gallery title. */
		$body = [ sprintf( __( 'You are invited to view %s.', 'slashphoto' ), $title ), $url ];
		if ( $requires_account ) {
			$signin = SignInLinks::enabled() && $recipient ? SignInLinks::create( $recipient, $gallery, SignInLinks::INVITE_TTL ) : '';
			$body[] = '' !== $signin
				? __( 'Sign in without a password with this link. It works once, within seven days:', 'slashphoto' ) . "\n" . $signin
				: __( 'Sign in with the WordPress account registered to this email address.', 'slashphoto' );
		}
		if ( $this->access->password_source( $gallery ) ) {
			$body[] = __( 'Use the gallery password shared with you separately.', 'slashphoto' );
		}
		$note = mb_substr( sanitize_textarea_field( $note ), 0, self::NOTE_LIMIT );
		if ( '' !== $note ) {
			$body[] = $note;
		}
		return wp_mail( $email, $subject, implode( "\n\n", $body ) ) ? 'sent' : 'mail_error';
	}
}
