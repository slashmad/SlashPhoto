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
 *     @type string  $type    "login", "denied", "expired" or "password" (block on another page).
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
	<?php elseif ( 'password' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is password protected.', 'slashphoto' ); ?></p>
		<p><a class="slashphoto-notice__action wp-element-button" href="<?php echo esc_url( get_permalink( $slashphoto_gallery ) ); ?>"><?php esc_html_e( 'Open the gallery', 'slashphoto' ); ?></a></p>
	<?php elseif ( 'expired' === $slashphoto_type ) : ?>
		<p><?php esc_html_e( 'This gallery is no longer available. Please contact the photographer if you need access.', 'slashphoto' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your account does not have access to this gallery. Please contact the photographer if you think this is a mistake.', 'slashphoto' ); ?></p>
	<?php endif; ?>
</div>
