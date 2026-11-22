<?php
/**
 * Order emails.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails when an order changes status:
 *
 * - on-hold:   customer (payment details) and shop owner (new order)
 * - paid:      customer (receipt, downloads) and shop owner (to fulfil)
 * - completed: customer
 * - cancelled, refunded: customer
 */
final class Emails {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'slashphoto_order_status', [ $this, 'on_status' ], 10, 3 );
	}

	/**
	 * Address for shop notifications.
	 */
	public static function owner(): string {
		$email = (string) Settings::get( 'shop_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Sends the emails for a status change.
	 *
	 * @param int    $id     Order ID.
	 * @param string $status New status.
	 * @param string $old    Previous status.
	 */
	public function on_status( $id, $status, $old ): void {
		$order = Orders::get( (int) $id );
		if ( ! $order ) {
			return;
		}
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$number = (int) $order['id'];

		switch ( $status ) {
			case 'on-hold':
				/* translators: 1: site name, 2: order number */
				$this->customer( $order, sprintf( __( '[%1$s] Order %2$d received', 'slashphoto' ), $site, $number ), __( 'Thank you for your order. It is waiting for your payment.', 'slashphoto' ), true );
				/* translators: 1: site name, 2: order number */
				$this->owner_mail( $order, sprintf( __( '[%1$s] New order %2$d, awaiting payment', 'slashphoto' ), $site, $number ) );
				break;
			case 'paid':
				/* translators: 1: site name, 2: order number */
				$this->customer( $order, sprintf( __( '[%1$s] Payment received for order %2$d', 'slashphoto' ), $site, $number ), __( 'Thank you, we have received your payment.', 'slashphoto' ) );
				/* translators: 1: site name, 2: order number */
				$this->owner_mail( $order, sprintf( __( '[%1$s] Order %2$d is paid', 'slashphoto' ), $site, $number ) );
				break;
			case 'completed':
				/* translators: 1: site name, 2: order number */
				$this->customer( $order, sprintf( __( '[%1$s] Order %2$d is complete', 'slashphoto' ), $site, $number ), __( 'Your order is complete. Prints are on their way if you ordered any.', 'slashphoto' ) );
				break;
			case 'cancelled':
				if ( 'pending' !== $old ) {
					/* translators: 1: site name, 2: order number */
					$this->customer( $order, sprintf( __( '[%1$s] Order %2$d was cancelled', 'slashphoto' ), $site, $number ), __( 'Your order was cancelled.', 'slashphoto' ) );
				}
				break;
			case 'refunded':
				/* translators: 1: site name, 2: order number */
				$this->customer( $order, sprintf( __( '[%1$s] Order %2$d was refunded', 'slashphoto' ), $site, $number ), __( 'Your order was refunded.', 'slashphoto' ) );
				break;
		}//end switch
	}

	/**
	 * Email to the customer.
	 *
	 * @param array  $order        Order.
	 * @param string $subject      Subject.
	 * @param string $intro        First paragraph.
	 * @param bool   $instructions Whether to include payment details.
	 */
	private function customer( array $order, string $subject, string $intro, bool $instructions = false ): void {
		$lines = [ $intro, '' ];
		if ( $instructions ) {
			$gateway = Gateways::get( (string) $order['gateway'] );
			$text    = $gateway ? trim( $gateway->instructions( $order ) ) : '';
			if ( '' !== $text ) {
				$lines[] = $text;
				$lines[] = '';
			}
		}
		$lines = array_merge( $lines, self::summary( $order ) );
		// Gift cards bought with a paid order: the codes go to the buyer only.
		$codes = in_array( $order['status'], Orders::PAID, true ) ? GiftCards::codes( $order ) : [];
		if ( $codes ) {
			$lines[] = '';
			$lines[] = __( 'Your gift cards:', 'slashphoto' );
			foreach ( $codes as $code ) {
				$lines[] = $code['code'] . ' – ' . Money::format( $code['card']['amount'], (string) $order['currency'] )
					/* translators: %s: date */
					. ( null !== $code['card']['expires_at'] ? ', ' . sprintf( __( 'valid until %s', 'slashphoto' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $code['card']['expires_at'] . ' UTC' ) ) ) : '' );
			}
			$lines[] = __( 'Enter a code under "Gift card code" in the cart. Anyone who has a code can use it.', 'slashphoto' );
		}
		$lines[] = '';
		if ( Orders::downloads_open( $order ) && in_array( 'download', array_column( Orders::items( (int) $order['id'] ), 'kind' ), true ) ) {
			$lines[] = __( 'Download your photos here:', 'slashphoto' );
		} else {
			$lines[] = __( 'See your order here:', 'slashphoto' );
		}
		$lines[] = Orders::url( $order );
		if ( 'confirmation' !== Receipts::type( $order ) ) {
			$lines[] = '';
			$lines[] = 'receipt' === Receipts::type( $order ) ? __( 'Your receipt:', 'slashphoto' ) : __( 'Your invoice:', 'slashphoto' );
			$lines[] = Receipts::url( $order );
		}
		$lines[] = '';
		$lines[] = __( 'Keep this link to yourself: anyone who has it can see the order.', 'slashphoto' );

		$headers = [ 'Reply-To: ' . self::owner() ];
		wp_mail( (string) $order['email'], $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Email to the shop owner.
	 *
	 * @param array  $order   Order.
	 * @param string $subject Subject.
	 */
	private function owner_mail( array $order, string $subject ): void {
		$lines   = self::summary( $order, true );
		$lines[] = '';
		$lines[] = __( 'Open the order:', 'slashphoto' );
		$lines[] = add_query_arg( 'order', $order['id'], Settings::admin_url( '', 'slashphoto-orders' ) );
		wp_mail( self::owner(), $subject, implode( "\n", $lines ), [ 'Reply-To: ' . str_replace( [ ',', '"', '<', '>', ';' ], '', (string) $order['name'] ) . ' <' . $order['email'] . '>' ] );
	}

	/**
	 * Order summary as text lines.
	 *
	 * @param array $order   Order.
	 * @param bool  $details Include contact details and file names (for the shop owner).
	 * @return string[]
	 */
	public static function summary( array $order, bool $details = false ): array {
		$money = static fn( int $amount ): string => Money::format( $amount, (string) $order['currency'] );
		/* translators: %d: order number */
		$lines = [ sprintf( __( 'Order %d', 'slashphoto' ), $order['id'] ) ];
		foreach ( Orders::items( (int) $order['id'] ) as $item ) {
			$lines[] = $item['parent_id']
				? sprintf( '    – %s', $item['file_label'] )
				: sprintf( '%d × %s – %s: %s', $item['quantity'], $item['name'], $item['file_label'], $money( $item['total'] ) )
					/* translators: %s: licence name */
					. ( '' !== (string) ( $item['licence_name'] ?? '' ) ? ' (' . sprintf( __( 'Licence: %s', 'slashphoto' ), $item['licence_name'] ) . ')' : '' );
		}
		if ( $order['discount'] > 0 ) {
			$lines[] = '' !== (string) $order['coupon']
				/* translators: 1: discount code, 2: amount */
				? sprintf( __( 'Discount (%1$s): −%2$s', 'slashphoto' ), $order['coupon'], $money( $order['discount'] ) )
				/* translators: %s: amount */
				: sprintf( __( 'Discount: −%s', 'slashphoto' ), $money( $order['discount'] ) );
		}
		if ( $order['shipping'] > 0 || ! empty( $order['address'] ) ) {
			/* translators: %s: amount */
			$lines[] = sprintf( __( 'Shipping: %s', 'slashphoto' ), $money( $order['shipping'] ) );
		}
		if ( $order['tax'] > 0 ) {
			$lines[] = $order['prices_include_tax']
				/* translators: %s: amount */
				? sprintf( __( 'Including VAT: %s', 'slashphoto' ), $money( $order['tax'] ) )
				/* translators: %s: amount */
				: sprintf( __( 'VAT: %s', 'slashphoto' ), $money( $order['tax'] ) );
		}
		/* translators: %s: amount */
		$lines[] = sprintf( __( 'Total: %s', 'slashphoto' ), $money( $order['total'] ) );
		if ( $order['gift_card_amount'] > 0 ) {
			/* translators: 1: last four characters of the gift card code, 2: amount */
			$lines[] = sprintf( __( 'Gift card (…%1$s): −%2$s', 'slashphoto' ), $order['gift_card_hint'], $money( $order['gift_card_amount'] ) );
			/* translators: %s: amount */
			$lines[] = sprintf( __( 'To pay: %s', 'slashphoto' ), $money( GiftCards::due( $order ) ) );
		}

		if ( $details ) {
			$lines[] = '';
			$lines[] = $order['name'] . ' <' . $order['email'] . '>' . ( '' !== $order['phone'] ? ', ' . $order['phone'] : '' );
		}
		if ( ! empty( $order['address'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Delivery address:', 'slashphoto' );
			$lines   = array_merge( $lines, self::address_lines( $order ) );
		}
		if ( $details && '' !== (string) $order['customer_note'] ) {
			$lines[] = '';
			$lines[] = __( 'Message from the customer:', 'slashphoto' );
			$lines[] = (string) $order['customer_note'];
		}
		return $lines;
	}

	/**
	 * Delivery address as lines.
	 *
	 * @param array $order Order.
	 * @return string[]
	 */
	public static function address_lines( array $order ): array {
		$a = $order['address'];
		return array_values(
			array_filter(
				[
					(string) $order['name'],
					(string) ( $a['line1'] ?? '' ),
					(string) ( $a['line2'] ?? '' ),
					trim( ( $a['postcode'] ?? '' ) . ' ' . ( $a['city'] ?? '' ) ),
					(string) ( $a['country'] ?? '' ),
				],
				static fn( string $line ): bool => '' !== $line
			)
		);
	}
}
