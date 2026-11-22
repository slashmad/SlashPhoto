<?php
/**
 * Printable receipt or invoice: a standalone page outside the theme.
 *
 * Override: copy this file to yourtheme/slashphoto/receipt.php.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type array    $order        The order.
 *     @type array[]  $items        Order lines; photos of a package have parent_id set.
 *     @type string   $type         "receipt", "invoice" or "confirmation".
 *     @type string   $title        Document title with number.
 *     @type string   $seller       Business name.
 *     @type string   $details      Address and registration (plain text).
 *     @type string   $note         Note at the bottom (plain text).
 *     @type string   $payment      Payment method.
 *     @type string   $instructions Payment details for invoices (plain text).
 *     @type callable $money        Formats an amount.
 *     @type callable $date         Formats a UTC date.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_order = $args['order'];
$slashphoto_money = $args['money'];
$slashphoto_date  = $args['date'];
$slashphoto_rate  = number_format_i18n( (float) $slashphoto_order['tax_rate'], floor( (float) $slashphoto_order['tax_rate'] ) === (float) $slashphoto_order['tax_rate'] ? 0 : 1 );
// VAT charged for another country's rate names that country (VAT by country).
$slashphoto_vat_in = '' !== (string) ( $slashphoto_order['tax_country'] ?? '' ) ? ' · ' . \SlashPhoto\Shop\Countries::name( (string) $slashphoto_order['tax_country'] ) : '';
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $args['title'] . ' – ' . $args['seller'] ); ?></title>
<style>
	:root { color-scheme: light; }
	body { margin: 0; background: #f3f3f3; color: #1d1d1d; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	.sheet { box-sizing: border-box; max-width: 800px; margin: 24px auto; padding: 48px; background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / 0.12); }
	header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 24px; margin-bottom: 32px; }
	h1 { margin: 0 0 4px; font-size: 28px; font-weight: 600; }
	.seller { text-align: end; white-space: pre-line; }
	.seller strong { display: block; font-size: 17px; }
	.meta { display: grid; grid-template-columns: max-content 1fr; gap: 2px 16px; margin: 0; }
	.meta dt { color: #666; }
	.meta dd { margin: 0; }
	.parties { display: flex; flex-wrap: wrap; gap: 32px; margin-bottom: 32px; }
	.parties h2, .instructions h2 { margin: 0 0 4px; color: #666; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
	.parties p { margin: 0; white-space: pre-line; }
	table { width: 100%; border-collapse: collapse; }
	th, td { padding: 8px 0; border-bottom: 1px solid #e3e3e3; text-align: start; vertical-align: top; }
	th { color: #666; font-size: 13px; font-weight: 600; }
	.num { text-align: end; white-space: nowrap; padding-inline-start: 16px; }
	.child td { padding-top: 2px; padding-bottom: 2px; border-bottom: 0; color: #555; font-size: 13px; }
	.child td:first-child { padding-inline-start: 16px; }
	tfoot td, tfoot th { border-bottom: 0; color: inherit; font-size: 15px; font-weight: 400; }
	tfoot .total td, tfoot .total th { padding-top: 12px; border-top: 2px solid #1d1d1d; font-size: 18px; font-weight: 700; }
	.instructions { margin-top: 32px; padding: 16px; background: #f6f6f6; white-space: pre-line; }
	.note { margin-top: 32px; color: #555; font-size: 13px; white-space: pre-line; }
	.status { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 4px; background: #e8f3ea; color: #1e5b2b; font-size: 13px; }
	.status.unpaid { background: #fdf0e1; color: #7a4a0b; }
	.actions { max-width: 800px; margin: 0 auto; padding: 0 0 24px; text-align: end; }
	.actions button { padding: 8px 16px; border: 1px solid #1d1d1d; border-radius: 4px; background: #1d1d1d; color: #fff; font: inherit; cursor: pointer; }
	@media (max-width: 640px) { .sheet { margin: 0; padding: 24px 16px; box-shadow: none; } .seller { text-align: start; } }
	@media print { body { background: #fff; } .sheet { margin: 0; padding: 0; max-width: none; box-shadow: none; } .actions { display: none; } }
</style>
</head>
<body>
<main class="sheet">
	<header>
		<div>
			<h1><?php echo esc_html( $args['title'] ); ?></h1>
			<dl class="meta">
				<dt><?php esc_html_e( 'Order date', 'slashphoto' ); ?></dt>
				<dd><?php echo esc_html( $slashphoto_date( $slashphoto_order['created_at'] ) ); ?></dd>
				<?php if ( $slashphoto_order['paid_at'] ) : ?>
					<dt><?php esc_html_e( 'Paid', 'slashphoto' ); ?></dt>
					<dd><?php echo esc_html( $slashphoto_date( $slashphoto_order['paid_at'] ) ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Payment', 'slashphoto' ); ?></dt>
				<dd><?php echo esc_html( $args['payment'] ); ?></dd>
			</dl>
			<?php if ( 'refunded' === $slashphoto_order['status'] ) : ?>
				<span class="status unpaid"><?php esc_html_e( 'Refunded', 'slashphoto' ); ?></span>
			<?php elseif ( 'receipt' === $args['type'] ) : ?>
				<span class="status"><?php esc_html_e( 'Paid', 'slashphoto' ); ?></span>
			<?php else : ?>
				<span class="status unpaid"><?php esc_html_e( 'Not paid', 'slashphoto' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="seller">
			<strong><?php echo esc_html( $args['seller'] ); ?></strong>
			<?php echo esc_html( $args['details'] ); ?>
		</div>
	</header>

	<section class="parties">
		<div>
			<h2><?php esc_html_e( 'Customer', 'slashphoto' ); ?></h2>
			<p><?php echo esc_html( implode( "\n", array_filter( [ (string) $slashphoto_order['name'], (string) $slashphoto_order['email'], (string) $slashphoto_order['phone'] ] ) ) ); ?></p>
		</div>
		<?php if ( $slashphoto_order['address'] ) : ?>
			<div>
				<h2><?php esc_html_e( 'Delivery address', 'slashphoto' ); ?></h2>
				<p><?php echo esc_html( implode( "\n", \SlashPhoto\Shop\Emails::address_lines( $slashphoto_order ) ) ); ?></p>
			</div>
		<?php endif; ?>
	</section>

	<table>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Product', 'slashphoto' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Quantity', 'slashphoto' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Price', 'slashphoto' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Amount', 'slashphoto' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $args['items'] as $slashphoto_item ) : ?>
				<?php if ( $slashphoto_item['parent_id'] ) : ?>
					<tr class="child"><td colspan="4"><?php echo esc_html( $slashphoto_item['file_label'] ); ?></td></tr>
				<?php else : ?>
					<tr>
						<td><?php echo esc_html( $slashphoto_item['name'] ); ?><br><small><?php echo esc_html( $slashphoto_item['file_label'] . ( '' !== (string) ( $slashphoto_item['licence_name'] ?? '' ) ? ' · ' . sprintf( /* translators: %s: licence name */ __( 'Licence: %s', 'slashphoto' ), $slashphoto_item['licence_name'] ) : '' ) ); ?></small></td>
						<td class="num"><?php echo esc_html( (string) $slashphoto_item['quantity'] ); ?></td>
						<td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_item['unit_price'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_item['total'] ) ); ?></td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr><th colspan="3"><?php esc_html_e( 'Subtotal', 'slashphoto' ); ?></th><td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_order['subtotal'] ) ); ?></td></tr>
			<?php if ( $slashphoto_order['discount'] > 0 ) : ?>
				<tr>
					<th colspan="3">
						<?php
						echo esc_html(
							'' !== (string) $slashphoto_order['coupon']
								/* translators: %s: discount code */
								? sprintf( __( 'Discount (%s)', 'slashphoto' ), $slashphoto_order['coupon'] )
								: __( 'Discount', 'slashphoto' )
						);
						?>
					</th>
					<td class="num"><?php echo esc_html( '−' . $slashphoto_money( $slashphoto_order['discount'] ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( $slashphoto_order['shipping'] > 0 || $slashphoto_order['address'] ) : ?>
				<tr><th colspan="3"><?php esc_html_e( 'Shipping', 'slashphoto' ); ?></th><td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_order['shipping'] ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! $slashphoto_order['prices_include_tax'] && $slashphoto_order['tax'] > 0 ) : ?>
				<tr>
					<th colspan="3">
						<?php
						/* translators: %s: VAT rate */
						echo esc_html( sprintf( __( 'VAT %s %%', 'slashphoto' ), $slashphoto_rate ) . $slashphoto_vat_in );
						?>
					</th>
					<td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_order['tax'] ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr class="total"><th colspan="3"><?php esc_html_e( 'Total', 'slashphoto' ); ?></th><td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_order['total'] ) ); ?></td></tr>
			<?php if ( (int) ( $slashphoto_order['gift_card_amount'] ?? 0 ) > 0 ) : ?>
				<tr>
					<th colspan="3">
						<?php
						/* translators: %s: last four characters of the gift card code */
						echo esc_html( sprintf( __( 'Paid with gift card (…%s)', 'slashphoto' ), $slashphoto_order['gift_card_hint'] ) );
						?>
					</th>
					<td class="num"><?php echo esc_html( '−' . $slashphoto_money( (int) $slashphoto_order['gift_card_amount'] ) ); ?></td>
				</tr>
				<tr><th colspan="3"><?php echo esc_html( 'receipt' === $args['type'] ? __( 'Paid', 'slashphoto' ) : __( 'To pay', 'slashphoto' ) ); ?></th><td class="num"><?php echo esc_html( $slashphoto_money( \SlashPhoto\Shop\GiftCards::due( $slashphoto_order ) ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $slashphoto_order['prices_include_tax'] && $slashphoto_order['tax'] > 0 ) : ?>
				<tr>
					<th colspan="3">
						<?php
						/* translators: 1: VAT rate, 2: amount before VAT */
						echo esc_html( sprintf( __( 'Of which VAT %1$s %% (on %2$s)', 'slashphoto' ), $slashphoto_rate, $slashphoto_money( $slashphoto_order['total'] - $slashphoto_order['tax'] ) ) . $slashphoto_vat_in );
						?>
					</th>
					<td class="num"><?php echo esc_html( $slashphoto_money( $slashphoto_order['tax'] ) ); ?></td>
				</tr>
			<?php endif; ?>
		</tfoot>
	</table>

	<?php if ( '' !== trim( (string) $args['instructions'] ) ) : ?>
		<section class="instructions">
			<h2><?php esc_html_e( 'How to pay', 'slashphoto' ); ?></h2>
			<?php
			/* translators: %d: order number */
			echo esc_html( trim( (string) $args['instructions'] ) . "\n" . sprintf( __( 'Reference: order %d', 'slashphoto' ), $slashphoto_order['id'] ) );
			?>
		</section>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) $args['note'] ) ) : ?>
		<p class="note"><?php echo esc_html( $args['note'] ); ?></p>
	<?php endif; ?>
</main>
<p class="actions"><button type="button" id="slashphoto-print"><?php esc_html_e( 'Print or save as PDF', 'slashphoto' ); ?></button></p>
<script>document.getElementById( 'slashphoto-print' ).addEventListener( 'click', function () { window.print(); } );</script>
</body>
</html>
