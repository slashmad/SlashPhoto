<?php
/**
 * Usage licences for downloads.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * A licence is a name and plain-text terms ("Personal use", "Commercial
 * use"), kept as a private post type under SlashPhoto → Licences. A
 * download product in a price list can name one. Customers see it next to
 * the product, must accept the licences in their cart to order, and the
 * order line keeps a copy of the name and text, so editing a licence later
 * never changes what someone bought.
 */
final class Licences {

	public const POST_TYPE = 'slashphoto_licence';

	/** Longest licence text kept, in characters. */
	public const MAX_TEXT = 20000;

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
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => __( 'Licences', 'slashphoto' ),
					'singular_name'      => __( 'Licence', 'slashphoto' ),
					'add_new_item'       => __( 'Add licence', 'slashphoto' ),
					'edit_item'          => __( 'Edit licence', 'slashphoto' ),
					'new_item'           => __( 'New licence', 'slashphoto' ),
					'search_items'       => __( 'Search licences', 'slashphoto' ),
					'not_found'          => __( 'No licences found.', 'slashphoto' ),
					'not_found_in_trash' => __( 'No licences found in Trash.', 'slashphoto' ),
					'all_items'          => __( 'Licences', 'slashphoto' ),
				],
				'description'     => __( 'Terms customers accept when they buy a download.', 'slashphoto' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . PostType::POST_TYPE,
				'show_in_rest'    => false,
				'supports'        => [ 'title', 'editor' ],
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			]
		);
	}

	/**
	 * Published licences, for menus.
	 *
	 * @return array<int, string> ID => name.
	 */
	public static function all(): array {
		$licences = [];
		foreach ( get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		) as $post ) {
			$licences[ $post->ID ] = get_the_title( $post );
		}
		return $licences;
	}

	/**
	 * A published licence as name and plain text, or null.
	 *
	 * @param int $id Licence.
	 * @return array{id: int, name: string, text: string}|null
	 */
	public static function get( int $id ): ?array {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$html = str_replace( [ '<br>', '<br />', '</li>' ], "\n", str_replace( '</p>', "\n\n", (string) $post->post_content ) );
		$text = trim( wp_strip_all_tags( $html ) );
		return [
			'id'   => $post->ID,
			'name' => mb_substr( trim( wp_strip_all_tags( $post->post_title ) ), 0, 200 ),
			'text' => mb_substr( (string) preg_replace( "/\n{3,}/", "\n\n", $text ), 0, self::MAX_TEXT ),
		];
	}
}
