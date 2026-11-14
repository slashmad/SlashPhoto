<?php
/**
 * Test image helpers.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

/**
 * Creates small JPEG files, optionally with an XMP packet.
 */
final class TestImages {

	/**
	 * Writes a JPEG to a temporary file and returns its path.
	 *
	 * @param int         $width  Width in pixels.
	 * @param int         $height Height in pixels.
	 * @param string|null $xmp    XMP packet to embed in an APP1 segment.
	 */
	public static function jpeg( int $width, int $height, ?string $xmp = null ): string {
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 90, 140 ) );
		ob_start();
		imagejpeg( $image, null, 80 );
		$jpeg = (string) ob_get_clean();
		imagedestroy( $image );

		if ( null !== $xmp ) {
			// Cameras write an Exif APP1 first and the XMP APP1 after it;
			// PHP's exif_read_data() warns when the first APP1 is not Exif.
			$exif     = "Exif\0\0II*\0" . pack( 'V', 8 ) . pack( 'v', 0 ) . pack( 'V', 0 );
			$xmp_app1 = "http://ns.adobe.com/xap/1.0/\0" . $xmp;
			$segments = self::app1( $exif ) . self::app1( $xmp_app1 );
			// Insert right after the SOI marker (FFD8).
			$jpeg = substr( $jpeg, 0, 2 ) . $segments . substr( $jpeg, 2 );
		}

		$file = wp_tempnam( 'slashphoto-test.jpg' );
		$path = preg_replace( '/\.tmp$/', '', $file ) . '.jpg';
		file_put_contents( $path, $jpeg );
		if ( $file !== $path && file_exists( $file ) ) {
			unlink( $file );
		}
		return $path;
	}

	/**
	 * Wraps a payload in a JPEG APP1 segment.
	 *
	 * @param string $payload Segment payload including its identifier.
	 */
	private static function app1( string $payload ): string {
		return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * Returns a GPano XMP packet for an equirectangular image.
	 *
	 * @param int  $width          Full panorama width.
	 * @param int  $height         Full panorama height.
	 * @param bool $element_syntax Use element instead of attribute syntax.
	 */
	public static function gpano_xmp( int $width, int $height, bool $element_syntax = false ): string {
		$ns = 'xmlns:GPano="http://ns.google.com/photos/1.0/panorama/"';
		if ( $element_syntax ) {
			$body = "<rdf:Description rdf:about=\"\" {$ns}>"
				. '<GPano:ProjectionType>equirectangular</GPano:ProjectionType>'
				. "<GPano:FullPanoWidthPixels>{$width}</GPano:FullPanoWidthPixels>"
				. "<GPano:FullPanoHeightPixels>{$height}</GPano:FullPanoHeightPixels>"
				. '<GPano:PoseHeadingDegrees>90.5</GPano:PoseHeadingDegrees>'
				. '</rdf:Description>';
		} else {
			$body = "<rdf:Description rdf:about=\"\" {$ns}"
				. ' GPano:ProjectionType="equirectangular" GPano:UsePanoramaViewer="True"'
				. " GPano:FullPanoWidthPixels=\"{$width}\" GPano:FullPanoHeightPixels=\"{$height}\""
				. " GPano:CroppedAreaImageWidthPixels=\"{$width}\" GPano:CroppedAreaImageHeightPixels=\"{$height}\""
				. ' GPano:CroppedAreaLeftPixels="0" GPano:CroppedAreaTopPixels="0"/>';
		}

		return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. $body
			. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
	}
}
