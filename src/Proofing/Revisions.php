<?php
/**
 * Private before/after previews for a selected photo.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use SlashPhoto\Access\Access;
use SlashPhoto\Admin\GalleryWorkspace;
use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\Originals;
use SlashPhoto\Media\Panorama;
use SlashPhoto\Media\Watermark;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Keeps a client edit alongside the original attachment and selection notes. */
final class Revisions {

	private const META   = '_slashphoto_client_revision';
	private const QUERY  = 'slashphoto_revision';
	private const ACTION = 'slashphoto_upload_revision';

	/**
	 * Uses the same access and terms as proofing.
	 *
	 * @param Access   $access   Gallery access.
	 * @param Proofing $proofing Selection access.
	 */
	public function __construct( private Access $access, private Proofing $proofing ) {}

	/** Registers revision delivery and the authenticated upload action. */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'serve' ], 99 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'upload' ] );
		add_action( 'delete_attachment', [ self::class, 'delete_files' ] );
	}

	/**
	 * Visible revision URLs for a gallery's current page of photos.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @param int[]   $ids     Photos.
	 * @return array<int, string>
	 */
	public function urls( WP_Post $gallery, array $ids ): array {
		if ( ! $this->access->is_protected( $gallery ) || ! $this->proofing->can_see( $gallery ) || ! is_user_logged_in() ) {
			return [];
		}
		if ( ! $this->access->is_editor( $gallery ) ) {
			$root      = $this->proofing->root( $gallery );
			$selection = $root && $this->proofing->can_select( $root ) ? Selections::find( $root->ID, get_current_user_id() ) : null;
			$ids       = $selection ? array_intersect( $ids, array_keys( Selections::items( $selection ) ) ) : [];
		}
		$urls = [];
		foreach ( $ids as $id ) {
			$photo = get_post( $id );
			if ( $photo && $gallery->ID === (int) $photo->post_parent && 'attachment' === $photo->post_type && 'inherit' === $photo->post_status && wp_attachment_is_image( $photo ) && self::latest( $id ) ) {
				$urls[ $id ] = add_query_arg( self::QUERY, $id, home_url( '/' ) );
			}
		}
		return $urls;
	}

	/** Streams a display-only JPEG to an eligible viewer. */
	public function serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only; recheck session and selection.
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$id = is_scalar( $_GET[ self::QUERY ] ) ? absint( $_GET[ self::QUERY ] ) : 0;
		// phpcs:enable
		$parent  = $id ? wp_get_post_parent_id( $id ) : 0;
		$gallery = $parent ? get_post( $parent ) : null;
		$path    = $gallery && isset( $this->urls( $gallery, [ $id ] )[ $id ] ) ? self::path( self::latest( $id ) ) . '/preview.jpg' : '';
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary JPEG.
		readfile( $path );
		exit;
	}

	/**
	 * Form for attaching a revision to the exact photo chosen in the workspace.
	 *
	 * @param WP_Post $photo Gallery attachment.
	 */
	public static function form( WP_Post $photo ): void {
		?>
		<details>
			<summary><?php esc_html_e( 'Client revision', 'slashphoto' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Clients who selected this photo can compare the new version in the lightbox while this gallery is protected.', 'slashphoto' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="photo" value="<?php echo esc_attr( (string) $photo->ID ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $photo->ID ); ?>
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Revision image', 'slashphoto' ); ?></span>
					<input type="file" name="revision" accept="image/jpeg,image/png,image/webp" required>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Upload revision', 'slashphoto' ); ?></button>
			</form>
		</details>
		<?php
	}

	/** Uploads a flat JPEG, PNG or WebP and creates a private comparison copy. */
	public function upload(): void {
		$id = isset( $_POST['photo'] ) ? absint( $_POST['photo'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$photo   = $id ? get_post( $id ) : null;
		$gallery = $photo && $photo->post_parent ? get_post( $photo->post_parent ) : null;
		if ( ! $photo || ! $gallery || ! Originals::in_gallery( $id ) || ! $this->access->is_protected( $gallery ) || ! $this->access->is_editor( $gallery ) || ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $photo ) || '360' === ( new Panorama() )->kind( $id ) ) {
			wp_die( esc_html__( 'You cannot edit this gallery.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		$file      = isset( $_FILES['revision'] ) ? $_FILES['revision'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The temporary path and MIME type are checked below.
		$tmp       = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$mime      = $tmp && is_uploaded_file( $tmp ) ? wp_get_image_mime( $tmp ) : false;
		$ext       = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		][ $mime ] ?? '';
		$watermark = ( new Watermark( $this->access ) )->enabled_for( $gallery->ID ) ? Watermark::watermark_file() : null;
		if ( '' === $ext || ! self::save( $id, $tmp, $ext, $watermark ) ) {
			wp_die( esc_html__( 'The revision could not be saved. Choose a JPEG, PNG or WebP image within the upload limit.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}
		wp_safe_redirect( add_query_arg( 'revision', 'saved', GalleryWorkspace::url( $gallery->ID ) ), 303 );
		exit;
	}

	/**
	 * Moves the uploaded revision into private storage and links it to the photo.
	 *
	 * @param int         $id        Photo ID.
	 * @param string      $tmp       Validated upload path.
	 * @param string      $ext       Validated extension.
	 * @param string|null $watermark Configured watermark file.
	 */
	private static function save( int $id, string $tmp, string $ext, ?string $watermark ): bool {
		wp_raise_memory_limit( 'image' );
		$directory = self::path();
		$stored    = Originals::store( $tmp, $directory, 'revision.' . $ext );
		if ( ! $stored ) {
			return false;
		}
		$folder = dirname( $stored );
		$editor = wp_get_image_editor( $directory . '/' . $stored );
		if ( ! is_wp_error( $editor ) ) {
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			$size    = $editor->get_size();
			$resized = max( $size ) <= 1920 || ! is_wp_error( $editor->resize( 1920, 1920, false ) );
			$preview = self::path( $folder ) . '/preview.jpg';
			$saved   = $resized && ! is_wp_error( $editor->save( $preview, 'image/jpeg' ) );
			if ( $saved && ( ! $watermark || Watermark::apply( $preview, $watermark, Settings::all() ) ) && add_post_meta( $id, self::META, $folder ) ) {
				return true;
			}
		}
		self::remove_folder( $folder );
		return false;
	}

	/**
	 * Reads the latest private folder linked to a photo.
	 *
	 * @param int $id Photo.
	 */
	private static function latest( int $id ): string {
		$folders = get_post_meta( $id, self::META, false );
		$folder  = end( $folders );
		return is_string( $folder ) && preg_match( '/^o[a-f0-9]{24}$/D', $folder ) ? $folder : '';
	}

	/**
	 * Storage shared by all revision uploads.
	 *
	 * @param string $folder Optional private subfolder.
	 */
	private static function path( string $folder = '' ): string {
		$uploads = wp_get_upload_dir();
		return $uploads['basedir'] . '/' . Uploads::BASE_DIR . '/revisions' . ( '' !== $folder ? '/' . $folder : '' );
	}

	/**
	 * Removes only the files of a known revision.
	 *
	 * @param string $folder The revision's random folder.
	 */
	private static function remove_folder( string $folder ): void {
		if ( ! preg_match( '/^o[a-f0-9]{24}$/D', $folder ) ) {
			return;
		}
		$path = self::path( $folder );
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( [ 'revision.jpg', 'revision.png', 'revision.webp', 'preview.jpg', '.htaccess', 'index.php' ] as $name ) {
			wp_delete_file( $path . '/' . $name );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only the known, now empty revision folder.
		rmdir( $path );
	}

	/**
	 * Cleans up linked revisions when the original attachment is deleted.
	 *
	 * @param int $id Photo.
	 */
	public static function delete_files( int $id ): void {
		foreach ( get_post_meta( $id, self::META, false ) as $folder ) {
			if ( is_string( $folder ) ) {
				self::remove_folder( $folder );
			}
		}
	}
}
