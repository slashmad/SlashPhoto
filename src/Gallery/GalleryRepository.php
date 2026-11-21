<?php
/**
 * Gallery queries.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads galleries, sub-galleries and their images.
 */
final class GalleryRepository {

	/**
	 * Returns the image attachments of a gallery in display order.
	 *
	 * @param int $gallery_id Gallery post ID.
	 * @param int $limit      Number of photos, or -1 for all.
	 * @return WP_Post[]
	 */
	public function images( int $gallery_id, int $limit = -1 ): array {
		$images = get_children(
			[
				'post_parent'    => $gallery_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => $limit,
				'post_status'    => 'inherit',
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
			]
		);

		return array_values( $images );
	}

	/**
	 * Returns the published sub-galleries of a gallery in display order.
	 *
	 * @param int $gallery_id Gallery post ID.
	 * @return WP_Post[]
	 */
	public function children( int $gallery_id ): array {
		return get_posts(
			[
				'post_type'        => PostType::POST_TYPE,
				'post_parent'      => $gallery_id,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => [
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				],
				'suppress_filters' => false,
			]
		);
	}
}
