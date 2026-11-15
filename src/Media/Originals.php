<?php
/**
 * Keeps original files out of reach of preview URLs.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the original of every gallery image into its own random folder.
 *
 * WordPress keeps the original next to its web copy: "photo-scaled.jpg" and
 * "photo.jpg". Anyone who sees the web copy can therefore fetch the
 * full-resolution original by removing "-scaled", which also defeats
 * watermarks. SlashPhoto stores the original as "o<96-bit random>/photo.jpg"
 * instead. WordPress resolves original_image relative to the attached file,
 * so editing, regenerating and deleting keep working.
 */
final class Originals {

	/**
	 * Registers hooks. Runs after panorama detection (10), before
	 * watermarking (20).
	 */
	public function register_hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'move_original' ], 15, 2 );
	}

	/**
	 * Moves the original file of a gallery image.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function move_original( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) || ! self::in_gallery( (int) $attachment_id ) ) {
			return $metadata;
		}
		// Already moved (regenerated images keep their folder).
		if ( str_contains( (string) $metadata['original_image'], '/' ) ) {
			return $metadata;
		}

		$dir    = dirname( (string) get_attached_file( (int) $attachment_id ) );
		$source = $dir . '/' . $metadata['original_image'];
		if ( ! is_file( $source ) ) {
			return $metadata;
		}

		$folder = 'o' . bin2hex( random_bytes( 12 ) );
		if ( ! wp_mkdir_p( $dir . '/' . $folder ) ) {
			return $metadata;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static file.
		file_put_contents( $dir . '/' . $folder . '/index.php', "<?php\n// Silence is golden.\n" );

		$target = $dir . '/' . $folder . '/' . $metadata['original_image'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same file system, atomic move.
		if ( rename( $source, $target ) ) {
			$metadata['original_image'] = $folder . '/' . $metadata['original_image'];
		}
		return $metadata;
	}

	/**
	 * Whether the attachment belongs to a gallery.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function in_gallery( int $attachment_id ): bool {
		$parent = (int) wp_get_post_parent_id( $attachment_id );
		return $parent && PostType::POST_TYPE === get_post_type( $parent );
	}
}
