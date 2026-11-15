<?php
/**
 * Site-wide SlashPhoto settings.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Shop\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the slashphoto_settings option.
 *
 * Two screens edit this option (Settings and Shop). Each form sends a
 * "_section" field; only that section's keys are taken from the form and
 * the rest keep their saved values. Amounts are stored in minor units and
 * typed in as decimals through "<key>_input" fields.
 */
final class Settings {

	public const OPTION = 'slashphoto_settings';

	public const POSITIONS = [ 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'tile' ];

	/**
	 * When SnapOtter makes the display copy: never, only for files WordPress
	 * cannot handle well (RAW, HEIC, TIFF, PSD, very large images), or always.
	 */
	public const SNAPOTTER_MODES = [ 'off', 'heavy', 'all' ];

	/**
	 * Keys per form section, and which of them are checkboxes.
	 */
	public const SECTIONS   = [
		'general' => [ 'watermark_id', 'watermark_default', 'watermark_position', 'watermark_scale', 'watermark_opacity', 'display_size', 'display_quality', 'snapotter_mode', 'snapotter_min_mp' ],
		'shop'    => [ 'currency', 'prices_include_tax', 'tax_rate', 'shipping_flat', 'free_shipping_over', 'shipping_countries', 'download_days', 'shop_page', 'shop_terms_page', 'shop_email', 'shop_price_list', 'manual_enabled', 'manual_title', 'manual_instructions', 'business_name', 'business_details', 'receipt_note' ],
	];
	public const CHECKBOXES = [ 'watermark_default', 'prices_include_tax', 'manual_enabled' ];
	public const AMOUNTS    = [ 'shipping_flat', 'free_shipping_over' ];

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$swedish = str_starts_with( (string) get_locale(), 'sv' );
		return [
			'watermark_id'        => 0,
			'watermark_default'   => false,
			'watermark_position'  => 'center',
			'watermark_scale'     => 30,
			'watermark_opacity'   => 60,
			'display_size'        => 2560,
			'display_quality'     => 90,
			'snapotter_mode'      => 'heavy',
			'snapotter_min_mp'    => 40,
			'currency'            => $swedish ? 'SEK' : 'EUR',
			'prices_include_tax'  => true,
			'tax_rate'            => 25.0,
			'shipping_flat'       => 0,
			'free_shipping_over'  => 0,
			'shipping_countries'  => $swedish ? 'SE' : '',
			'download_days'       => 30,
			'shop_page'           => 0,
			'shop_terms_page'     => 0,
			'shop_email'          => '',
			'shop_price_list'     => 0,
			'manual_enabled'      => true,
			'manual_title'        => '',
			'manual_instructions' => '',
			'business_name'       => '',
			'business_details'    => '',
			'receipt_note'        => '',
		];
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return self::sanitize( is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Address of a SlashPhoto admin screen.
	 *
	 * @param string $anchor Fragment without "#".
	 * @param string $page   Page slug.
	 */
	public static function admin_url( string $anchor = '', string $page = 'slashphoto-settings' ): string {
		return admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=' . $page ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * Sanitises submitted or stored settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];

		if ( isset( $input['_section'] ) ) {
			$keys  = self::SECTIONS[ (string) $input['_section'] ] ?? [];
			$saved = get_option( self::OPTION, [] );
			$form  = $input;
			$input = is_array( $saved ) ? $saved : [];
			foreach ( $keys as $key ) {
				if ( in_array( $key, self::CHECKBOXES, true ) ) {
					$input[ $key ] = ! empty( $form[ $key ] );
				} elseif ( array_key_exists( $key . '_input', $form ) ) {
					$input[ $key . '_input' ] = $form[ $key . '_input' ];
				} elseif ( array_key_exists( $key, $form ) ) {
					$input[ $key ] = $form[ $key ];
				}
			}
		}

		$defaults = self::defaults();
		$value    = static fn( string $key ) => $input[ $key ] ?? $defaults[ $key ];

		$id = absint( $input['watermark_id'] ?? 0 );
		// Only transparent PNG files make sense as watermarks.
		if ( $id && 'image/png' !== get_post_mime_type( $id ) ) {
			$id = 0;
		}
		$position = (string) $value( 'watermark_position' );

		/** This filter is documented in src/Admin/ShopSettings.php */
		$currencies = (array) apply_filters( 'slashphoto_currencies', Money::CURRENCIES );
		$currency   = strtoupper( (string) $value( 'currency' ) );
		$currency   = in_array( $currency, $currencies, true ) ? $currency : $defaults['currency'];

		$clean = [
			'watermark_id'        => $id,
			'watermark_default'   => ! empty( $input['watermark_default'] ),
			'watermark_position'  => in_array( $position, self::POSITIONS, true ) ? $position : $defaults['watermark_position'],
			'watermark_scale'     => min( 100, max( 5, (int) $value( 'watermark_scale' ) ) ),
			'watermark_opacity'   => min( 100, max( 5, (int) $value( 'watermark_opacity' ) ) ),
			'display_size'        => min( 8192, max( 1024, (int) $value( 'display_size' ) ) ),
			'display_quality'     => min( 100, max( 60, (int) $value( 'display_quality' ) ) ),
			'snapotter_mode'      => in_array( $input['snapotter_mode'] ?? '', self::SNAPOTTER_MODES, true ) ? $input['snapotter_mode'] : $defaults['snapotter_mode'],
			'snapotter_min_mp'    => min( 1000, max( 1, (int) $value( 'snapotter_min_mp' ) ) ),
			'currency'            => $currency,
			'prices_include_tax'  => (bool) $value( 'prices_include_tax' ),
			'tax_rate'            => min( 100.0, max( 0.0, round( (float) str_replace( ',', '.', (string) $value( 'tax_rate' ) ), 3 ) ) ),
			'shipping_countries'  => implode( ', ', array_unique( array_filter( (array) preg_split( '/[\s,;]+/', strtoupper( (string) $value( 'shipping_countries' ) ) ), static fn( string $code ): bool => (bool) preg_match( '/^[A-Z]{2}$/', $code ) ) ) ),
			'download_days'       => min( 3650, max( 0, (int) $value( 'download_days' ) ) ),
			'shop_page'           => absint( $value( 'shop_page' ) ),
			'shop_terms_page'     => absint( $value( 'shop_terms_page' ) ),
			'shop_email'          => is_email( (string) $value( 'shop_email' ) ) ? sanitize_email( (string) $value( 'shop_email' ) ) : '',
			'shop_price_list'     => absint( $value( 'shop_price_list' ) ),
			'manual_enabled'      => (bool) $value( 'manual_enabled' ),
			'manual_title'        => mb_substr( sanitize_text_field( (string) $value( 'manual_title' ) ), 0, 100 ),
			'manual_instructions' => mb_substr( sanitize_textarea_field( (string) $value( 'manual_instructions' ) ), 0, 2000 ),
			'business_name'       => mb_substr( sanitize_text_field( (string) $value( 'business_name' ) ), 0, 200 ),
			'business_details'    => mb_substr( sanitize_textarea_field( (string) $value( 'business_details' ) ), 0, 1000 ),
			'receipt_note'        => mb_substr( sanitize_textarea_field( (string) $value( 'receipt_note' ) ), 0, 1000 ),
		];
		foreach ( self::AMOUNTS as $key ) {
			$clean[ $key ] = array_key_exists( $key . '_input', $input )
				? Money::parse( (string) $input[ $key . '_input' ], $currency )
				: max( 0, (int) $value( $key ) );
		}
		return $clean;
	}
}
