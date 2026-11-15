<?php
/**
 * Payment gateway interface.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A way to pay for an order. Gateways never see or store card details on
 * this site: online payments happen on the provider's own pages.
 *
 * Add gateways with the slashphoto_gateways filter.
 */
interface Gateway {

	/**
	 * Short machine name, stored with the order.
	 */
	public function id(): string;

	/**
	 * Name shown to customers.
	 */
	public function title(): string;

	/**
	 * One line shown under the name at checkout.
	 */
	public function description(): string;

	/**
	 * Whether customers can choose it now (configured and switched on).
	 */
	public function available(): bool;

	/**
	 * Starts payment for a new order (status "pending").
	 *
	 * @param array $order Order.
	 * @param array $items Order lines.
	 * @return string|WP_Error Address to send the customer to.
	 */
	public function start( array $order, array $items );

	/**
	 * Called when the customer opens their order page, with the query
	 * arguments, for example to confirm a payment on return from the
	 * provider. Returns the order, updated if anything changed.
	 *
	 * @param array $order Order.
	 * @param array $query Query arguments.
	 */
	public function on_return( array $order, array $query ): array;

	/**
	 * Text for the customer about paying, shown on the order page and in
	 * emails while the order waits for payment. Plain text.
	 *
	 * @param array $order Order.
	 */
	public function instructions( array $order ): string;
}
