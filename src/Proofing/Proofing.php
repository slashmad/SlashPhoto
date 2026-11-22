<?php
/**
 * Proofing rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\PostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides where proofing is switched on and who may change a selection.
 *
 * A selection belongs to a "proofing root": the nearest gallery up the tree
 * with an explicit proofing setting of "on". Images from all sub-galleries
 * below it go into the same selection, so a client choosing from a wedding
 * with chapters sends one selection, not one per chapter. A sub-gallery can
 * switch proofing off, or on again to start a separate selection.
 *
 * Only logged-in users store selections on the server, and only for
 * galleries they can see (passwords, client lists, links, expiry, terms).
 * Anonymous visitors keep favourites in their own browser only.
 */
final class Proofing {

	public const META     = '_slashphoto_proofing';
	public const META_MAX = '_slashphoto_proofing_max';

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Terms gate.
	 *
	 * @var Terms
	 */
	private Terms $terms;

	/**
	 * Constructor.
	 *
	 * @param Access $access Access rules.
	 * @param Terms  $terms  Terms gate.
	 */
	public function __construct( Access $access, Terms $terms ) {
		$this->access = $access;
		$this->terms  = $terms;
	}

	/**
	 * Returns the proofing root of a gallery, or null when proofing is off.
	 *
	 * @param int|WP_Post $gallery Gallery.
	 */
	public function root( $gallery ): ?WP_Post {
		$gallery = get_post( $gallery );
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type ) {
			return null;
		}
		foreach ( $this->access->chain( $gallery ) as $post ) {
			$value = (string) get_post_meta( $post->ID, self::META, true );
			if ( 'on' === $value ) {
				return $post;
			}
			if ( 'off' === $value ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Whether the gallery is its own proofing root.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function is_root( WP_Post $gallery ): bool {
		$root = $this->root( $gallery );
		return $root && $root->ID === $gallery->ID;
	}

	/**
	 * Maximum number of images in a selection, 0 for no limit.
	 *
	 * @param WP_Post $root Proofing root.
	 */
	public static function max( WP_Post $root ): int {
		return max( 0, (int) get_post_meta( $root->ID, self::META_MAX, true ) );
	}

	/**
	 * Whether the current visitor sees the gallery's images, which is the
	 * precondition for choosing any of them.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function can_see( WP_Post $gallery ): bool {
		return $this->access->can_view( $gallery ) && null === $this->terms->needs_step( $gallery );
	}

	/**
	 * Whether the current user may keep a selection for this root.
	 *
	 * @param WP_Post $root Proofing root.
	 */
	public function can_select( WP_Post $root ): bool {
		return is_user_logged_in() && $this->is_root( $root ) && $this->can_see( $root );
	}

	/**
	 * Whether an image may be part of the root's selection for the current
	 * user: it is in the root or a sub-gallery sharing the root, and the user
	 * can see that sub-gallery.
	 *
	 * @param WP_Post $root          Proofing root.
	 * @param int     $attachment_id Image ID.
	 */
	public function image_allowed( WP_Post $root, int $attachment_id ): bool {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment ) || ! $attachment->post_parent ) {
			return false;
		}
		$gallery = get_post( $attachment->post_parent );
		if ( ! $gallery ) {
			return false;
		}
		$gallery_root = $this->root( $gallery );
		return $gallery_root && $gallery_root->ID === $root->ID && $this->can_see( $gallery );
	}

	/**
	 * Whether the current user may review the gallery's saved client selections.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function can_review( WP_Post $gallery ): bool {
		return PostType::POST_TYPE === $gallery->post_type && 'trash' !== $gallery->post_status && $this->access->is_editor( $gallery );
	}

	/**
	 * Current, accessible photos in a stored selection, in the client's order.
	 *
	 * Historical selections remain reviewable if the gallery's proofing setting
	 * changes. Images that have been moved elsewhere are no longer shown.
	 *
	 * @param WP_Post $gallery   Gallery owning the selection.
	 * @param WP_Post $selection Stored client selection.
	 * @return array<int, string>
	 */
	public function review_items( WP_Post $gallery, WP_Post $selection ): array {
		if ( ! $this->can_review( $gallery ) || Selections::POST_TYPE !== $selection->post_type || 'private' !== $selection->post_status || $gallery->ID !== $selection->post_parent ) {
			return [];
		}
		$items = Selections::items( $selection );
		if ( ! $items ) {
			return [];
		}
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'include'        => array_keys( $items ),
			]
		);
		$allowed     = [];
		$visible     = [];
		foreach ( $attachments as $attachment ) {
			$parent_id = $attachment->post_parent;
			if ( ! array_key_exists( $parent_id, $allowed ) ) {
				$parent                = $parent_id ? get_post( $parent_id ) : null;
				$allowed[ $parent_id ] = $parent && PostType::POST_TYPE === $parent->post_type && 'trash' !== $parent->post_status
					&& in_array( $gallery->ID, array_column( $this->access->chain( $parent ), 'ID' ), true )
					&& $this->can_see( $parent );
			}
			if ( $allowed[ $parent_id ] ) {
				$visible[ $attachment->ID ] = true;
			}
		}
		return array_intersect_key( $items, $visible );
	}

	/**
	 * Name to give the photographer for finding the image in their own
	 * catalogue (for example a Lightroom filter): the uploaded file name
	 * without the random suffix SlashPhoto adds, the "-scaled" marker or the
	 * extension.
	 *
	 * @param int $attachment_id Image ID.
	 */
	public static function file_label( int $attachment_id ): string {
		$file = (string) wp_get_original_image_path( $attachment_id );
		if ( '' === $file ) {
			$file = (string) get_attached_file( $attachment_id );
		}
		$name = pathinfo( $file, PATHINFO_FILENAME );
		$name = (string) preg_replace( '/-scaled$/', '', $name );
		$name = (string) preg_replace( '/-[a-f0-9]{12}$/', '', $name );
		return '' !== $name ? $name : (string) $attachment_id;
	}
}
