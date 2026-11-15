<?php
/**
 * Terms the visitor must accept before the images are shown.
 *
 * Acceptance is remembered only in a cookie on the visitor's device.
 * Override: copy this file to yourtheme/slashphoto/terms.php and keep the
 * form fields (action, gallery, agree, _wpnonce).
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type WP_Post $gallery The gallery.
 *     @type string  $terms   Terms text (HTML allowed by wp_kses_post).
 *     @type string  $align   "wide", "full" or "".
 *     @type string  $action  Form action URL.
 *     @type string  $nonce   Nonce.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_gallery = $args['gallery'];
$slashphoto_classes = 'slashphoto slashphoto-terms' . ( ! empty( $args['align'] ) ? ' align' . $args['align'] : '' );
$slashphoto_field   = 'slashphoto-agree-' . $slashphoto_gallery->ID;
?>
<form class="<?php echo esc_attr( $slashphoto_classes ); ?>" method="post" action="<?php echo esc_url( $args['action'] ); ?>">
	<h2 class="slashphoto-terms__title"><?php esc_html_e( 'Before you view the photos', 'slashphoto' ); ?></h2>
	<div class="slashphoto-terms__text"><?php echo wp_kses_post( wpautop( $args['terms'] ) ); ?></div>
	<p class="slashphoto-terms__agree">
		<input type="checkbox" id="<?php echo esc_attr( $slashphoto_field ); ?>" name="agree" value="1" required>
		<label for="<?php echo esc_attr( $slashphoto_field ); ?>"><?php esc_html_e( 'I have read and accept these terms', 'slashphoto' ); ?></label>
	</p>
	<input type="hidden" name="action" value="<?php echo esc_attr( \SlashPhoto\Access\Terms::ACTION ); ?>">
	<input type="hidden" name="gallery" value="<?php echo esc_attr( (string) $slashphoto_gallery->ID ); ?>">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['nonce'] ); ?>">
	<p><button type="submit" class="slashphoto-terms__submit wp-element-button"><?php esc_html_e( 'Show the photos', 'slashphoto' ); ?></button></p>
	<p class="slashphoto-terms__privacy"><?php esc_html_e( 'Your acceptance is remembered only in a cookie in this browser. Nothing about you is stored on this site.', 'slashphoto' ); ?></p>
</form>
