<?php
/**
 * The slashphoto/gallery block.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Blocks;

use SlashPhoto\Access\Access;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\GalleryRenderer;
use SlashPhoto\Gallery\PostType;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Places a gallery anywhere: on its own page in a custom layout, on another
 * page, or inside page-builder areas that render blocks.
 *
 * A block never shows more than the gallery page would: client-only and
 * expired galleries the visitor may not see render nothing, and password
 * galleries render a link to the gallery page where the password is asked.
 */
final class GalleryBlock {

	public const NAME = 'slashphoto/gallery';

	/**
	 * Gallery renderer.
	 *
	 * @var GalleryRenderer
	 */
	private GalleryRenderer $renderer;

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Constructor.
	 *
	 * @param GalleryRenderer $renderer  Gallery renderer.
	 * @param Access          $access    Access rules.
	 * @param TemplateLoader  $templates Template loader.
	 */
	public function __construct( GalleryRenderer $renderer, Access $access, TemplateLoader $templates ) {
		$this->renderer  = $renderer;
		$this->access    = $access;
		$this->templates = $templates;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 20 );
	}

	/**
	 * Registers the block type from block.json.
	 */
	public function register(): void {
		$type = register_block_type( SLASHPHOTO_DIR . 'blocks/gallery' );
		if ( $type && ! empty( $type->editor_script_handles[0] ) ) {
			wp_set_script_translations( $type->editor_script_handles[0], 'slashphoto', SLASHPHOTO_DIR . 'languages' );
		}
	}

	/**
	 * Renders the block.
	 *
	 * @param array         $attributes Block attributes.
	 * @param WP_Block|null $block      Block instance.
	 */
	public function render( array $attributes, ?WP_Block $block = null ): string {
		$id = (int) ( $attributes['galleryId'] ?? 0 );
		if ( ! $id ) {
			$id = (int) ( $block->context['postId'] ?? get_the_ID() );
		}
		$gallery = $id ? get_post( $id ) : null;
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type ) {
			return '';
		}

		if ( ! $this->access->can_view( $gallery ) ) {
			if ( $this->access->hidden_from_listings( $gallery ) || ( 'publish' !== $gallery->post_status ) ) {
				return '';
			}
			$markup = $this->templates->render(
				'access-notice.php',
				[
					'gallery' => $gallery,
					'type'    => 'password',
				]
			);
		} else {
			$markup = $this->renderer->render( $gallery );
			if ( '' === $markup ) {
				return '';
			}
			wp_enqueue_style( Assets::STYLE_HANDLE );
			wp_enqueue_script_module( Assets::LIGHTBOX_MODULE );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( [ 'class' => 'slashphoto-block' ] ), $markup );
	}
}
