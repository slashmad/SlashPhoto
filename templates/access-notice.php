<?php
/**
 * Notice shown instead of a gallery the visitor may not see.
 *
 * Override: copy this file to yourtheme/slashphoto/access-notice.php.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type WP_Post $gallery The gallery.
 *     @type string  $type    "login", "denied", "link", "expired" or "password" (block on another page).
 *     @type array|null $signin Sign-in link form (action, nonce, sent), or null when not offered.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_gallery = $args['gallery'];
$slashphoto_type    = $args['type'] ?? 'denied';
?>
<div class="slashphoto slashphoto-notice slashphoto-notice--<?php echo esc_attr( $slashphoto_type ); ?>">
	<?php if ( 'login' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is only available to invited clients.', 'slashphoto' ); ?></p>
		<p><a class="slashphoto-notice__action wp-element-button" href="<?php echo esc_url( wp_login_url( get_permalink( $slashphoto_gallery ) ) ); ?>"><?php esc_html_e( 'Log in to view the gallery', 'slashphoto' ); ?></a></p>
		<?php if ( ! empty( $args['signin'] ) ) : ?>
			<?php $slashphoto_signin_id = 'slashphoto-signin-' . $slashphoto_gallery->ID; ?>
			<?php if ( $args['signin']['sent'] ) : ?>
				<p class="slashphoto-signin__sent" role="status"><?php esc_html_e( 'If that address belongs to an invited client, a sign-in link is on its way. It works once, for one hour.', 'slashphoto' ); ?></p>
			<?php else : ?>
				<form class="slashphoto-terms slashphoto-signin" method="post" action="<?php echo esc_url( $args['signin']['action'] ); ?>">
					<p class="slashphoto-field">
						<label for="<?php echo esc_attr( $slashphoto_signin_id ); ?>"><?php esc_html_e( 'No password? Get a sign-in link by email', 'slashphoto' ); ?></label>
						<input type="email" id="<?php echo esc_attr( $slashphoto_signin_id ); ?>" name="email" autocomplete="email" maxlength="200" required>
					</p>
					<input type="hidden" name="action" value="<?php echo esc_attr( \SlashPhoto\Access\SignInLinks::ACTION ); ?>">
					<input type="hidden" name="gallery" value="<?php echo esc_attr( (string) $slashphoto_gallery->ID ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['signin']['nonce'] ); ?>">
					<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Email me a link', 'slashphoto' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	<?php elseif ( 'link' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is private. Please use the link you received from the photographer.', 'slashphoto' ); ?></p>
	<?php elseif ( 'password' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is password protected.', 'slashphoto' ); ?></p>
		<p><a class="slashphoto-notice__action wp-element-button" href="<?php echo esc_url( get_permalink( $slashphoto_gallery ) ); ?>"><?php esc_html_e( 'Open the gallery', 'slashphoto' ); ?></a></p>
	<?php elseif ( 'expired' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is no longer available. Please contact the photographer if you need access.', 'slashphoto' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your account does not have access to this gallery. Please contact the photographer if you think this is a mistake.', 'slashphoto' ); ?></p>
	<?php endif; ?>
</div>
