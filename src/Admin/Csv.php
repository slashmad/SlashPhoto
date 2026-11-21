<?php
/**
 * Spreadsheet-safe CSV cells.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

defined( 'ABSPATH' ) || exit;

/** Shared cell escaping for WordPress-admin exports. */
final class Csv {

	/**
	 * Keeps spreadsheet programs from interpreting text as a formula.
	 *
	 * @param mixed $value Cell.
	 */
	public static function safe( $value ): string {
		$value = (string) $value;
		return preg_match( '/^(?:[\x00-\x1f]|[\s\x{FEFF}]*[=+\-@])/u', $value ) ? "'" . $value : $value;
	}
}
