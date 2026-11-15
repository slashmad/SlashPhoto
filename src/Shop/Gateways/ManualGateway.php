<?php
/**
 * Manual payment.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Pay outside the site (invoice, bank transfer, Swish, cash at pickup).
 * The order waits as "on-hold" until the photographer marks it paid;
 * downloads open only then.
 */
final class ManualGateway implements Gateway {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		$title = (string) Settings::get( 'manual_title' );
		return '' !== $title ? $title : __( 'Pay later (invoice or transfer)', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'You get payment details by email. Downloads open when the payment has arrived.', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return (bool) Settings::get( 'manual_enabled' );
	}

	/**
	 * Starts payment for a new order.
	 *
	 * @param array $order Order.
	 * @param array $items Order lines.
	 * @return string|\WP_Error Address to send the customer to.
	 */
	public function start( array $order, array $items ) {
		Orders::set_status( (int) $order['id'], 'on-hold' );
		return Orders::url( $order );
	}

	/**
	 * Called when the customer opens their order page.
	 *
	 * @param array $order Order.
	 * @param array $query Query arguments.
	 */
	public function on_return( array $order, array $query ): array {
		return $order;
	}

	/**
	 * Payment text for the customer while the order waits for payment.
	 *
	 * @param array $order Order.
	 */
	public function instructions( array $order ): string {
		return (string) Settings::get( 'manual_instructions' );
	}
}
