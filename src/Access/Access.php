<?php
/**
 * Gallery access rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Gallery\PostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may see a gallery and its images.
 *
 * Rules are evaluated for the gallery and every ancestor, so a sub-gallery is
 * never more open than the gallery it belongs to:
 *  - Password: WordPress' own post password. A sub-gallery without its own
 *    password uses the nearest ancestor's password.
 *  - Clients: only selected user accounts (plus editors) may view. Every
 *    ancestor in "clients" mode must allow the user.
 *  - Expiry: after the end date only editors may view.
 * Users who can edit the gallery always pass.
 *
 * Terms (see Terms) are not an access rule; they gate the images after access
 * has been granted.
 *
 * This class stores nothing about visitors.
 */
final class Access {

	public const META_MODE    = '_slashphoto_access';
	public const META_CLIENTS = '_slashphoto_clients';
	public const META_EXPIRES = '_slashphoto_expires';

	public const MODE_PUBLIC  = 'public';
	public const MODE_CLIENTS = 'clients';

	/** Maximum ancestor depth followed; guards against corrupt parent loops. */
	private const MAX_DEPTH = 20;

	/**
	 * Per-request cache of restricted gallery IDs, keyed by user ID.
	 *
	 * @var array<int, int[]>
	 */
	private array $restricted_cache = [];

	/**
	 * Per-request cache of hidden gallery IDs, keyed by user ID.
	 *
	 * @var array<int, int[]>
	 */
	private array $hidden_cache = [];

	/**
	 * Per-request cache of protected gallery IDs.
	 *
	 * @var int[]|null
	 */
	private ?array $protected_cache = null;

	/**
	 * Returns the gallery itself followed by its ancestors, nearest first.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @return WP_Post[]
	 */
	public function chain( WP_Post $gallery ): array {
		$chain = [ $gallery ];
		$seen  = [ $gallery->ID => true ];
		$post  = $gallery;
		$depth = 1;
		while ( $post->post_parent && $depth++ < self::MAX_DEPTH ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || isset( $seen[ $parent->ID ] ) || PostType::POST_TYPE !== $parent->post_type ) {
				break;
			}
			$seen[ $parent->ID ] = true;
			$chain[]             = $parent;
			$post                = $parent;
		}
		return $chain;
	}

	/**
	 * Whether the current user may edit (and therefore always view) the gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function is_editor( WP_Post $gallery ): bool {
		return current_user_can( 'edit_post', $gallery->ID );
	}

	/**
	 * Returns the gallery whose password protects $gallery, or null.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function password_source( WP_Post $gallery ): ?WP_Post {
		foreach ( $this->chain( $gallery ) as $post ) {
			if ( '' !== (string) $post->post_password ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Whether a password still has to be entered to see $gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function password_required( WP_Post $gallery ): bool {
		$source = $this->password_source( $gallery );
		if ( ! $source || $this->is_editor( $gallery ) ) {
			return false;
		}
		return self::core_password_required( $source );
	}

	/**
	 * WordPress' own password check for a single post, without our filter.
	 *
	 * @param WP_Post $post Post with a password.
	 */
	public static function core_password_required( WP_Post $post ): bool {
		if ( '' === (string) $post->post_password ) {
			return false;
		}
		// Delegate to core so future changes to password hashing apply;
		// AccessGuard's post_password_required filter is bypassed meanwhile.
		self::$bypass_password_filter = true;
		try {
			return post_password_required( $post );
		} finally {
			self::$bypass_password_filter = false;
		}
	}

	/**
	 * True while core_password_required() runs core's check.
	 *
	 * @var bool
	 */
	public static bool $bypass_password_filter = false;

	/**
	 * Returns the mode of one gallery (not inherited).
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public static function mode( WP_Post $gallery ): string {
		$mode = (string) get_post_meta( $gallery->ID, self::META_MODE, true );
		return self::MODE_CLIENTS === $mode ? self::MODE_CLIENTS : self::MODE_PUBLIC;
	}

	/**
	 * Returns the user IDs allowed on one gallery (not inherited).
	 *
	 * @param WP_Post $gallery Gallery.
	 * @return int[]
	 */
	public static function clients( WP_Post $gallery ): array {
		$ids = get_post_meta( $gallery->ID, self::META_CLIENTS, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
	}

	/**
	 * Whether $gallery or an ancestor is limited to selected clients.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function clients_only( WP_Post $gallery ): bool {
		foreach ( $this->chain( $gallery ) as $post ) {
			if ( self::MODE_CLIENTS === self::mode( $post ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user passes every "clients" rule in the chain.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function client_allowed( WP_Post $gallery ): bool {
		if ( $this->is_editor( $gallery ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		foreach ( $this->chain( $gallery ) as $post ) {
			if ( self::MODE_CLIENTS !== self::mode( $post ) ) {
				continue;
			}
			if ( ! $user_id || ! in_array( $user_id, self::clients( $post ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the expiry timestamp (end of the day in the site timezone) of
	 * the gallery or the earliest-expiring ancestor, or null.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function expires_at( WP_Post $gallery ): ?int {
		$earliest = null;
		foreach ( $this->chain( $gallery ) as $post ) {
			$date = (string) get_post_meta( $post->ID, self::META_EXPIRES, true );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			$end = date_create_immutable( $date . ' 23:59:59', wp_timezone() );
			if ( $end && ( null === $earliest || $end->getTimestamp() < $earliest ) ) {
				$earliest = $end->getTimestamp();
			}
		}
		return $earliest;
	}

	/**
	 * Whether the gallery has expired for the current user.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function expired( WP_Post $gallery ): bool {
		if ( $this->is_editor( $gallery ) ) {
			return false;
		}
		$expires = $this->expires_at( $gallery );
		return null !== $expires && time() > $expires;
	}

	/**
	 * Whether the current user may see the gallery's images.
	 *
	 * @param WP_Post|int|null $gallery Gallery.
	 */
	public function can_view( $gallery ): bool {
		$gallery = get_post( $gallery );
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type ) {
			return false;
		}
		if ( $this->is_editor( $gallery ) ) {
			return true;
		}
		foreach ( $this->chain( $gallery ) as $post ) {
			if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
				return false;
			}
		}
		$allowed = ! $this->password_required( $gallery )
			&& $this->client_allowed( $gallery )
			&& ! $this->expired( $gallery );

		/**
		 * Filters whether the current user may see a gallery's images.
		 *
		 * Can only be used to add restrictions safely; returning true here
		 * bypasses passwords, client lists and expiry.
		 *
		 * @param bool    $allowed Whether access is allowed.
		 * @param WP_Post $gallery The gallery.
		 */
		return (bool) apply_filters( 'slashphoto_can_view_gallery', $allowed, $gallery );
	}

	/**
	 * Whether the gallery is anything other than plainly public, so pages
	 * must not be cached, indexed or listed in sitemaps.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function is_protected( WP_Post $gallery ): bool {
		return null !== $this->password_source( $gallery )
			|| $this->clients_only( $gallery )
			|| null !== $this->expires_at( $gallery )
			|| null !== Terms::source( $this, $gallery );
	}

	/**
	 * Whether the gallery should be hidden from listings, search, feeds and
	 * REST collections for the current user: client-only or expired
	 * galleries the user cannot view. Password galleries stay listed (title
	 * only), as WordPress does for password-protected posts.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function hidden_from_listings( WP_Post $gallery ): bool {
		if ( $this->is_editor( $gallery ) ) {
			return false;
		}
		return ! $this->client_allowed( $gallery ) || $this->expired( $gallery );
	}

	/**
	 * IDs of galleries whose images the current user may not see, including
	 * all galleries below them. Cached per request and user.
	 *
	 * @return int[]
	 */
	public function restricted_gallery_ids(): array {
		$user_id = get_current_user_id();
		if ( isset( $this->restricted_cache[ $user_id ] ) ) {
			return $this->restricted_cache[ $user_id ];
		}

		$ids = [];
		foreach ( $this->protected_gallery_ids() as $id ) {
			if ( ! $this->can_view( $id ) ) {
				$ids[] = $id;
			}
		}
		$this->restricted_cache[ $user_id ] = $ids;
		return $ids;
	}

	/**
	 * IDs of galleries hidden from listings for the current user.
	 *
	 * @return int[]
	 */
	public function hidden_gallery_ids(): array {
		$user_id = get_current_user_id();
		if ( isset( $this->hidden_cache[ $user_id ] ) ) {
			return $this->hidden_cache[ $user_id ];
		}
		$ids = [];
		foreach ( $this->protected_gallery_ids() as $id ) {
			$post = get_post( $id );
			if ( $post && $this->hidden_from_listings( $post ) ) {
				$ids[] = $id;
			}
		}
		$this->hidden_cache[ $user_id ] = $ids;
		return $ids;
	}

	/**
	 * IDs of all galleries that have, or inherit, any protection.
	 *
	 * @return int[]
	 */
	public function protected_gallery_ids(): array {
		global $wpdb;

		if ( null !== $this->protected_cache ) {
			return $this->protected_cache;
		}

		// Galleries with their own rule: password, clients mode, expiry date or terms.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed query per request; result is cached by the caller.
		$roots = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( %s, %s, %s )
				WHERE p.post_type = %s
				AND ( p.post_password <> '' OR ( m.meta_key = %s AND m.meta_value = %s ) OR ( m.meta_key IN ( %s, %s ) AND m.meta_value <> '' ) )",
				self::META_MODE,
				self::META_EXPIRES,
				Terms::META_TERMS,
				PostType::POST_TYPE,
				self::META_MODE,
				self::MODE_CLIENTS,
				self::META_EXPIRES,
				Terms::META_TERMS
			)
		);

		$ids = array_map( 'intval', $roots );
		// Add all descendants, which inherit the rules.
		$queue = $ids;
		while ( $queue ) {
			$children = get_posts(
				[
					'post_type'             => PostType::POST_TYPE,
					'post_status'           => 'any',
					'post_parent__in'       => $queue,
					'fields'                => 'ids',
					'numberposts'           => -1,
					'suppress_filters'      => true,
					'slashphoto_unfiltered' => true,
				]
			);
			$queue    = array_diff( array_map( 'intval', $children ), $ids );
			$ids      = array_merge( $ids, $queue );
		}
		$this->protected_cache = array_values( array_unique( $ids ) );
		return $this->protected_cache;
	}

	/**
	 * Clears the per-request cache (after login state or rules change).
	 */
	public function flush(): void {
		$this->restricted_cache = [];
		$this->hidden_cache     = [];
		$this->protected_cache  = null;
	}
}
