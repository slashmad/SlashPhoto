<?php
/**
 * Site-wide SlashPhoto settings.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the slashphoto_settings option.
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
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'watermark_id'       => 0,
			'watermark_default'  => false,
			'watermark_position' => 'center',
			'watermark_scale'    => 30,
			'watermark_opacity'  => 60,
			'display_size'       => 2560,
			'display_quality'    => 90,
			'snapotter_mode'     => 'heavy',
			'snapotter_min_mp'   => 40,
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
	 * Sanitises submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();

		$id = absint( $input['watermark_id'] ?? 0 );
		// Only transparent PNG files make sense as watermarks.
		if ( $id && 'image/png' !== get_post_mime_type( $id ) ) {
			$id = 0;
		}
		$position = (string) ( $input['watermark_position'] ?? $defaults['watermark_position'] );

		return [
			'watermark_id'       => $id,
			'watermark_default'  => ! empty( $input['watermark_default'] ),
			'watermark_position' => in_array( $position, self::POSITIONS, true ) ? $position : $defaults['watermark_position'],
			'watermark_scale'    => min( 100, max( 5, (int) ( $input['watermark_scale'] ?? $defaults['watermark_scale'] ) ) ),
			'watermark_opacity'  => min( 100, max( 5, (int) ( $input['watermark_opacity'] ?? $defaults['watermark_opacity'] ) ) ),
			'display_size'       => min( 8192, max( 1024, (int) ( $input['display_size'] ?? $defaults['display_size'] ) ) ),
			'display_quality'    => min( 100, max( 60, (int) ( $input['display_quality'] ?? $defaults['display_quality'] ) ) ),
			'snapotter_mode'     => in_array( $input['snapotter_mode'] ?? '', self::SNAPOTTER_MODES, true ) ? $input['snapotter_mode'] : $defaults['snapotter_mode'],
			'snapotter_min_mp'   => min( 1000, max( 1, (int) ( $input['snapotter_min_mp'] ?? $defaults['snapotter_min_mp'] ) ) ),
		];
	}
}
