<?php
/**
 * A customer's order, opened through the private link in their email.
 *
 * Override: copy this file to yourtheme/slashphoto/order.php.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type array|null $order        The order, or null when the link is not valid.
 *     @type array[]    $items        Lines; download lines have a "download" URL once paid.
 *     @type string     $status       Status for people.
 *     @type string     $instructions Payment details while waiting for payment (plain text).
 *     @type callable   $money        Formats an amount in the order's currency.
 *     @type bool       $has_download Whether the order contains downloads.
 *     @type bool       $open         Whether downloads are available now.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_order = $args['order'] ?? null;
if ( ! $slashphoto_order ) : ?>
	<div class="slashphoto slashphoto-order">
		<p><?php esc_html_e( 'This order link is not valid. Use the link in your order email, or contact us.', 'slashphoto' ); ?></p>
	</div>
	<?php
	return;
endif;

$slashphoto_items = $args['items'];
$slashphoto_money = $args['money'];
$slashphoto_until = $slashphoto_order['downloads_until'] ? strtotime( $slashphoto_order['downloads_until'] . ' UTC' ) : 0;
?>
<div class="slashphoto slashphoto-order" data-slashphoto-order="<?php echo esc_attr( (string) $slashphoto_order['id'] ); ?>" data-slashphoto-order-status="<?php echo esc_attr( (string) $slashphoto_order['status'] ); ?>">
	<h2 class="slashphoto-order__title">
		<?php
		/* translators: %d: order number */
		echo esc_html( sprintf( __( 'Order %d', 'slashphoto' ), $slashphoto_order['id'] ) );
		?>
	</h2>
	<p class="slashphoto-order__status"><strong><?php esc_html_e( 'Status:', 'slashphoto' ); ?></strong> <?php echo esc_html( $args['status'] ); ?></p>

	<?php if ( '' !== trim( (string) $args['instructions'] ) ) : ?>
		<div class="slashphoto-order__instructions"><?php echo wp_kses_post( wpautop( esc_html( (string) $args['instructions'] ) ) ); ?></div>
	<?php endif; ?>

	<?php if ( $args['has_download'] && $args['open'] ) : ?>
		<p class="slashphoto-order__downloads">
			<?php
			echo esc_html(
				$slashphoto_until
					/* translators: %s: date */
					? sprintf( __( 'Your downloads are ready. They are available until %s.', 'slashphoto' ), wp_date( (string) get_option( 'date_format' ), $slashphoto_until ) )
					: __( 'Your downloads are ready.', 'slashphoto' )
			);
			?>
		</p>
	<?php elseif ( $args['has_download'] && in_array( $slashphoto_order['status'], [ 'paid', 'completed' ], true ) ) : ?>
		<p class="slashphoto-order__downloads"><?php esc_html_e( 'The download period for this order has ended. Contact us if you need the files again.', 'slashphoto' ); ?></p>
	<?php elseif ( $args['has_download'] ) : ?>
		<p class="slashphoto-order__downloads"><?php esc_html_e( 'Downloads appear here when the payment has arrived.', 'slashphoto' ); ?></p>
	<?php endif; ?>

	<ul class="slashphoto-order__lines">
		<?php foreach ( $slashphoto_items as $slashphoto_item ) : ?>
			<li class="slashphoto-order__line<?php echo $slashphoto_item['parent_id'] ? ' slashphoto-order__line--in-package' : ''; ?>">
				<?php echo wp_get_attachment_image( $slashphoto_item['image_id'], 'thumbnail', false, [ 'class' => 'slashphoto-order__thumb' ] ); ?>
				<span class="slashphoto-order__what">
					<span class="slashphoto-order__name"><?php echo esc_html( $slashphoto_item['name'] ); ?></span>
					<span class="slashphoto-order__file"><?php echo esc_html( $slashphoto_item['file_label'] ); ?></span>
				</span>
				<span class="slashphoto-order__qty"><?php echo esc_html( $slashphoto_item['parent_id'] ? '' : '× ' . $slashphoto_item['quantity'] ); ?></span>
				<span class="slashphoto-order__price"><?php echo esc_html( $slashphoto_item['parent_id'] ? __( 'In package', 'slashphoto' ) : $slashphoto_money( $slashphoto_item['total'] ) ); ?></span>
				<?php if ( ! empty( $slashphoto_item['download'] ) ) : ?>
					<a class="slashphoto-order__download wp-element-button" href="<?php echo esc_url( $slashphoto_item['download'] ); ?>" download><?php esc_html_e( 'Download', 'slashphoto' ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<dl class="slashphoto-order__totals">
		<?php if ( $slashphoto_order['discount'] > 0 ) : ?>
			<dt>
				<?php
				echo esc_html(
					'' !== (string) $slashphoto_order['coupon']
						/* translators: %s: discount code */
						? sprintf( __( 'Discount (%s)', 'slashphoto' ), $slashphoto_order['coupon'] )
						: __( 'Discount', 'slashphoto' )
				);
				?>
			</dt>
			<dd><?php echo esc_html( '−' . $slashphoto_money( $slashphoto_order['discount'] ) ); ?></dd>
		<?php endif; ?>
		<?php if ( $slashphoto_order['shipping'] > 0 || $slashphoto_order['address'] ) : ?>
			<dt><?php esc_html_e( 'Shipping', 'slashphoto' ); ?></dt>
			<dd><?php echo esc_html( $slashphoto_money( $slashphoto_order['shipping'] ) ); ?></dd>
		<?php endif; ?>
		<?php if ( $slashphoto_order['tax'] > 0 ) : ?>
			<dt><?php echo esc_html( $slashphoto_order['prices_include_tax'] ? __( 'Including VAT', 'slashphoto' ) : __( 'VAT', 'slashphoto' ) ); ?></dt>
			<dd><?php echo esc_html( $slashphoto_money( $slashphoto_order['tax'] ) ); ?></dd>
		<?php endif; ?>
		<dt class="slashphoto-order__total"><?php esc_html_e( 'Total', 'slashphoto' ); ?></dt>
		<dd class="slashphoto-order__total"><?php echo esc_html( $slashphoto_money( $slashphoto_order['total'] ) ); ?></dd>
		<?php if ( (int) ( $slashphoto_order['gift_card_amount'] ?? 0 ) > 0 ) : ?>
			<dt>
				<?php
				/* translators: %s: last four characters of the gift card code */
				echo esc_html( sprintf( __( 'Gift card (…%s)', 'slashphoto' ), $slashphoto_order['gift_card_hint'] ) );
				?>
			</dt>
			<dd><?php echo esc_html( '−' . $slashphoto_money( (int) $slashphoto_order['gift_card_amount'] ) ); ?></dd>
			<dt class="slashphoto-order__total"><?php esc_html_e( 'To pay', 'slashphoto' ); ?></dt>
			<dd class="slashphoto-order__total"><?php echo esc_html( $slashphoto_money( \SlashPhoto\Shop\GiftCards::due( $slashphoto_order ) ) ); ?></dd>
		<?php endif; ?>
	</dl>

	<?php $slashphoto_codes = in_array( $slashphoto_order['status'], [ 'paid', 'completed' ], true ) ? \SlashPhoto\Shop\GiftCards::codes( $slashphoto_order ) : []; ?>
	<?php if ( $slashphoto_codes ) : ?>
		<h3 class="slashphoto-order__subtitle"><?php esc_html_e( 'Your gift cards', 'slashphoto' ); ?></h3>
		<ul class="slashphoto-order__gift-cards">
			<?php foreach ( $slashphoto_codes as $slashphoto_code ) : ?>
				<li>
					<code><?php echo esc_html( $slashphoto_code['code'] ); ?></code>
					<?php
					echo esc_html(
						' – ' . $slashphoto_money( $slashphoto_code['card']['amount'] )
						. ( null !== $slashphoto_code['card']['expires_at']
							/* translators: %s: date */
							? ', ' . sprintf( __( 'valid until %s', 'slashphoto' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $slashphoto_code['card']['expires_at'] . ' UTC' ) ) )
							: '' )
						/* translators: %s: amount left */
						. ( $slashphoto_code['card']['balance'] < $slashphoto_code['card']['amount'] ? ', ' . sprintf( __( '%s left', 'slashphoto' ), $slashphoto_money( $slashphoto_code['card']['balance'] ) ) : '' )
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $slashphoto_order['address'] ) : ?>
		<h3 class="slashphoto-order__subtitle"><?php esc_html_e( 'Delivery address', 'slashphoto' ); ?></h3>
		<p class="slashphoto-order__address"><?php echo implode( '<br>', array_map( 'esc_html', \SlashPhoto\Shop\Emails::address_lines( $slashphoto_order ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line. ?></p>
	<?php endif; ?>

	<p class="slashphoto-order__receipt">
		<?php
		$slashphoto_doc = \SlashPhoto\Shop\Receipts::type( $slashphoto_order );
		if ( 'confirmation' !== $slashphoto_doc ) :
			?>
			<a href="<?php echo esc_url( \SlashPhoto\Shop\Receipts::url( $slashphoto_order ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( 'receipt' === $slashphoto_doc ? __( 'Receipt (print or save as PDF)', 'slashphoto' ) : __( 'Invoice (print or save as PDF)', 'slashphoto' ) ); ?></a>
		<?php endif; ?>
	</p>

	<p class="slashphoto-order__privacy"><?php esc_html_e( 'Keep the link to this page to yourself: anyone who has it can see the order and download the files.', 'slashphoto' ); ?></p>
</div>
<?php
// The order is placed: empty the cart in this browser.
if ( in_array( $slashphoto_order['status'], [ 'on-hold', 'paid', 'completed' ], true ) ) {
	wp_print_inline_script_tag( 'try{window.localStorage.removeItem("slashphoto-cart");window.localStorage.removeItem("slashphoto-coupon")}catch(e){}' );
}
