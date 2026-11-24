<?php
/**
 * Calculates an optional print crop relative to the source.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

defined( 'ABSPATH' ) || exit;

/** Reads print dimensions and validates customer framing. */
final class PrintCrop {

	/**
	 * The product dimensions in inches, or [] when none were specified.
	 *
	 * @param string $label Product label, for example "Fine art 20 × 30 cm".
	 * @return float[]
	 */
	public static function format( string $label ): array {
		if ( ! preg_match( '/(\d+(?:[.,]\d+)?)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|in\b|″|")/iu', $label, $parts ) ) {
			return [];
		}
		$width  = (float) str_replace( ',', '.', $parts[1] );
		$height = (float) str_replace( ',', '.', $parts[2] );
		if ( $width < 1 || $height < 1 || $width > 10000 || $height > 10000 ) {
			return [];
		}
		$unit = strtolower( $parts[3] );
		$in   = 'mm' === $unit ? 25.4 : ( 'cm' === $unit ? 2.54 : 1.0 );
		return [ $width / $in, $height / $in ];
	}

	/**
	 * Crops to the print's ratio, orienting it to match the photo.
	 *
	 * @param int     $attachment Photo attachment.
	 * @param float[] $format     Print dimensions.
	 * @param int     $position   Alignment on the cropped axis, 0 to 100.
	 * @return string|null x:y:width:height, measured in ten-thousandths of the photo.
	 */
	public static function rectangle( int $attachment, array $format, int $position ): ?string {
		$photo = wp_get_attachment_metadata( $attachment );
		if ( ! $format || empty( $photo['width'] ) || empty( $photo['height'] ) || $position < 0 || $position > 100 ) {
			return null;
		}
		$source = (float) $photo['width'] / $photo['height'];
		$ratio  = max( $format ) / min( $format );
		$ratio  = $source < 1 ? 1 / $ratio : $ratio;
		$width  = min( 1, $ratio / $source );
		$height = min( 1, $source / $ratio );
		return implode(
			':',
			[
				(int) round( ( 1 - $width ) * $position * 100 ),
				(int) round( ( 1 - $height ) * $position * 100 ),
				(int) round( $width * 10000 ),
				(int) round( $height * 10000 ),
			]
		);
	}
}
