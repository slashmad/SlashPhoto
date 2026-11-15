<?php
/**
 * Amounts of money.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts are integers in the currency's smallest unit (öre, cents), as
 * Stripe expects them, so no floating-point rounding creeps into totals.
 */
final class Money {

	/**
	 * Currencies without a minor unit (Stripe's list).
	 */
	public const ZERO_DECIMAL = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];

	/**
	 * Currencies offered in the settings. Any ISO code works through the
	 * slashphoto_currencies filter.
	 */
	public const CURRENCIES = [ 'SEK', 'NOK', 'DKK', 'EUR', 'GBP', 'USD', 'CHF', 'PLN', 'CAD', 'AUD', 'NZD', 'JPY' ];

	/**
	 * Number of decimals of a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function decimals( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	/**
	 * Converts a typed price ("120", "99,50", "1 200.00") to minor units.
	 *
	 * @param mixed  $value    Input.
	 * @param string $currency Currency.
	 */
	public static function parse( $value, string $currency ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value * ( 10 ** self::decimals( $currency ) ) );
		}
		$text = preg_replace( '/[\s\x{00A0}\x{202F}]/u', '', (string) $value );
		$text = str_replace( ',', '.', (string) $text );
		// "1.200.50" → keep only the last dot as the decimal separator.
		if ( substr_count( $text, '.' ) > 1 ) {
			$last = strrpos( $text, '.' );
			$text = str_replace( '.', '', substr( $text, 0, $last ) ) . substr( $text, $last );
		}
		if ( ! is_numeric( $text ) ) {
			return 0;
		}
		return max( 0, (int) round( (float) $text * ( 10 ** self::decimals( $currency ) ) ) );
	}

	/**
	 * Minor units as a plain number for form fields ("99.50").
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function to_input( int $amount, string $currency ): string {
		$decimals = self::decimals( $currency );
		return number_format( $amount / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * Formats an amount for people, in the site's language.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function format( int $amount, string $currency ): string {
		$currency = strtoupper( $currency );
		$value    = $amount / ( 10 ** self::decimals( $currency ) );
		if ( class_exists( 'NumberFormatter' ) ) {
			$formatter = new \NumberFormatter( determine_locale(), \NumberFormatter::CURRENCY );
			if ( 0 === $amount % ( 10 ** self::decimals( $currency ) ) ) {
				// Whole amounts without ",00": "120 kr", "€15".
				$formatter->setAttribute( \NumberFormatter::FRACTION_DIGITS, 0 );
			}
			$text = $formatter->formatCurrency( $value, $currency );
			if ( false !== $text ) {
				return $text;
			}
		}
		$decimals = 0 === $amount % ( 10 ** self::decimals( $currency ) ) ? 0 : self::decimals( $currency );
		return number_format_i18n( $value, $decimals ) . ' ' . $currency;
	}

	/**
	 * Tax contained in, or added to, an amount.
	 *
	 * @param int   $amount   Minor units.
	 * @param float $rate     Percent, e.g. 25.
	 * @param bool  $included Whether the amount already includes the tax.
	 */
	public static function tax( int $amount, float $rate, bool $included ): int {
		if ( $rate <= 0 ) {
			return 0;
		}
		return $included ? (int) round( $amount - $amount / ( 1 + $rate / 100 ) ) : (int) round( $amount * $rate / 100 );
	}
}
