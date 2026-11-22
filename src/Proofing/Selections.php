<?php
/**
 * Stored client selections.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use SlashPhoto\Gallery\PostType;
use WP_Post;
use WP_Query;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * One selection per client and proofing root, stored as a private post:
 * post_author is the client, post_parent the proofing root.
 *
 * Only what the client chooses is stored (images, notes, an optional
 * message, the time it was sent). Nothing about browsing, devices or
 * networks. Selections are deleted with the client's account and with the
 * gallery, and are covered by the WordPress personal data export and
 * erasure tools.
 */
final class Selections {

	public const POST_TYPE    = 'slashphoto_selection';
	public const META_ITEMS   = '_slashphoto_items';
	public const META_SENT    = '_slashphoto_submitted';
	public const META_MESSAGE = '_slashphoto_message';

	public const MAX_ITEMS         = 2000;
	public const MAX_NOTE_CHARS    = 1000;
	public const MAX_MESSAGE_CHARS = 5000;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'before_delete_post', [ $this, 'delete_for_gallery' ] );
	}

	/**
	 * Registers the internal post type. It has no front end, REST route or
	 * admin screen of its own.
	 */
	public static function register(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			[
				'label'            => __( 'Client selections', 'slashphoto' ),
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => true,
				'delete_with_user' => true,
				'supports'         => [ 'author' ],
			]
		);
	}

	/**
	 * Deletes the selections of a gallery that is deleted permanently.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function delete_for_gallery( $post_id ): void {
		if ( PostType::POST_TYPE !== get_post_type( (int) $post_id ) ) {
			return;
		}
		foreach ( self::for_root( (int) $post_id ) as $selection ) {
			wp_delete_post( $selection->ID, true );
		}
	}

	/**
	 * Returns the user's selection for a root, or null.
	 *
	 * @param int $root_id Proofing root ID.
	 * @param int $user_id User ID.
	 */
	public static function find( int $root_id, int $user_id ): ?WP_Post {
		if ( ! $root_id || ! $user_id ) {
			return null;
		}
		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'post_parent'      => $root_id,
				'author'           => $user_id,
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Returns the user's selection for a root, creating an empty one.
	 *
	 * @param WP_Post $root    Proofing root.
	 * @param int     $user_id User ID.
	 */
	public static function find_or_create( WP_Post $root, int $user_id ): ?WP_Post {
		$selection = self::find( $root->ID, $user_id );
		if ( $selection ) {
			return $selection;
		}
		$id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_parent' => $root->ID,
				'post_author' => $user_id,
				'post_title'  => $root->post_title,
			],
			true
		);
		return is_wp_error( $id ) ? null : get_post( $id );
	}

	/**
	 * All selections of a root, newest first.
	 *
	 * @param int $root_id Proofing root ID.
	 * @return WP_Post[]
	 */
	public static function for_root( int $root_id ): array {
		return get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'post_parent'      => $root_id,
				'numberposts'      => 100,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			]
		);
	}

	/**
	 * Finds the gallery IDs which have stored selections.
	 *
	 * @return int[]
	 */
	public static function root_ids(): array {
		$parents = get_posts(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'numberposts' => -1,
				'fields'      => 'id=>parent',
				'orderby'     => 'none',
			]
		);
		return array_values( array_unique( $parents ) );
	}

	/**
	 * Paginates recent selections within an already-authorized set of roots.
	 *
	 * @param int[]  $roots    Gallery IDs.
	 * @param string $status   Selection status filter.
	 * @param int    $page     One-based page.
	 * @param int    $per_page Page size.
	 */
	public static function review_page( array $roots, string $status, int $page, int $per_page ): WP_Query {
		if ( ! $roots ) {
			return new WP_Query();
		}
		$args      = [
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'private',
			'post_parent__in'  => $roots,
			'posts_per_page'   => $per_page,
			'paged'            => $page,
			'orderby'          => [
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			'suppress_filters' => true,
		];
		$submitted = [
			'key'     => self::META_SENT,
			'value'   => 0,
			'compare' => '>',
			'type'    => 'NUMERIC',
		];
		if ( 'submitted' === $status ) {
			$args['meta_query'] = [ $submitted ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Submission time is stored in post meta.
		} elseif ( 'choosing' === $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Submission time is stored in post meta.
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => self::META_SENT,
					'compare' => 'NOT EXISTS',
				],
				array_replace( $submitted, [ 'compare' => '<=' ] ),
			];
		}
		return new WP_Query( $args );
	}

	/**
	 * All selections of a user.
	 *
	 * @param int $user_id User ID.
	 * @return WP_Post[]
	 */
	public static function for_user( int $user_id ): array {
		if ( ! $user_id ) {
			return [];
		}
		return get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'author'           => $user_id,
				'numberposts'      => 100,
				'suppress_filters' => true,
			]
		);
	}

	/**
	 * Selected images and their notes, in the order chosen.
	 *
	 * @param WP_Post $selection Selection.
	 * @return array<int, string> Attachment ID => note.
	 */
	public static function items( WP_Post $selection ): array {
		$items = get_post_meta( $selection->ID, self::META_ITEMS, true );
		if ( ! is_array( $items ) ) {
			return [];
		}
		$clean = [];
		foreach ( $items as $id => $note ) {
			if ( (int) $id > 0 ) {
				$clean[ (int) $id ] = (string) $note;
			}
		}
		return $clean;
	}

	/**
	 * Saves the items.
	 *
	 * @param WP_Post            $selection Selection.
	 * @param array<int, string> $items     Attachment ID => note.
	 */
	public static function save_items( WP_Post $selection, array $items ): void {
		update_post_meta( $selection->ID, self::META_ITEMS, $items );
		// Bumps post_modified so the photographer sees recent activity first.
		wp_update_post(
			[
				'ID'         => $selection->ID,
				'post_title' => get_the_title( $selection->post_parent ),
			]
		);
	}

	/**
	 * Unix time the selection was sent, or 0 while the client is still choosing.
	 *
	 * @param WP_Post $selection Selection.
	 */
	public static function submitted_at( WP_Post $selection ): int {
		return (int) get_post_meta( $selection->ID, self::META_SENT, true );
	}

	/**
	 * The client's message sent with the selection.
	 *
	 * @param WP_Post $selection Selection.
	 */
	public static function message( WP_Post $selection ): string {
		return (string) get_post_meta( $selection->ID, self::META_MESSAGE, true );
	}

	/**
	 * Marks the selection as sent.
	 *
	 * @param WP_Post $selection Selection.
	 * @param string  $message   Message to the photographer.
	 */
	public static function submit( WP_Post $selection, string $message ): void {
		update_post_meta( $selection->ID, self::META_MESSAGE, self::clean_text( $message, self::MAX_MESSAGE_CHARS ) );
		update_post_meta( $selection->ID, self::META_SENT, time() );
		wp_update_post( [ 'ID' => $selection->ID ] );
	}

	/**
	 * Lets the client change a sent selection again.
	 *
	 * @param WP_Post $selection Selection.
	 */
	public static function reopen( WP_Post $selection ): void {
		delete_post_meta( $selection->ID, self::META_SENT );
		wp_update_post( [ 'ID' => $selection->ID ] );
	}

	/**
	 * Plain text, trimmed to a length.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Maximum characters.
	 */
	public static function clean_text( string $text, int $limit ): string {
		return trim( mb_substr( sanitize_textarea_field( $text ), 0, $limit ) );
	}

	/**
	 * The client of a selection.
	 *
	 * @param WP_Post $selection Selection.
	 */
	public static function client( WP_Post $selection ): ?WP_User {
		$user = get_userdata( (int) $selection->post_author );
		return $user ? $user : null;
	}
}
