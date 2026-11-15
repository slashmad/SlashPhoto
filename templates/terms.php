<?php
/**
 * Terms the visitor must accept before the images are shown.
 *
 * The acceptance is remembered in a cookie on the visitor's device and
 * logged with the date and the account or email address. Override: copy
 * this file to yourtheme/slashphoto/terms.php and keep the form fields
 * (action, gallery, agree, email, _wpnonce).
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type WP_Post $gallery The gallery.
 *     @type string  $terms   Terms text (HTML allowed by wp_kses_post).
 *     @type string  $align   "wide", "full" or "".
 *     @type string  $action  Form action URL.
 *     @type string  $nonce   Nonce.
 *     @type bool    $ask_email Whether to ask for an email address.
 *     @type bool    $invalid   Whether the last attempt was refused.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_gallery = $args['gallery'];
// Forms read best at the text width, so the gallery's wide alignment is not used.
$slashphoto_classes = 'slashphoto slashphoto-terms';
$slashphoto_field   = 'slashphoto-agree-' . $slashphoto_gallery->ID;
?>
<form class="<?php echo esc_attr( $slashphoto_classes ); ?>" method="post" action="<?php echo esc_url( $args['action'] ); ?>">
	<h2 class="slashphoto-terms__title"><?php esc_html_e( 'Before you view the photos', 'slashphoto' ); ?></h2>
	<div class="slashphoto-terms__text"><?php echo wp_kses_post( wpautop( $args['terms'] ) ); ?></div>
	<?php if ( ! empty( $args['invalid'] ) ) : ?>
		<p class="slashphoto-terms__error" role="alert"><?php esc_html_e( 'Enter a valid email address to accept the terms.', 'slashphoto' ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $args['ask_email'] ) ) : ?>
		<p class="slashphoto-field">
			<label for="<?php echo esc_attr( $slashphoto_field ); ?>-email"><?php esc_html_e( 'Your email address', 'slashphoto' ); ?></label>
			<input type="email" id="<?php echo esc_attr( $slashphoto_field ); ?>-email" name="email" autocomplete="email" maxlength="200" required>
		</p>
	<?php endif; ?>
	<p class="slashphoto-terms__agree">
		<input type="checkbox" id="<?php echo esc_attr( $slashphoto_field ); ?>" name="agree" value="1" required>
		<label for="<?php echo esc_attr( $slashphoto_field ); ?>"><?php esc_html_e( 'I have read and accept these terms', 'slashphoto' ); ?></label>
	</p>
	<input type="hidden" name="action" value="<?php echo esc_attr( \SlashPhoto\Access\Terms::ACTION ); ?>">
	<input type="hidden" name="gallery" value="<?php echo esc_attr( (string) $slashphoto_gallery->ID ); ?>">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['nonce'] ); ?>">
	<p><button type="submit" class="slashphoto-terms__submit wp-element-button"><?php esc_html_e( 'Show the photos', 'slashphoto' ); ?></button></p>
	<p class="slashphoto-terms__privacy"><?php esc_html_e( 'This browser remembers your acceptance for 30 days. The photographer keeps a record of it with the date and your account or email address. No IP address or browser details are stored.', 'slashphoto' ); ?></p>
</form>
