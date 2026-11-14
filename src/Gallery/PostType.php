<?php
/**
 * Gallery post type.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the hierarchical gallery post type.
 *
 * Galleries are ordinary WordPress posts so that every theme, SEO plugin and
 * page builder can work with them. Sub-galleries use the post parent.
 * Images are attachments whose post_parent is the gallery.
 */
final class PostType {

	public const POST_TYPE = 'slashphoto_gallery';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	/**
	 * Registers the post type.
	 */
	public static function register(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		/**
		 * Filters the URL base for galleries, e.g. "galleries" or "gallerier".
		 *
		 * Flush permalinks (Settings > Permalinks > Save) after changing it.
		 *
		 * @param string $slug URL base.
		 */
		$slug = (string) apply_filters( 'slashphoto_gallery_rewrite_slug', _x( 'galleries', 'URL slug', 'slashphoto' ) );

		$args = [
			'labels'          => [
				'name'               => __( 'Galleries', 'slashphoto' ),
				'singular_name'      => __( 'Gallery', 'slashphoto' ),
				'menu_name'          => __( 'SlashPhoto', 'slashphoto' ),
				'all_items'          => __( 'All galleries', 'slashphoto' ),
				'add_new_item'       => __( 'Add new gallery', 'slashphoto' ),
				'edit_item'          => __( 'Edit gallery', 'slashphoto' ),
				'new_item'           => __( 'New gallery', 'slashphoto' ),
				'view_item'          => __( 'View gallery', 'slashphoto' ),
				'search_items'       => __( 'Search galleries', 'slashphoto' ),
				'not_found'          => __( 'No galleries found.', 'slashphoto' ),
				'not_found_in_trash' => __( 'No galleries found in Trash.', 'slashphoto' ),
				'parent_item_colon'  => __( 'Parent gallery:', 'slashphoto' ),
			],
			'public'          => true,
			'hierarchical'    => true,
			'show_in_rest'    => true,
			'has_archive'     => true,
			'menu_icon'       => 'dashicons-format-gallery',
			'menu_position'   => 25,
			'capability_type' => 'page',
			'map_meta_cap'    => true,
			'rewrite'         => [
				'slug'       => $slug,
				'with_front' => false,
			],
			'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'author' ],
		];

		/**
		 * Filters the gallery post type arguments.
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		register_post_type( self::POST_TYPE, apply_filters( 'slashphoto_gallery_post_type_args', $args ) );
	}
}
