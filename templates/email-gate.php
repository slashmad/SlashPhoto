<?php
/**
 * Email address the visitor gives before the photos are shown.
 *
 * Override: copy this file to yourtheme/slashphoto/email-gate.php and keep
 * the form fields (action, gallery, email, consent, _wpnonce).
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type WP_Post $gallery The gallery.
 *     @type string  $align   "wide", "full" or "".
 *     @type string  $action  Form action URL.
 *     @type string  $nonce   Nonce.
 *     @type bool    $invalid Whether the last attempt was refused.
 *     @type int     $days    How long the address is kept, 0 for no limit.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_gallery = $args['gallery'];
// Forms read best at the text width, so the gallery's wide alignment is not used.
$slashphoto_classes = 'slashphoto slashphoto-terms slashphoto-email-gate';
$slashphoto_id      = 'slashphoto-gate-' . $slashphoto_gallery->ID;
$slashphoto_days    = (int) ( $args['days'] ?? 0 );
?>
<form class="<?php echo esc_attr( $slashphoto_classes ); ?>" method="post" action="<?php echo esc_url( $args['action'] ); ?>">
	<h2 class="slashphoto-terms__title"><?php esc_html_e( 'Enter your email address to see the photos', 'slashphoto' ); ?></h2>
	<?php if ( ! empty( $args['invalid'] ) ) : ?>
		<p class="slashphoto-terms__error" role="alert"><?php esc_html_e( 'Enter a valid email address and tick the box.', 'slashphoto' ); ?></p>
	<?php endif; ?>
	<p class="slashphoto-field">
		<label for="<?php echo esc_attr( $slashphoto_id ); ?>-email"><?php esc_html_e( 'Email', 'slashphoto' ); ?></label>
		<input type="email" id="<?php echo esc_attr( $slashphoto_id ); ?>-email" name="email" autocomplete="email" maxlength="200" required>
	</p>
	<p class="slashphoto-terms__agree">
		<input type="checkbox" id="<?php echo esc_attr( $slashphoto_id ); ?>-consent" name="consent" value="1" required>
		<label for="<?php echo esc_attr( $slashphoto_id ); ?>-consent">
			<?php
			echo esc_html(
				$slashphoto_days
					/* translators: %d: number of days */
					? sprintf( __( 'I agree that the photographer keeps my email address and the date with this gallery for %d days.', 'slashphoto' ), $slashphoto_days )
					: __( 'I agree that the photographer keeps my email address and the date with this gallery.', 'slashphoto' )
			);
			?>
		</label>
	</p>
	<input type="hidden" name="action" value="<?php echo esc_attr( \SlashPhoto\Access\EmailGate::ACTION ); ?>">
	<input type="hidden" name="gallery" value="<?php echo esc_attr( (string) $slashphoto_gallery->ID ); ?>">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['nonce'] ); ?>">
	<p><button type="submit" class="slashphoto-terms__submit wp-element-button"><?php esc_html_e( 'Show the photos', 'slashphoto' ); ?></button></p>
	<p class="slashphoto-terms__privacy"><?php esc_html_e( 'This browser remembers it for 30 days. No IP address or browser details are stored.', 'slashphoto' ); ?></p>
</form>
