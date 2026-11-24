<?php
/**
 * Refund actions in the order workspace.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\RefundPayments;
use SlashPhoto\Shop\Refunds;

defined( 'ABSPATH' ) || exit;

/** Allows authorized order managers to issue and reconcile refunds. */
final class OrderRefunds {

	private const ACTION = 'slashphoto_order_refund';

	/** Registers the authenticated form action. */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/** Processes a deliberate refund or reconciliation request. */
	public function handle(): void {
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$order = Orders::get( $id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'slashphoto' ), '', [ 'response' => 404 ] );
		}
		$action = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$refund = isset( $_POST['refund'] ) ? absint( $_POST['refund'] ) : 0;
		if ( $refund ) {
			$result = Refunds::refresh( $id, $refund, $action );
		} else {
			$input     = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
			$precision = Money::decimals( $order['currency'] );
			$pattern   = $precision ? '/^\d{1,9}(?:[.,]\d{1,' . $precision . '})?$/D' : '/^\d{1,9}$/D';
			$amount    = preg_match( $pattern, $input ) ? Money::parse( $input, $order['currency'] ) : 0;
			$key       = isset( $_POST['request_key'] ) ? sanitize_key( wp_unslash( $_POST['request_key'] ) ) : '';
			$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
			$valid     = ( 'record' === $action && '' !== $reference ) || ( 'issue' === $action && '' === $reference );
			$result    = $valid ? Refunds::create( $id, $amount, $key, $reference ) : '';
		}
		wp_safe_redirect(
			add_query_arg(
				[
					'order'  => $id,
					'refund' => is_wp_error( $result ) || '' === $result ? 'error' : $result,
				],
				Settings::admin_url( '', OrdersPage::SLUG )
			)
		);
		exit;
	}

	/**
	 * Renders a refund history and actions for an order manager.
	 *
	 * @param array $order Order.
	 */
	public static function render( array $order ): void {
		$rows      = Refunds::for_order( $order['id'] );
		$remaining = Refunds::remaining( $order, $rows );
		if ( ! $rows && ! $remaining ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected status message only.
		$notice = isset( $_GET['refund'] ) ? sanitize_key( wp_unslash( $_GET['refund'] ) ) : '';
		$labels = [
			'completed' => __( 'Refund recorded', 'slashphoto' ),
			'pending'   => __( 'Provider processing', 'slashphoto' ),
			'review'    => __( 'Check with the payment provider', 'slashphoto' ),
			'failed'    => __( 'Refund unsuccessful', 'slashphoto' ),
			'error'     => __( 'The refund could not be recorded. Check the amount and the payment provider before trying again.', 'slashphoto' ),
		];
		?>
		<section class="postbox" style="padding:0 12px 12px">
			<h2><?php esc_html_e( 'Refunds', 'slashphoto' ); ?></h2>
			<?php if ( isset( $labels[ $notice ] ) ) : ?>
				<p role="status"><?php echo esc_html( $labels[ $notice ] ); ?></p>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<div>
					<p>
						<strong><?php echo esc_html( Money::format( (int) $row['amount'], $order['currency'] ) ); ?></strong>
						· <?php echo esc_html( $labels[ $row['status'] ] ?? $row['status'] ); ?>
						<?php if ( '' !== $row['reference'] ) : ?>
							<br><code><?php echo esc_html( $row['reference'] ); ?></code>
						<?php endif; ?>
					</p>
					<?php if ( in_array( $row['status'], [ 'pending', 'review' ], true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::fields( $order['id'] ); ?>
							<input type="hidden" name="refund" value="<?php echo esc_attr( $row['id'] ); ?>">
							<?php if ( '' !== $row['reference'] ) : ?>
								<button class="button" name="operation" value="refresh"><?php esc_html_e( 'Check refund status', 'slashphoto' ); ?></button>
							<?php endif; ?>
							<?php if ( 'review' === $row['status'] ) : ?>
								<p class="description"><?php esc_html_e( 'Confirm the outcome in the payment provider before updating this entry.', 'slashphoto' ); ?></p>
								<button class="button" name="operation" value="completed"><?php esc_html_e( 'Confirm funds returned', 'slashphoto' ); ?></button>
								<button class="button" name="operation" value="failed"><?php esc_html_e( 'Confirm no refund was made', 'slashphoto' ); ?></button>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( $remaining ) : ?>
				<details>
					<summary><?php esc_html_e( 'Add refund', 'slashphoto' ); ?></summary>
					<p>
						<?php
						/* translators: %s: maximum amount that may be refunded. */
						echo esc_html( sprintf( __( 'Available to refund: %s', 'slashphoto' ), Money::format( $remaining, $order['currency'] ) ) );
						?>
					</p>
					<?php if ( 'refunded' !== $order['status'] && RefundPayments::supported( $order ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::new_fields( $order['id'] ); ?>
							<p><label><?php esc_html_e( 'Amount to send to the customer', 'slashphoto' ); ?> <input name="amount" inputmode="decimal" size="8" required></label></p>
							<p><button class="button" name="operation" value="issue"><?php esc_html_e( 'Send refund through payment provider', 'slashphoto' ); ?></button></p>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::new_fields( $order['id'] ); ?>
						<p><label><?php esc_html_e( 'Amount already returned', 'slashphoto' ); ?> <input name="amount" inputmode="decimal" size="8" required></label></p>
						<p><label><?php esc_html_e( 'Transaction reference', 'slashphoto' ); ?> <input name="reference" maxlength="100" required></label></p>
						<p><button class="button" name="operation" value="record"><?php esc_html_e( 'Record completed refund', 'slashphoto' ); ?></button></p>
					</form>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Writes fields shared by refund forms.
	 *
	 * @param int $id Order ID.
	 */
	private static function fields( int $id ): void {
		wp_nonce_field( self::ACTION . '_' . $id, '_wpnonce', false );
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="order" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php
	}

	/**
	 * Writes fields for a new refund.
	 *
	 * @param int $id Order ID.
	 */
	private static function new_fields( int $id ): void {
		self::fields( $id );
		?>
		<input type="hidden" name="request_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
		<?php
	}
}
