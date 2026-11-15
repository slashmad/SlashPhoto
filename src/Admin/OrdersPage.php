<?php
/**
 * Orders screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\Emails;
use SlashPhoto\Shop\Gateways\Gateways;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\Receipts;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Orders: the list, one order with its lines and delivery
 * address, status changes (which email the customer) and the originals of
 * ordered images for printing.
 */
final class OrdersPage {

	public const SLUG   = 'slashphoto-orders';
	public const ACTION = 'slashphoto_order_update';

	/**
	 * Capability needed to see and change orders.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability for the Orders screen.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'slashphoto_orders_capability', 'manage_options' );
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Adds the submenu page with a count of orders to fulfil.
	 */
	public function menu(): void {
		$waiting = 0;
		if ( current_user_can( self::capability() ) ) {
			$counts  = Orders::counts();
			$waiting = $counts['paid'] + $counts['on-hold'];
		}
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Orders', 'slashphoto' ),
			__( 'Orders', 'slashphoto' ) . ( $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '' ),
			self::capability(),
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Changes the status or the private note of an order.
	 */
	public function handle(): void {
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$order = Orders::get( $id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'slashphoto' ), '', [ 'response' => 404 ] );
		}
		$note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';
		if ( $note !== (string) $order['admin_note'] ) {
			Orders::update( $id, [ 'admin_note' => mb_substr( $note, 0, 5000 ) ] );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( in_array( $status, Orders::STATUSES, true ) ) {
			Orders::set_status( $id, $status );
		}
		wp_safe_redirect(
			add_query_arg(
				[
					'order'   => $id,
					'updated' => 1,
				],
				Settings::admin_url( '', self::SLUG )
			)
		);
		exit;
	}

	/**
	 * Renders the list or one order.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		if ( $id ) {
			$this->render_order( $id, ! empty( $_GET['updated'] ) );
			return;
		}
		$table = new OrdersTable();
		$table->prepare_items();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Orders', 'slashphoto' ); ?></h1>
			<hr class="wp-header-end">
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::POST_TYPE ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				$table->search_box( __( 'Search orders', 'slashphoto' ), 'slashphoto-order' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders one order.
	 *
	 * @param int  $id      Order ID.
	 * @param bool $updated Whether it was just saved.
	 */
	private function render_order( int $id, bool $updated ): void {
		$order = Orders::get( $id );
		$back  = Settings::admin_url( '', self::SLUG );
		if ( ! $order ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Order not found.', 'slashphoto' ) . '</h1><p><a href="' . esc_url( $back ) . '">' . esc_html__( 'All orders', 'slashphoto' ) . '</a></p></div>';
			return;
		}
		$items   = Orders::items( $id );
		$money   = static fn( int $amount ): string => Money::format( $amount, (string) $order['currency'] );
		$labels  = Orders::labels();
		$gateway = Gateways::get( (string) $order['gateway'] );
		?>
		<div class="wrap slashphoto-order">
			<h1>
				<?php
				/* translators: %d: order number */
				echo esc_html( sprintf( __( 'Order %d', 'slashphoto' ), $id ) );
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'All orders', 'slashphoto' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( Receipts::admin_url( $id ) ); ?>" target="_blank" rel="noopener">
				<?php
				echo esc_html(
					[
						'receipt'      => __( 'Receipt', 'slashphoto' ),
						'invoice'      => __( 'Invoice', 'slashphoto' ),
						'confirmation' => __( 'Order summary', 'slashphoto' ),
					][ Receipts::type( $order ) ]
				);
				?>
							</a>
			</p>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Order updated.', 'slashphoto' ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
				<div style="flex:2 1 480px;min-width:0">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Image', 'slashphoto' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Product', 'slashphoto' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Quantity', 'slashphoto' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Total', 'slashphoto' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Original', 'slashphoto' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<tr<?php echo $item['parent_id'] ? ' class="slashphoto-order-child"' : ''; ?>>
									<td<?php echo $item['parent_id'] ? ' style="padding-left:32px"' : ''; ?>>
										<?php echo wp_get_attachment_image( $item['image_id'], [ 80, 80 ], false, [ 'style' => 'width:60px;height:auto;vertical-align:middle;margin-right:8px' ] ); ?>
										<strong><?php echo esc_html( $item['file_label'] ); ?></strong>
										<?php if ( $item['gallery_id'] && get_post( $item['gallery_id'] ) ) : ?>
											<br><a href="<?php echo esc_url( (string) get_edit_post_link( $item['gallery_id'] ) ); ?>"><?php echo esc_html( get_the_title( $item['gallery_id'] ) ); ?></a>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $item['name'] ); ?></td>
									<td><?php echo esc_html( (string) $item['quantity'] ); ?></td>
									<td><?php echo esc_html( $item['parent_id'] ? __( 'In package', 'slashphoto' ) : $money( $item['total'] ) ); ?></td>
									<td>
										<?php if ( 'package' === $item['kind'] ) : ?>
											&nbsp;
										<?php elseif ( get_post( $item['image_id'] ) ) : ?>
											<a href="<?php echo esc_url( Downloads::admin_url( $item['image_id'] ) ); ?>"><?php esc_html_e( 'Download', 'slashphoto' ); ?></a>
										<?php else : ?>
											<?php esc_html_e( 'Deleted', 'slashphoto' ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<?php if ( $order['discount'] > 0 ) : ?>
								<tr><th colspan="3"><?php echo esc_html( '' !== (string) $order['coupon'] ? sprintf( /* translators: %s: discount code */ __( 'Discount (%s)', 'slashphoto' ), $order['coupon'] ) : __( 'Discount', 'slashphoto' ) ); ?></th><td colspan="2"><?php echo esc_html( '−' . $money( $order['discount'] ) ); ?></td></tr>
							<?php endif; ?>
							<?php if ( $order['shipping'] > 0 || $order['address'] ) : ?>
								<tr><th colspan="3"><?php esc_html_e( 'Shipping', 'slashphoto' ); ?></th><td colspan="2"><?php echo esc_html( $money( $order['shipping'] ) ); ?></td></tr>
							<?php endif; ?>
							<?php if ( $order['tax'] > 0 ) : ?>
								<tr><th colspan="3"><?php echo esc_html( $order['prices_include_tax'] ? __( 'Including VAT', 'slashphoto' ) : __( 'VAT', 'slashphoto' ) ); ?></th><td colspan="2"><?php echo esc_html( $money( $order['tax'] ) ); ?></td></tr>
							<?php endif; ?>
							<tr><th colspan="3"><strong><?php esc_html_e( 'Total', 'slashphoto' ); ?></strong></th><td colspan="2"><strong><?php echo esc_html( $money( $order['total'] ) ); ?></strong></td></tr>
						</tfoot>
					</table>
					<?php if ( '' !== (string) $order['customer_note'] ) : ?>
						<h2><?php esc_html_e( 'Message from the customer', 'slashphoto' ); ?></h2>
						<p><?php echo nl2br( esc_html( (string) $order['customer_note'] ) ); ?></p>
					<?php endif; ?>
				</div>

				<div style="flex:1 1 280px;min-width:0">
					<div class="postbox" style="padding:0 12px 12px">
						<h2><?php esc_html_e( 'Customer', 'slashphoto' ); ?></h2>
						<p>
							<?php echo esc_html( (string) $order['name'] ); ?><br>
							<a href="mailto:<?php echo esc_attr( (string) $order['email'] ); ?>"><?php echo esc_html( (string) $order['email'] ); ?></a>
							<?php if ( '' !== (string) $order['phone'] ) : ?>
								<br><?php echo esc_html( (string) $order['phone'] ); ?>
							<?php endif; ?>
						</p>
						<?php if ( $order['address'] ) : ?>
							<h3><?php esc_html_e( 'Delivery address', 'slashphoto' ); ?></h3>
							<p><?php echo implode( '<br>', array_map( 'esc_html', Emails::address_lines( $order ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line. ?></p>
						<?php endif; ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: date, 2: payment method */
									__( 'Placed %1$s · %2$s', 'slashphoto' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $order['created_at'] . ' UTC' ) ),
									$gateway ? $gateway->title() : (string) $order['gateway']
								)
							);
							if ( $order['paid_at'] ) {
								echo '<br>' . esc_html(
									sprintf(
										/* translators: %s: date */
										__( 'Paid %s', 'slashphoto' ),
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $order['paid_at'] . ' UTC' ) )
									)
								);
							}
							if ( '' !== (string) $order['gateway_ref'] && 'stripe' === $order['gateway'] ) {
								echo '<br>' . esc_html( (string) $order['gateway_ref'] );
							}
							?>
						</p>
					</div>

					<form class="postbox" style="padding:0 12px 12px" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<h2><?php esc_html_e( 'Status', 'slashphoto' ); ?></h2>
						<?php wp_nonce_field( self::ACTION . '_' . $id ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="order" value="<?php echo esc_attr( (string) $id ); ?>">
						<p>
							<label for="slashphoto-order-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'slashphoto' ); ?></label>
							<select id="slashphoto-order-status" name="status">
								<?php foreach ( $labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $order['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="description"><?php esc_html_e( 'The customer gets an email when the order becomes paid, completed, cancelled or refunded. Marking it paid opens the downloads. Refunds through Stripe are made in the Stripe Dashboard.', 'slashphoto' ); ?></p>
						<p>
							<label for="slashphoto-admin-note"><?php esc_html_e( 'Private note', 'slashphoto' ); ?></label>
							<textarea id="slashphoto-admin-note" name="admin_note" class="widefat" rows="3"><?php echo esc_textarea( (string) $order['admin_note'] ); ?></textarea>
						</p>
						<?php submit_button( __( 'Update order', 'slashphoto' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
