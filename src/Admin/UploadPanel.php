<?php
/**
 * Upload UI for full-size originals.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\Importer;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * "Upload photos" panel in the block editor and a box in the classic editor,
 * both using the chunked uploader.
 */
final class UploadPanel {

	public const UPLOADER = 'slashphoto-uploader';
	public const PANEL    = 'slashphoto-editor-upload';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_classic' ] );
		add_action( 'add_meta_boxes_' . PostType::POST_TYPE, [ $this, 'add_meta_box' ] );
	}

	/**
	 * Registers the shared uploader script with its settings.
	 */
	private function register_uploader(): void {
		if ( wp_script_is( self::UPLOADER, 'registered' ) ) {
			return;
		}
		wp_register_script( self::UPLOADER, SLASHPHOTO_URL . 'assets/js/uploader.js', [ 'wp-api-fetch', 'wp-i18n' ], Assets::version( 'assets/js/uploader.js' ), true );
		wp_set_script_translations( self::UPLOADER, 'slashphoto', SLASHPHOTO_DIR . 'languages' );
		wp_add_inline_script(
			self::UPLOADER,
			'window.slashphotoUploadConfig = ' . wp_json_encode(
				[
					'extensions'  => Importer::allowed_extensions(),
					'snapotter'   => Importer::snapotter_active(),
					'displaySize' => (int) Settings::get( 'display_size' ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Whether the current admin screen edits a gallery.
	 */
	private static function on_gallery_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && PostType::POST_TYPE === $screen->post_type && current_user_can( 'upload_files' );
	}

	/**
	 * Block editor panel.
	 */
	public function enqueue_block_editor(): void {
		if ( ! self::on_gallery_screen() ) {
			return;
		}
		$this->register_uploader();
		wp_enqueue_script(
			self::PANEL,
			SLASHPHOTO_URL . 'assets/js/editor-upload.js',
			[ self::UPLOADER, 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ],
			Assets::version( 'assets/js/editor-upload.js' ),
			true
		);
		wp_set_script_translations( self::PANEL, 'slashphoto', SLASHPHOTO_DIR . 'languages' );
	}

	/**
	 * Classic editor box script.
	 *
	 * @param string $hook Admin page.
	 */
	public function enqueue_classic( $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! self::on_gallery_screen() ) {
			return;
		}
		$this->register_uploader();
		wp_enqueue_script( self::UPLOADER );
	}

	/**
	 * Adds the classic-editor box (hidden in the block editor, which has the panel).
	 */
	public function add_meta_box(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		add_meta_box(
			'slashphoto-upload',
			__( 'Upload photos', 'slashphoto' ),
			[ $this, 'render_meta_box' ],
			PostType::POST_TYPE,
			'side',
			'high',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Renders the classic-editor box.
	 *
	 * @param WP_Post $post Gallery.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$accept = implode( ',', array_map( static fn( string $ext ): string => '.' . $ext, Importer::allowed_extensions() ) );
		?>
		<div data-slashphoto-upload="<?php echo esc_attr( (string) $post->ID ); ?>">
			<p class="description">
				<?php
				echo esc_html(
					Importer::snapotter_active()
						? __( 'Full-size originals, also RAW, HEIC, TIFF and PSD. SnapOtter makes the display copies.', 'slashphoto' )
						: __( 'Full-size originals (JPEG, PNG, WebP, AVIF). Display copies are made automatically.', 'slashphoto' )
				);
				?>
			</p>
			<input type="file" multiple accept="<?php echo esc_attr( $accept ); ?>" aria-label="<?php esc_attr_e( 'Choose photos to upload', 'slashphoto' ); ?>">
			<ul class="slashphoto-upload-list"></ul>
		</div>
		<?php
	}
}
