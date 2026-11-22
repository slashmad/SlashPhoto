<?php
/**
 * Finds gallery photos by their text and camera metadata.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

use SlashPhoto\Proofing\Proofing;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Each gallery photo gets a lower-case search text in post meta: title,
 * caption, alt text, the file name used in Lightroom, IPTC keywords, title
 * and caption, credit and copyright, camera make and model, lens, and the
 * capture date (Y-m-d and year-month). WordPress reads most of these from
 * the uploaded file; the lens and the camera make are added here.
 *
 * The text is written when the photo is uploaded or edited, and photos
 * uploaded earlier are indexed the first time a search reaches them.
 * Search terms must all match (anywhere in the text).
 */
final class PhotoSearch {

	public const META = '_slashphoto_search';

	/** Bumped when the indexed fields change, so old texts are rebuilt. */
	private const VERSION = '1';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_read_image_metadata', [ self::class, 'camera_details' ], 10, 5 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_generate' ], 30, 2 );
		add_action( 'attachment_updated', [ $this, 'on_update' ] );
	}

	/**
	 * Adds the lens and camera make to the metadata WordPress reads.
	 *
	 * @param array  $meta       Image metadata.
	 * @param string $file       File.
	 * @param int    $image_type Image type.
	 * @param array  $iptc       IPTC data.
	 * @param array  $exif       EXIF data.
	 * @return array
	 */
	public static function camera_details( $meta, $file = '', $image_type = 0, $iptc = [], $exif = [] ) {
		unset( $file, $image_type, $iptc );
		if ( ! is_array( $meta ) || ! is_array( $exif ) ) {
			return $meta;
		}
		$lens = $exif['UndefinedTag:0xA434'] ?? ( $exif['LensModel'] ?? '' );
		if ( is_string( $lens ) && '' !== trim( $lens ) ) {
			$meta['lens'] = trim( wp_strip_all_tags( $lens ) );
		}
		if ( isset( $exif['Make'] ) && is_string( $exif['Make'] ) && '' !== trim( $exif['Make'] ) ) {
			$meta['make'] = trim( wp_strip_all_tags( $exif['Make'] ) );
		}
		return $meta;
	}

	/**
	 * Indexes a photo when WordPress has made its metadata.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment.
	 * @return array
	 */
	public function on_generate( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) && Originals::in_gallery( (int) $attachment_id ) ) {
			self::index( (int) $attachment_id, $metadata );
		}
		return $metadata;
	}

	/**
	 * Reindexes a photo after its title, caption or alt text changed.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public function on_update( $attachment_id ): void {
		if ( Originals::in_gallery( (int) $attachment_id ) ) {
			self::index( (int) $attachment_id );
		}
	}

	/**
	 * The search text of a photo.
	 *
	 * @param int        $id       Attachment.
	 * @param array|null $metadata Metadata being saved, or null to read it.
	 */
	public static function text( int $id, ?array $metadata = null ): string {
		$post     = get_post( $id );
		$metadata = $metadata ?? wp_get_attachment_metadata( $id );
		$image    = is_array( $metadata['image_meta'] ?? null ) ? $metadata['image_meta'] : [];
		if ( ! isset( $image['lens'] ) && ! isset( $image['make'] ) ) {
			// Photos uploaded before the lens and make were read.
			$image += self::exif_details( $id );
		}
		$parts = [
			$post ? $post->post_title : '',
			$post ? $post->post_excerpt : '',
			(string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			Proofing::file_label( $id ),
		];
		foreach ( [ 'title', 'caption', 'credit', 'copyright', 'make', 'camera', 'lens' ] as $key ) {
			$parts[] = is_scalar( $image[ $key ] ?? null ) ? (string) $image[ $key ] : '';
		}
		foreach ( (array) ( $image['keywords'] ?? [] ) as $keyword ) {
			$parts[] = is_scalar( $keyword ) ? (string) $keyword : '';
		}
		$taken = (int) ( $image['created_timestamp'] ?? 0 );
		if ( $taken > 0 ) {
			$parts[] = gmdate( 'Y-m-d', $taken );
		}
		$text = mb_strtolower( wp_strip_all_tags( implode( ' ', array_filter( array_map( 'trim', $parts ) ) ) ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Lens and make read straight from the original's EXIF.
	 *
	 * @param int $id Attachment.
	 * @return array{lens?: string, make?: string}
	 */
	private static function exif_details( int $id ): array {
		$file = (string) wp_get_original_image_path( $id );
		if ( '' === $file || ! is_readable( $file ) || ! function_exists( 'exif_read_data' ) || ! in_array( strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'jpe', 'tif', 'tiff' ], true ) ) {
			return [];
		}
		$exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- broken EXIF must not stop indexing.
		return is_array( $exif ) ? array_intersect_key( self::camera_details( [], '', 0, [], $exif ), array_flip( [ 'lens', 'make' ] ) ) : [];
	}

	/**
	 * Stores the search text of a photo.
	 *
	 * @param int        $id       Attachment.
	 * @param array|null $metadata Metadata being saved, or null to read it.
	 */
	public static function index( int $id, ?array $metadata = null ): string {
		$text = self::text( $id, $metadata );
		update_post_meta( $id, self::META, self::VERSION . '|' . $text );
		return $text;
	}

	/**
	 * The stored text, indexing the photo first when needed.
	 *
	 * @param int $id Attachment.
	 */
	private static function stored( int $id ): string {
		$value = (string) get_post_meta( $id, self::META, true );
		if ( str_starts_with( $value, self::VERSION . '|' ) ) {
			return substr( $value, strlen( self::VERSION ) + 1 );
		}
		return self::index( $id );
	}

	/**
	 * Photos whose text contains every term of the query.
	 *
	 * @param WP_Post[] $images Photos to search, in their order.
	 * @param string    $query  Typed search.
	 * @return WP_Post[]
	 */
	public static function filter( array $images, string $query ): array {
		$split = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ) );
		$terms = array_filter( false === $split ? [] : $split );
		if ( ! $terms ) {
			return $images;
		}
		update_meta_cache( 'post', array_map( static fn( WP_Post $image ): int => $image->ID, $images ) );
		return array_values(
			array_filter(
				$images,
				static function ( WP_Post $image ) use ( $terms ): bool {
					$text = self::stored( $image->ID );
					foreach ( $terms as $term ) {
						if ( ! str_contains( $text, $term ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * A short line with the capture date and camera, for photo lists.
	 *
	 * @param int $id Attachment.
	 */
	public static function summary( int $id ): string {
		$meta  = wp_get_attachment_metadata( $id );
		$image = is_array( $meta['image_meta'] ?? null ) ? $meta['image_meta'] : [];
		$taken = (int) ( $image['created_timestamp'] ?? 0 );
		$parts = [];
		if ( $taken > 0 ) {
			$parts[] = wp_date( (string) get_option( 'date_format' ), $taken, new \DateTimeZone( 'UTC' ) );
		}
		$camera = trim( (string) ( $image['camera'] ?? '' ) );
		if ( '' !== $camera ) {
			$parts[] = $camera;
		}
		return implode( ' · ', $parts );
	}
}
