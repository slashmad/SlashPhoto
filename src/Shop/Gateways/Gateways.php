<?php
/**
 * Registry of payment gateways.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The gateways customers can choose from.
 */
final class Gateways {

	/**
	 * All registered gateways.
	 *
	 * @return array<string, Gateway>
	 */
	public static function all(): array {
		$gateways = [
			new StripeGateway(),
			new ManualGateway(),
		];

		/**
		 * Filters the payment gateways.
		 *
		 * @param Gateway[] $gateways Gateways.
		 */
		$gateways = (array) apply_filters( 'slashphoto_gateways', $gateways );
		$by_id    = [];
		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof Gateway ) {
				$by_id[ $gateway->id() ] = $gateway;
			}
		}
		return $by_id;
	}

	/**
	 * Gateways customers can choose now.
	 *
	 * @return array<string, Gateway>
	 */
	public static function available(): array {
		return array_filter( self::all(), static fn( Gateway $gateway ): bool => $gateway->available() );
	}

	/**
	 * One gateway.
	 *
	 * @param string $id Gateway ID.
	 */
	public static function get( string $id ): ?Gateway {
		return self::all()[ $id ] ?? null;
	}
}
