<?php
/**
 * Delivery country codes.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

defined( 'ABSPATH' ) || exit;

/** Keeps the shop's country settings and checkout in agreement. */
final class Countries {

	/** ISO 3166-1 alpha-2 country and territory codes. */
	private const AVAILABLE = <<<'CODES'
AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ
BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR
CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR
GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU
ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ
LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ
MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF
PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI
SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR
TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW
CODES;

	/**
	 * Recognises both the old comma-delimited setting and checkbox form values.
	 * Accepts previously saved two-letter codes in addition to the picker list.
	 *
	 * @param mixed $value Country selection.
	 * @return string[] Unique country codes in input order.
	 */
	public static function codes( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value );
		}
		$codes = [];
		foreach ( (array) $value as $item ) {
			$code = is_string( $item ) ? strtoupper( trim( $item ) ) : '';
			if ( preg_match( '/^[A-Z]{2}$/D', $code ) ) {
				$codes[ $code ] = true;
			}
		}
		return array_keys( $codes );
	}

	/**
	 * Returns the picker's options in saved order, followed by other countries.
	 *
	 * @param string[] $selected Saved codes.
	 * @return string[]
	 */
	public static function options( array $selected ): array {
		return array_merge( $selected, array_diff( self::codes( self::AVAILABLE ), $selected ) );
	}

	/**
	 * Returns a localized name when the optional PHP Intl extension is present.
	 *
	 * @param string $code Country code.
	 */
	public static function name( string $code ): string {
		$name = function_exists( 'locale_get_display_region' ) ? locale_get_display_region( '_' . $code, determine_locale() ) : '';
		return $name ? $name : $code;
	}
}
