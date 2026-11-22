<?php
/**
 * Gift cards for sale: one button per amount adds a card to the cart.
 *
 * Override: copy this file to yourtheme/slashphoto/gift-cards.php and keep
 * the data-slashphoto-* attributes.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type array[] $cards    { amount (minor units), label, name }.
 *     @type int     $months   Validity in months, 0 for no end date.
 *     @type string  $cart_url Cart page, or ''.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_months = (int) ( $args['months'] ?? 0 );
?>
<div class="slashphoto slashphoto-gift-cards" data-slashphoto-gift-cards>
	<ul class="slashphoto-gift-cards__amounts">
		<?php foreach ( $args['cards'] as $slashphoto_card ) : ?>
			<li>
				<button type="button" class="wp-element-button" data-slashphoto-gift-card="<?php echo esc_attr( (string) $slashphoto_card['amount'] ); ?>" data-name="<?php echo esc_attr( $slashphoto_card['name'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'Add %s to cart', 'slashphoto' ), $slashphoto_card['name'] ) ); ?>"><?php echo esc_html( $slashphoto_card['label'] ); ?></button>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="slashphoto-gift-cards__terms">
		<?php
		echo esc_html(
			$slashphoto_months
				/* translators: %d: number of months */
				? sprintf( _n( 'The code arrives by email when the payment is in, and can be used for photos, prints and downloads for %d month.', 'The code arrives by email when the payment is in, and can be used for photos, prints and downloads for %d months.', $slashphoto_months, 'slashphoto' ), $slashphoto_months )
				: __( 'The code arrives by email when the payment is in, and can be used for photos, prints and downloads.', 'slashphoto' )
		);
		?>
	</p>
	<p class="slashphoto-gift-cards__status" data-slashphoto-gift-card-status role="status"></p>
	<?php if ( ! empty( $args['cart_url'] ) ) : ?>
		<p><a class="wp-element-button" href="<?php echo esc_url( $args['cart_url'] ); ?>" data-slashphoto-gift-card-link hidden><?php esc_html_e( 'Go to cart', 'slashphoto' ); ?></a></p>
	<?php endif; ?>
</div>
