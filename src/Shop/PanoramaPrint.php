<?php
/**
 * A reproducible print view from a complete equirectangular panorama.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Media\Panorama;

defined( 'ABSPATH' ) || exit;

/** Validates and snapshots a camera view separately from conventional crops. */
final class PanoramaPrint {

	/**
	 * Checks that the image covers a complete sphere without pitch or roll correction.
	 *
	 * @param int $image Photo attachment.
	 */
	public static function supported( int $image ): bool {
		$panorama = new Panorama();
		if ( '360' !== $panorama->kind( $image ) ) {
			return false;
		}
		$data = $panorama->pano_data( $image );
		return $data && empty( $data['posePitch'] ) && empty( $data['poseRoll'] )
			&& abs( $data['fullWidth'] - $data['croppedWidth'] ) <= 1
			&& abs( $data['fullHeight'] - $data['croppedHeight'] ) <= 1;
	}

	/**
	 * Stores the view and format used to render this line.
	 *
	 * @param int    $image Photo attachment.
	 * @param string $label Print product name.
	 * @param string $view  Yaw, pitch and vertical field of view in millidegrees.
	 */
	public static function snapshot( int $image, string $label, string $view ): string {
		$format = PrintCrop::format( $label );
		if ( ! $format || ! self::supported( $image ) || ! preg_match( '/^(-?\d{1,6}):(-?\d{1,5}):(\d{1,6})$/D', $view, $parts ) ) {
			return '';
		}
		[ $yaw, $pitch, $fov ] = array_map( 'intval', array_slice( $parts, 1 ) );
		if ( abs( $yaw ) > 180000 || abs( $pitch ) > 89500 || $fov < 15000 || $fov > 100000 ) {
			return '';
		}
		return (string) wp_json_encode(
			[
				'yaw'    => $yaw / 1000,
				'pitch'  => $pitch / 1000,
				'fov'    => $fov / 1000,
				'width'  => max( $format ),
				'height' => min( $format ),
			]
		);
	}
}
