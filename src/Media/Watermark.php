<?php
/**
 * Watermarked previews.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stamps a watermark on every web copy of images uploaded to galleries that
 * have watermarks switched on. The original is never touched: DisplayCopy
 * makes WordPress create a separate display copy for every gallery image,
 * and Originals moves the original out of reach.
 *
 * 360° panoramas are not watermarked: a flat stamp would be distorted on
 * the sphere.
 *
 * Applies to images uploaded after watermarks are switched on.
 */
final class Watermark {

	public const META = '_slashphoto_watermark';

	/**
	 * Access rules (for the ancestor chain).
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Constructor.
	 *
	 * @param Access $access Access rules.
	 */
	public function __construct( Access $access ) {
		$this->access = $access;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'stamp_sizes' ], 20, 2 );
	}

	/**
	 * Whether images of the gallery get a watermark: the nearest explicit
	 * setting up the tree wins, otherwise the site default, and only when a
	 * watermark image is configured.
	 *
	 * @param int $gallery_id Gallery ID.
	 */
	public function enabled_for( int $gallery_id ): bool {
		$gallery = get_post( $gallery_id );
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type || ! self::watermark_file() ) {
			return false;
		}
		foreach ( $this->access->chain( $gallery ) as $post ) {
			$value = (string) get_post_meta( $post->ID, self::META, true );
			if ( 'on' === $value || 'off' === $value ) {
				return 'on' === $value;
			}
		}
		return (bool) Settings::get( 'watermark_default' );
	}

	/**
	 * Path of the configured watermark PNG, or null.
	 */
	public static function watermark_file(): ?string {
		$id   = (int) Settings::get( 'watermark_id' );
		$file = $id ? get_attached_file( $id ) : '';
		return $file && is_readable( $file ) ? $file : null;
	}

	/**
	 * Stamps the display copy and every generated size.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function stamp_sizes( $metadata, $attachment_id ) {
		$parent = (int) wp_get_post_parent_id( (int) $attachment_id );
		// Without a separate original there is nothing safe to stamp.
		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) || ! empty( $metadata['slashphoto_watermarked'] ) || ! $parent || ! $this->enabled_for( $parent ) ) {
			return $metadata;
		}
		$main = (string) get_attached_file( (int) $attachment_id );
		// Use the in-flight metadata: Originals may just have moved the file.
		if ( null !== Panorama::detect_file( dirname( $main ) . '/' . $metadata['original_image'] ) ) {
			return $metadata;
		}

		$files = [ $main ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = dirname( $main ) . '/' . $size['file'];
			}
		}

		$watermark = (string) self::watermark_file();
		$options   = Settings::all();
		$stamped   = 0;
		foreach ( array_unique( $files ) as $file ) {
			if ( is_file( $file ) && self::apply( $file, $watermark, $options ) ) {
				++$stamped;
			}
		}
		if ( $stamped ) {
			$metadata['slashphoto_watermarked'] = true;
		}
		return $metadata;
	}

	/**
	 * Stamps one image file in place.
	 *
	 * @param string $file      Image to stamp.
	 * @param string $watermark Watermark PNG.
	 * @param array  $options   position, scale (% of width), opacity (%).
	 */
	public static function apply( string $file, string $watermark, array $options ): bool {
		$position = (string) ( $options['watermark_position'] ?? 'center' );
		$scale    = min( 100, max( 5, (int) ( $options['watermark_scale'] ?? 30 ) ) ) / 100;
		$opacity  = min( 100, max( 5, (int) ( $options['watermark_opacity'] ?? 60 ) ) ) / 100;

		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			try {
				return self::apply_imagick( $file, $watermark, $position, $scale, $opacity );
			} catch ( \Throwable $e ) {
				// Fall back to GD.
				unset( $e );
			}
		}
		return function_exists( 'imagecreatefromstring' ) && self::apply_gd( $file, $watermark, $position, $scale, $opacity );
	}

	/**
	 * Returns [x, y] positions for a stamp of size $mw x $mh on $w x $h.
	 *
	 * @param string $position Position name.
	 * @param int    $w        Image width.
	 * @param int    $h        Image height.
	 * @param int    $mw       Stamp width.
	 * @param int    $mh       Stamp height.
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function positions( string $position, int $w, int $h, int $mw, int $mh ): array {
		$margin = (int) round( min( $w, $h ) * 0.03 );
		switch ( $position ) {
			case 'top-left':
				return [ [ $margin, $margin ] ];
			case 'top-right':
				return [ [ $w - $mw - $margin, $margin ] ];
			case 'bottom-left':
				return [ [ $margin, $h - $mh - $margin ] ];
			case 'bottom-right':
				return [ [ $w - $mw - $margin, $h - $mh - $margin ] ];
			case 'tile':
				$points = [];
				$step_x = max( 1, (int) round( $mw * 1.6 ) );
				$step_y = max( 1, (int) round( $mh * 2.2 ) );
				for ( $y = $margin, $row = 0; $y < $h; $y += $step_y, $row++ ) {
					for ( $x = ( $row % 2 ) ? (int) ( $step_x / 2 ) : 0; $x < $w; $x += $step_x ) {
						$points[] = [ $x, $y ];
					}
				}
				return $points;
			default:
				return [ [ (int) ( ( $w - $mw ) / 2 ), (int) ( ( $h - $mh ) / 2 ) ] ];
		}//end switch
	}

	/**
	 * Imagick implementation (keeps the colour profile).
	 *
	 * @param string $file      Image.
	 * @param string $watermark Watermark PNG.
	 * @param string $position  Position.
	 * @param float  $scale     Stamp width as a fraction of the image width.
	 * @param float  $opacity   0..1.
	 */
	private static function apply_imagick( string $file, string $watermark, string $position, float $scale, float $opacity ): bool {
		$image = new \Imagick( $file );
		$mark  = new \Imagick( $watermark );
		$w     = $image->getImageWidth();
		$h     = $image->getImageHeight();
		$mw    = max( 1, (int) round( $w * ( 'tile' === $position ? min( $scale, 0.25 ) : $scale ) ) );
		$mh    = max( 1, (int) round( $mark->getImageHeight() * $mw / max( 1, $mark->getImageWidth() ) ) );

		$mark->setImageAlphaChannel( \Imagick::ALPHACHANNEL_SET );
		$mark->resizeImage( $mw, $mh, \Imagick::FILTER_LANCZOS, 1 );
		$mark->evaluateImage( \Imagick::EVALUATE_MULTIPLY, $opacity, \Imagick::CHANNEL_ALPHA );

		foreach ( self::positions( $position, $w, $h, $mw, $mh ) as [ $x, $y ] ) {
			$image->compositeImage( $mark, \Imagick::COMPOSITE_OVER, $x, $y );
		}
		$image->setImageCompressionQuality(
			(int) apply_filters(
				'wp_editor_set_quality', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				82,
				$image->getImageMimeType()
			)
		);
		$ok = $image->writeImage( $file );
		$image->clear();
		$mark->clear();
		return (bool) $ok;
	}

	/**
	 * GD implementation.
	 *
	 * @param string $file      Image.
	 * @param string $watermark Watermark PNG.
	 * @param string $position  Position.
	 * @param float  $scale     Stamp width as a fraction of the image width.
	 * @param float  $opacity   0..1.
	 */
	private static function apply_gd( string $file, string $watermark, string $position, float $scale, float $opacity ): bool {
		$info = wp_getimagesize( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local files.
		$image = $info ? imagecreatefromstring( (string) file_get_contents( $file ) ) : false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local files.
		$mark = imagecreatefromstring( (string) file_get_contents( $watermark ) );
		if ( ! $image || ! $mark ) {
			return false;
		}
		$w  = imagesx( $image );
		$h  = imagesy( $image );
		$mw = max( 1, (int) round( $w * ( 'tile' === $position ? min( $scale, 0.25 ) : $scale ) ) );
		$mh = max( 1, (int) round( imagesy( $mark ) * $mw / max( 1, imagesx( $mark ) ) ) );

		$scaled = imagecreatetruecolor( $mw, $mh );
		imagealphablending( $scaled, false );
		imagesavealpha( $scaled, true );
		imagecopyresampled( $scaled, $mark, 0, 0, 0, 0, $mw, $mh, imagesx( $mark ), imagesy( $mark ) );

		// Multiply the stamp's alpha by $opacity (GD alpha: 0 opaque, 127 transparent).
		for ( $x = 0; $x < $mw; $x++ ) {
			for ( $y = 0; $y < $mh; $y++ ) {
				$rgba  = imagecolorat( $scaled, $x, $y );
				$alpha = 127 - (int) round( ( 127 - ( ( $rgba >> 24 ) & 0x7F ) ) * $opacity );
				imagesetpixel( $scaled, $x, $y, imagecolorallocatealpha( $scaled, ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF, $alpha ) );
			}
		}

		imagealphablending( $image, true );
		foreach ( self::positions( $position, $w, $h, $mw, $mh ) as [ $x, $y ] ) {
			imagecopy( $image, $scaled, $x, $y, 0, 0, $mw, $mh );
		}

		$quality = (int) apply_filters(
			'wp_editor_set_quality', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			82,
			$info['mime'] ?? 'image/jpeg'
		);
		switch ( $info['mime'] ?? '' ) {
			case 'image/png':
				$ok = imagepng( $image, $file );
				break;
			case 'image/webp':
				$ok = function_exists( 'imagewebp' ) && imagewebp( $image, $file, $quality );
				break;
			default:
				$ok = imagejpeg( $image, $file, $quality );
		}
		return (bool) $ok;
	}
}
