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

	private const PHOTO_ORDER = '_slashphoto_photo_order';

	/**
	 * Returns the image attachments of a gallery in display order.
	 *
	 * @param int $gallery_id Gallery post ID.
	 * @param int $limit      Number of photos, or -1 for all.
	 * @return WP_Post[]
	 */
	public function images( int $gallery_id, int $limit = -1 ): array {
		$query = [
			'post_parent'    => $gallery_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'numberposts'    => $limit,
			'post_status'    => 'inherit',
			'orderby'        => [
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			],
		];
		$order = get_post_meta( $gallery_id, self::PHOTO_ORDER, true );
		if ( ! is_array( $order ) || ! $order ) {
			return array_values( get_children( $query ) );
		}
		$sorted = get_children(
			array_replace(
				$query,
				[
					'post__in' => $order,
					'orderby'  => 'post__in',
				]
			)
		);
		if ( $limit > 0 && count( $sorted ) >= $limit ) {
			return array_values( $sorted );
		}
		$query['post__not_in'] = $order;
		$query['numberposts']  = $limit > 0 ? $limit - count( $sorted ) : $limit;
		return array_values( $sorted + get_children( $query ) );
	}

	/**
	 * Identifies the order and membership seen by the editor.
	 *
	 * @param WP_Post[] $images Gallery photos in display order.
	 */
	public static function order_token( array $images ): string {
		return hash( 'sha256', implode( ',', array_column( $images, 'ID' ) ) );
	}

	/**
	 * Moves one photo to a position in its gallery. The caller authorizes edits.
	 *
	 * @param int    $gallery_id Gallery post ID.
	 * @param int    $image_id   Photo to move.
	 * @param int    $position   Destination position, starting at one.
	 * @param string $token      Order the editor last saw.
	 */
	public function move_photo( int $gallery_id, int $image_id, int $position, string $token ): string {
		$previous = get_post_meta( $gallery_id, self::PHOTO_ORDER, true );
		$images   = $this->images( $gallery_id );
		if ( ! hash_equals( self::order_token( $images ), $token ) ) {
			return 'stale';
		}
		$ids  = array_column( $images, 'ID' );
		$from = array_search( $image_id, $ids, true );
		if ( false === $from || $position < 1 || $position > count( $ids ) ) {
			return 'invalid';
		}
		if ( $from === $position - 1 ) {
			return 'moved';
		}
		array_splice( $ids, $from, 1 );
		array_splice( $ids, $position - 1, 0, [ $image_id ] );
		$saved = '' === $previous
			? add_post_meta( $gallery_id, self::PHOTO_ORDER, $ids, true )
			: update_post_meta( $gallery_id, self::PHOTO_ORDER, $ids, $previous );
		return $saved ? 'moved' : 'stale';
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
