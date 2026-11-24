<?php
/**
 * Print delivery methods and their prices.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Settings;

defined( 'ABSPATH' ) || exit;

/** Standard shipping, optional express shipping and optional pickup. */
final class Delivery {

	/** Methods offered at checkout, in the default selection order. */
	public static function methods(): array {
		$methods = [];
		if ( Settings::get( 'shipping_countries' ) ) {
			$methods['standard'] = __( 'Standard shipping', 'slashphoto' );
			if ( Settings::get( 'express_shipping' ) ) {
				$methods['express'] = __( 'Express shipping', 'slashphoto' );
			}
		}
		if ( Settings::get( 'pickup_country' ) && Settings::get( 'pickup_instructions' ) ) {
			$methods['pickup'] = __( 'Local pickup', 'slashphoto' );
		}
		return $methods;
	}

	/**
	 * Prices the selected method using the shop's rates.
	 *
	 * @param string $selected      The requested method.
	 * @param int    $discounted    Order value after discounts (excluding gift cards).
	 * @param bool   $free_shipping Whether a coupon grants standard shipping.
	 */
	public static function quote( string $selected, int $discounted, bool $free_shipping ): array {
		$methods = self::methods();
		$method  = '' === $selected ? (string) array_key_first( $methods ) : $selected;
		$valid   = isset( $methods[ $method ] );
		$price   = 0;
		if ( 'express' === $method && $valid ) {
			$price = (int) Settings::get( 'express_price' );
		} elseif ( 'standard' === $method || ( '' === $method && '' === $selected ) ) {
			$threshold = (int) Settings::get( 'free_shipping_over' );
			$price     = $free_shipping || ( $threshold > 0 && $discounted >= $threshold ) ? 0 : (int) Settings::get( 'shipping_flat' );
		}
		return [
			'id'           => $valid ? $method : '',
			'label'        => $methods[ $method ] ?? '',
			'price'        => $price,
			'instructions' => 'pickup' === $method && $valid ? (string) Settings::get( 'pickup_instructions' ) : '',
		];
	}
}
