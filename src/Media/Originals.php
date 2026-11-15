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
 * instead, with deny rules for servers that read .htaccess. WordPress
 * resolves original_image relative to the attached file, so editing,
 * regenerating and deleting keep working.
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

		$relative = self::store( $source, $dir, (string) $metadata['original_image'] );
		if ( null !== $relative ) {
			$metadata['original_image'] = $relative;
		}
		return $metadata;
	}

	/**
	 * Moves a file into a new random originals folder next to the display
	 * copy and returns its path relative to that copy's folder.
	 *
	 * @param string $source File to move.
	 * @param string $dir    Folder of the display copy.
	 * @param string $name   File name to use.
	 */
	public static function store( string $source, string $dir, string $name ): ?string {
		$folder = 'o' . bin2hex( random_bytes( 12 ) );
		if ( ! wp_mkdir_p( $dir . '/' . $folder ) ) {
			return null;
		}
		self::protect( $dir . '/' . $folder );

		$name = sanitize_file_name( wp_basename( $name ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same file system, atomic move.
		return rename( $source, $dir . '/' . $folder . '/' . $name ) ? $folder . '/' . $name : null;
	}

	/**
	 * Blocks direct web access to an originals folder where the server reads
	 * .htaccess (Apache, LiteSpeed). Other servers need the rule from
	 * docs/security-and-privacy.md; the random folder name protects them
	 * meanwhile.
	 *
	 * @param string $folder Absolute path.
	 */
	public static function protect( string $folder ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static files.
		file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents(
			$folder . '/.htaccess',
			"# SlashPhoto: originals are never served directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
		);
		// phpcs:enable
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
