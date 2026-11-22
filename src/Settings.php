<?php
/**
 * Site-wide SlashPhoto settings.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Shop\Countries;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\PrintPresets;

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
		'general_watermark' => [ 'watermark_id', 'watermark_default', 'watermark_position', 'watermark_scale', 'watermark_opacity' ],
		'general_galleries' => [ 'gallery_directory_page' ],
		'general_copies'    => [ 'display_size', 'display_quality', 'display_format' ],
		'general_snapotter' => [ 'snapotter_mode', 'snapotter_min_mp' ],
		'general_visitors'  => [ 'stats_enabled', 'visitor_retention_days', 'terms_ask_email', 'signin_links' ],
		'general_proofing'  => [ 'selection_client_email' ],
		'general_inbox'     => [ 'inbox_enabled' ],
		'shop_pages'        => [ 'shop_page', 'shop_terms_page' ],
		'shop_prices'       => [ 'currency', 'prices_include_tax', 'tax_rate', 'tax_rates', 'shop_price_list' ],
		'shop_prints'       => [ 'print_presets' ],
		'shop_shipping'     => [ 'shipping_flat', 'free_shipping_over', 'shipping_countries' ],
		'shop_downloads'    => [ 'download_days' ],
		'shop_giftcards'    => [ 'gift_cards', 'gift_card_amounts', 'gift_card_months' ],
		'shop_receipts'     => [ 'business_name', 'business_details', 'receipt_note' ],
		'shop_payments'     => [ 'shop_email', 'manual_enabled', 'manual_title', 'manual_instructions' ],
		'general'           => [ 'watermark_id', 'watermark_default', 'watermark_position', 'watermark_scale', 'watermark_opacity', 'display_size', 'display_quality', 'display_format', 'snapotter_mode', 'snapotter_min_mp', 'stats_enabled', 'visitor_retention_days', 'terms_ask_email', 'signin_links', 'selection_client_email' ],
		'shop'              => [ 'currency', 'prices_include_tax', 'tax_rate', 'tax_rates', 'shipping_flat', 'free_shipping_over', 'shipping_countries', 'download_days', 'shop_page', 'shop_terms_page', 'shop_email', 'shop_price_list', 'manual_enabled', 'manual_title', 'manual_instructions', 'business_name', 'business_details', 'receipt_note' ],
	];
	public const CHECKBOXES = [ 'watermark_default', 'prices_include_tax', 'manual_enabled', 'stats_enabled', 'terms_ask_email', 'signin_links', 'selection_client_email', 'gift_cards', 'inbox_enabled' ];
	public const AMOUNTS    = [ 'shipping_flat', 'free_shipping_over' ];

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$swedish = str_starts_with( (string) get_locale(), 'sv' );
		return [
			'gallery_directory_page' => 0,
			'watermark_id'           => 0,
			'watermark_default'      => false,
			'watermark_position'     => 'center',
			'watermark_scale'        => 30,
			'watermark_opacity'      => 60,
			'display_size'           => 2560,
			'display_quality'        => 90,
			'display_format'         => 'jpeg',
			'snapotter_mode'         => 'heavy',
			'snapotter_min_mp'       => 40,
			'stats_enabled'          => true,
			'visitor_retention_days' => 365,
			'terms_ask_email'        => true,
			'selection_client_email' => true,
			'signin_links'           => true,
			'inbox_enabled'          => false,
			'inbox_user'             => 0,
			'currency'               => $swedish ? 'SEK' : 'EUR',
			'prices_include_tax'     => true,
			'tax_rate'               => 25.0,
			'tax_rates'              => [],
			'shipping_flat'          => 0,
			'free_shipping_over'     => 0,
			'print_presets'          => PrintPresets::IDS,
			'shipping_countries'     => $swedish ? 'SE' : '',
			'download_days'          => 30,
			'gift_cards'             => false,
			'gift_card_amounts'      => [],
			'gift_card_months'       => 24,
			'shop_page'              => 0,
			'shop_terms_page'        => 0,
			'shop_email'             => '',
			'shop_price_list'        => 0,
			'manual_enabled'         => true,
			'manual_title'           => '',
			'manual_instructions'    => '',
			'business_name'          => '',
			'business_details'       => '',
			'receipt_note'           => '',
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
			// New galleries made by the import folder belong to whoever switched it on.
			if ( 'general_inbox' === (string) $form['_section'] && ! empty( $form['inbox_enabled'] ) ) {
				$input['inbox_user'] = get_current_user_id();
			}
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
			'gallery_directory_page' => absint( $value( 'gallery_directory_page' ) ),
			'watermark_id'           => $id,
			'watermark_default'      => ! empty( $input['watermark_default'] ),
			'watermark_position'     => in_array( $position, self::POSITIONS, true ) ? $position : $defaults['watermark_position'],
			'watermark_scale'        => min( 100, max( 5, (int) $value( 'watermark_scale' ) ) ),
			'watermark_opacity'      => min( 100, max( 5, (int) $value( 'watermark_opacity' ) ) ),
			'display_size'           => min( 8192, max( 1024, (int) $value( 'display_size' ) ) ),
			'display_quality'        => min( 100, max( 60, (int) $value( 'display_quality' ) ) ),
			'display_format'         => in_array( $value( 'display_format' ), [ 'jpeg', 'webp', 'avif' ], true ) ? (string) $value( 'display_format' ) : 'jpeg',
			'snapotter_mode'         => in_array( $input['snapotter_mode'] ?? '', self::SNAPOTTER_MODES, true ) ? $input['snapotter_mode'] : $defaults['snapotter_mode'],
			'snapotter_min_mp'       => min( 1000, max( 1, (int) $value( 'snapotter_min_mp' ) ) ),
			'stats_enabled'          => (bool) $value( 'stats_enabled' ),
			'visitor_retention_days' => min( 3650, max( 0, (int) $value( 'visitor_retention_days' ) ) ),
			'terms_ask_email'        => (bool) $value( 'terms_ask_email' ),
			'selection_client_email' => (bool) $value( 'selection_client_email' ),
			'signin_links'           => (bool) $value( 'signin_links' ),
			'inbox_enabled'          => (bool) $value( 'inbox_enabled' ),
			'inbox_user'             => absint( $value( 'inbox_user' ) ),
			'currency'               => $currency,
			'prices_include_tax'     => (bool) $value( 'prices_include_tax' ),
			'tax_rate'               => self::rate( $value( 'tax_rate' ) ),
			'tax_rates'              => self::tax_rates( $value( 'tax_rates' ) ),
			'print_presets'          => PrintPresets::sanitize( $value( 'print_presets' ) ),
			'shipping_countries'     => implode( ', ', Countries::codes( $value( 'shipping_countries' ) ) ),
			'download_days'          => min( 3650, max( 0, (int) $value( 'download_days' ) ) ),
			'gift_cards'             => (bool) $value( 'gift_cards' ),
			'gift_card_amounts'      => self::amounts( $value( 'gift_card_amounts' ), $currency ),
			'gift_card_months'       => min( 120, max( 0, (int) $value( 'gift_card_months' ) ) ),
			'shop_page'              => absint( $value( 'shop_page' ) ),
			'shop_terms_page'        => absint( $value( 'shop_terms_page' ) ),
			'shop_email'             => is_email( (string) $value( 'shop_email' ) ) ? sanitize_email( (string) $value( 'shop_email' ) ) : '',
			'shop_price_list'        => absint( $value( 'shop_price_list' ) ),
			'manual_enabled'         => (bool) $value( 'manual_enabled' ),
			'manual_title'           => mb_substr( sanitize_text_field( (string) $value( 'manual_title' ) ), 0, 100 ),
			'manual_instructions'    => mb_substr( sanitize_textarea_field( (string) $value( 'manual_instructions' ) ), 0, 2000 ),
			'business_name'          => mb_substr( sanitize_text_field( (string) $value( 'business_name' ) ), 0, 200 ),
			'business_details'       => mb_substr( sanitize_textarea_field( (string) $value( 'business_details' ) ), 0, 1000 ),
			'receipt_note'           => mb_substr( sanitize_textarea_field( (string) $value( 'receipt_note' ) ), 0, 1000 ),
		];
		foreach ( self::AMOUNTS as $key ) {
			$clean[ $key ] = array_key_exists( $key . '_input', $input )
				? Money::parse( (string) $input[ $key . '_input' ], $currency )
				: max( 0, (int) $value( $key ) );
		}
		return $clean;
	}

	/**
	 * A VAT rate in percent, 0–100 with up to three decimals.
	 *
	 * @param mixed $value Raw value; a comma works as decimal mark.
	 */
	public static function rate( $value ): float {
		return min( 100.0, max( 0.0, round( (float) str_replace( ',', '.', is_scalar( $value ) ? (string) $value : '' ), 3 ) ) );
	}

	/**
	 * VAT rates by country: "DE 19" per line (or comma-separated), or a saved
	 * map. Unknown codes and malformed rows are dropped.
	 *
	 * @param mixed $value Text or array.
	 * @return array<string, float> Country code => rate.
	 */
	public static function tax_rates( $value ): array {
		$pairs = [];
		if ( is_string( $value ) ) {
			// A comma separates rows only before a country code, so "25,5" stays a rate.
			foreach ( (array) preg_split( '/[\r\n;]+|,(?=\s*[A-Za-z])/', $value ) as $row ) {
				if ( preg_match( '/^\s*([A-Za-z]{2})\s*[:=]?\s*(\d{1,3}(?:[.,]\d{1,3})?)\s*%?\s*$/D', (string) $row, $match ) ) {
					$pairs[ strtoupper( $match[1] ) ] = $match[2];
				}
			}
		} elseif ( is_array( $value ) ) {
			$pairs = $value;
		}
		$known = array_flip( Countries::all() );
		$rates = [];
		foreach ( $pairs as $code => $rate ) {
			$code = strtoupper( (string) $code );
			if ( isset( $known[ $code ] ) && is_scalar( $rate ) ) {
				$rates[ $code ] = self::rate( $rate );
			}
		}
		ksort( $rates );
		return $rates;
	}

	/**
	 * Saved VAT rates as editable text.
	 *
	 * @param array<string, float> $rates Country code => rate.
	 */
	public static function tax_rates_text( array $rates ): string {
		$lines = [];
		foreach ( $rates as $code => $rate ) {
			$lines[] = $code . ' ' . rtrim( rtrim( number_format( (float) $rate, 3, '.', '' ), '0' ), '.' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Gift card amounts in minor units, from "250, 500, 1000" (typed in the
	 * currency's major units, a point for decimals) or a saved list. At most
	 * eight, ascending.
	 *
	 * @param mixed  $value    Text or array.
	 * @param string $currency Currency.
	 * @return int[]
	 */
	public static function amounts( $value, string $currency ): array {
		$amounts = [];
		if ( is_string( $value ) ) {
			foreach ( (array) preg_split( '/[\s,;]+/', trim( $value ) ) as $part ) {
				if ( preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/D', (string) $part ) ) {
					$amounts[] = Money::parse( (string) $part, $currency );
				}
			}
		} elseif ( is_array( $value ) ) {
			$amounts = array_map( 'intval', $value );
		}
		$amounts = array_values( array_unique( array_filter( $amounts, static fn( int $amount ): bool => $amount > 0 ) ) );
		sort( $amounts );
		return array_slice( $amounts, 0, 8 );
	}
}
