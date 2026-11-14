<?php
/**
 * Theme compatibility.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Media\Panorama;

defined( 'ABSPATH' ) || exit;

/**
 * Puts gallery output inside whatever layout the active theme provides.
 *
 * SlashPhoto never replaces the theme's page template by default. A gallery
 * is rendered by the theme's own single template (classic PHP or block
 * template), and the plugin appends its markup to the post content. That way
 * header, footer, sidebars, content width and typography always come from the
 * theme. Themes that want full control can provide single-slashphoto_gallery.php
 * or single-slashphoto_gallery.html through the normal template hierarchy, and
 * can override every partial in a slashphoto/ folder. See
 * docs/theme-compatibility.md.
 */
final class ThemeCompat {

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Gallery queries.
	 *
	 * @var GalleryRepository
	 */
	private GalleryRepository $galleries;

	/**
	 * Panorama detection.
	 *
	 * @var Panorama
	 */
	private Panorama $panorama;

	/**
	 * Constructor.
	 *
	 * @param TemplateLoader    $templates Template loader.
	 * @param GalleryRepository $galleries Gallery queries.
	 * @param Panorama          $panorama  Panorama detection.
	 */
	public function __construct( TemplateLoader $templates, GalleryRepository $galleries, Panorama $panorama ) {
		$this->templates = $templates;
		$this->galleries = $galleries;
		$this->panorama  = $panorama;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Late priority: run after wpautop, shortcodes and blocks have rendered.
		add_filter( 'the_content', [ $this, 'append_gallery' ], 20 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Appends the gallery markup to the content of a single gallery.
	 *
	 * @param string $content Post content.
	 */
	public function append_gallery( $content ) {
		$post = get_post();
		if ( ! $post || ! $this->should_append( $post ) ) {
			return $content;
		}

		$markup = $this->templates->render(
			'gallery.php',
			[
				'gallery'  => $post,
				'children' => $this->galleries->children( $post->ID ),
				'items'    => $this->items( $post->ID ),
				'align'    => $this->alignment( $post ),
			]
		);

		return $content . $markup;
	}

	/**
	 * Builds the image list for the gallery template.
	 *
	 * @param int $gallery_id Gallery post ID.
	 * @return array<int, array{attachment: \WP_Post, kind: string, panorama: array}>
	 */
	public function items( int $gallery_id ): array {
		$items = [];
		foreach ( $this->galleries->images( $gallery_id ) as $attachment ) {
			$kind    = $this->panorama->kind( $attachment->ID );
			$items[] = [
				'attachment' => $attachment,
				'kind'       => $kind,
				'panorama'   => '360' === $kind ? $this->panorama->pano_data( $attachment->ID ) : [],
			];
		}
		return $items;
	}

	/**
	 * Returns the gallery alignment: "wide" by default, so galleries use the
	 * theme's own wide width (theme.json wideSize, or the .alignwide rules of
	 * classic themes) instead of the narrower text column.
	 *
	 * @param \WP_Post $post The gallery.
	 */
	public function alignment( \WP_Post $post ): string {
		$align = current_theme_supports( 'align-wide' ) || wp_is_block_theme() ? 'wide' : '';

		/**
		 * Filters the gallery alignment.
		 *
		 * @param string   $align "wide", "full" or "" for the content width.
		 * @param \WP_Post $post  The gallery.
		 */
		$align = (string) apply_filters( 'slashphoto_gallery_align', $align, $post );
		return in_array( $align, [ 'wide', 'full' ], true ) ? $align : '';
	}

	/**
	 * Adds body classes themes can target.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( is_singular( PostType::POST_TYPE ) || is_post_type_archive( PostType::POST_TYPE ) ) {
			$classes[] = 'slashphoto-page';
			$classes[] = wp_is_block_theme() ? 'slashphoto-block-theme' : 'slashphoto-classic-theme';
		}
		return $classes;
	}

	/**
	 * Decides whether the gallery should be appended to this content call.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private function should_append( \WP_Post $post ): bool {
		$append = PostType::POST_TYPE === $post->post_type
			&& is_singular( PostType::POST_TYPE )
			&& in_the_loop()
			&& is_main_query()
			&& get_queried_object_id() === $post->ID
			&& ! post_password_required( $post )
			// The author placed the gallery block manually; respect the layout.
			&& ! has_block( 'slashphoto/gallery', $post );

		/**
		 * Filters whether SlashPhoto appends the gallery to the post content.
		 *
		 * Return false when a theme template renders the gallery itself.
		 *
		 * @param bool     $append Whether to append.
		 * @param \WP_Post $post   The gallery.
		 */
		return (bool) apply_filters( 'slashphoto_append_gallery_to_content', $append, $post );
	}
}
