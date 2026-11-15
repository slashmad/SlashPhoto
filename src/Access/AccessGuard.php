<?php
/**
 * Enforces gallery access everywhere WordPress can expose a gallery or image.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\PostType;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Closes every path through which a protected gallery or its images could
 * leak, beyond the gallery page itself:
 *
 *  - passwords inherited by sub-galleries (post_password_required filter)
 *  - page content for client-only and expired galleries
 *  - REST: /wp/v2/media (collections and single items), gallery endpoints
 *  - attachment pages and their redirect to the file
 *  - listings, search, feeds (pre_get_posts) and previous/next links
 *  - XML sitemaps, search-engine indexing (noindex) and page caches
 *  - oEmbed
 *
 * Image files themselves live under unguessable paths (see Uploads).
 */
final class AccessGuard {

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Template loader for notices.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Re-entrancy guard for pre_get_posts.
	 *
	 * @var bool
	 */
	private bool $in_query_filter = false;

	/**
	 * Constructor.
	 *
	 * @param Access         $access    Access rules.
	 * @param TemplateLoader $templates Template loader.
	 */
	public function __construct( Access $access, TemplateLoader $templates ) {
		$this->access    = $access;
		$this->templates = $templates;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'post_password_required', [ $this, 'inherit_password' ], 10, 2 );
		add_filter( 'the_content', [ $this, 'gate_content' ], 999 );
		add_filter( 'get_the_excerpt', [ $this, 'gate_excerpt' ], 999, 2 );

		add_action( 'template_redirect', [ $this, 'template_redirect' ], 0 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );

		add_action( 'pre_get_posts', [ $this, 'exclude_hidden_galleries' ] );
		add_filter( 'get_previous_post_where', [ $this, 'adjacent_where' ], 10, 5 );
		add_filter( 'get_next_post_where', [ $this, 'adjacent_where' ], 10, 5 );

		add_filter( 'rest_attachment_query', [ $this, 'rest_attachment_query' ] );
		add_filter( 'rest_' . PostType::POST_TYPE . '_query', [ $this, 'rest_gallery_query' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'rest_single_item' ], 10, 3 );

		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'sitemap_query_args' ], 10, 2 );
		add_filter( 'oembed_request_post_id', [ $this, 'oembed_post_id' ] );

		// Logging in or out, or changing a gallery, changes who may see what.
		add_action( 'set_current_user', [ $this->access, 'flush' ] );
		foreach ( [ 'save_post', 'deleted_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this->access, 'flush' ] );
		}
	}

	/**
	 * Sub-galleries without their own password use the nearest ancestor's.
	 *
	 * @param bool    $required Core's result.
	 * @param WP_Post $post     Post.
	 */
	public function inherit_password( $required, $post ) {
		if ( Access::$bypass_password_filter || ! $post instanceof WP_Post || PostType::POST_TYPE !== $post->post_type ) {
			return $required;
		}
		return $this->access->password_required( $post );
	}

	/**
	 * Replaces the content of client-only or expired galleries the visitor
	 * may not see with a notice. Password protection is handled by core.
	 *
	 * @param string $content Content.
	 */
	public function gate_content( $content ) {
		$post = get_post();
		if ( ! $post || PostType::POST_TYPE !== $post->post_type || $this->access->password_required( $post ) ) {
			return $content;
		}
		$notice = $this->notice_type( $post );
		if ( null === $notice ) {
			return $content;
		}
		return $this->templates->render(
			'access-notice.php',
			[
				'gallery' => $post,
				'type'    => $notice,
			]
		);
	}

	/**
	 * Empties excerpts of galleries whose content is gated.
	 *
	 * @param string       $excerpt Excerpt.
	 * @param WP_Post|null $post    Post.
	 */
	public function gate_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( $post && PostType::POST_TYPE === $post->post_type && null !== $this->notice_type( $post ) ) {
			return '';
		}
		return $excerpt;
	}

	/**
	 * Which notice replaces the content, or null when the visitor may see it.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @return string|null "login", "denied" or "expired".
	 */
	public function notice_type( WP_Post $gallery ): ?string {
		if ( ! $this->access->client_allowed( $gallery ) ) {
			return is_user_logged_in() ? 'denied' : 'login';
		}
		if ( $this->access->expired( $gallery ) ) {
			return 'expired';
		}
		return null;
	}

	/**
	 * Front-end request handling: no caching for protected pages, 404 for
	 * attachments of galleries the visitor may not see.
	 */
	public function template_redirect(): void {
		global $wp_query;

		if ( is_attachment() ) {
			$attachment = get_queried_object();
			if ( $attachment instanceof WP_Post && $this->attachment_restricted( $attachment ) ) {
				// No canonical redirect: it would reveal the attachment's slug first.
				remove_action( 'template_redirect', 'redirect_canonical' );
				$wp_query->set_404();
				status_header( 404 );
				$this->no_cache();
			}
			return;
		}

		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( PostType::POST_TYPE === $post->post_type && $this->access->is_protected( $post ) ) {
			$this->no_cache();
		} elseif ( $this->embeds_protected_gallery( $post ) ) {
			$this->no_cache();
		}
	}

	/**
	 * Adds noindex to protected galleries.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots( $robots ) {
		$post = is_singular( PostType::POST_TYPE ) ? get_queried_object() : null;
		if ( $post instanceof WP_Post && $this->access->is_protected( $post ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Hides client-only and expired galleries from listings, search and feeds.
	 *
	 * @param WP_Query $query Query.
	 */
	public function exclude_hidden_galleries( $query ): void {
		if ( $this->in_query_filter || $query->get( 'slashphoto_unfiltered' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! array_intersect( $types, [ PostType::POST_TYPE, 'any' ] ) && ! $query->is_search() ) {
			return;
		}
		// Single gallery views are handled by content gating, not hidden.
		if ( $query->is_main_query() && $query->is_singular() ) {
			return;
		}

		$this->in_query_filter = true;
		try {
			$hidden = $this->access->hidden_gallery_ids();
		} finally {
			$this->in_query_filter = false;
		}
		if ( $hidden ) {
			$args = self::exclude(
				[
					'post__in'     => $query->get( 'post__in' ),
					'post__not_in' => $query->get( 'post__not_in' ),
				],
				'post__in',
				'post__not_in',
				$hidden
			);
			$query->set( 'post__in', $args['post__in'] );
			$query->set( 'post__not_in', $args['post__not_in'] ?? [] );
		}
	}

	/**
	 * Keeps hidden galleries out of previous/next post links.
	 *
	 * @param string       $where          SQL WHERE clause.
	 * @param bool         $in_same_term   Unused.
	 * @param array|string $excluded_terms Unused.
	 * @param string       $taxonomy       Unused.
	 * @param WP_Post|null $post           Current post.
	 */
	public function adjacent_where( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ) {
		if ( ! $post instanceof WP_Post || PostType::POST_TYPE !== $post->post_type ) {
			return $where;
		}
		$hidden = array_map( 'intval', $this->access->hidden_gallery_ids() );
		if ( $hidden ) {
			$where .= ' AND p.ID NOT IN (' . implode( ',', $hidden ) . ')';
		}
		return $where;
	}

	/**
	 * Excludes images of restricted galleries from /wp/v2/media collections,
	 * including requests filtered by ?parent=.
	 *
	 * @param array $args WP_Query arguments.
	 * @return array
	 */
	public function rest_attachment_query( $args ) {
		return self::exclude( (array) $args, 'post_parent__in', 'post_parent__not_in', $this->access->restricted_gallery_ids() );
	}

	/**
	 * Excludes hidden galleries from /wp/v2/slashphoto_gallery collections,
	 * including requests filtered by ?include=.
	 *
	 * @param array $args WP_Query arguments.
	 * @return array
	 */
	public function rest_gallery_query( $args ) {
		return self::exclude( (array) $args, 'post__in', 'post__not_in', $this->access->hidden_gallery_ids() );
	}

	/**
	 * Refuses single media items of restricted galleries and single hidden
	 * galleries before the endpoint runs. (Returning an error from
	 * rest_prepare_* instead would crash the core controllers.)
	 *
	 * @param mixed            $response Response so far; null to continue.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function rest_single_item( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		$pattern = '#^/wp/v2/(media|' . preg_quote( PostType::POST_TYPE, '#' ) . ')/(\d+)#';
		if ( ! preg_match( $pattern, $request->get_route(), $m ) ) {
			return $response;
		}
		$post = get_post( (int) $m[2] );
		if ( ! $post ) {
			return $response;
		}
		$blocked = 'media' === $m[1]
			? 'attachment' === $post->post_type && $this->attachment_restricted( $post )
			: PostType::POST_TYPE === $post->post_type && $this->access->hidden_from_listings( $post );
		return $blocked ? self::not_found() : $response;
	}

	/**
	 * Excludes IDs from query arguments.
	 *
	 * WP_Query ignores "*__not_in" whenever the matching "*__in" is set (it
	 * uses if/elseif), so in that case the IDs are removed from the "in" list
	 * instead, and a list that becomes empty is replaced by [0] (no match).
	 *
	 * @param array  $args       Query arguments.
	 * @param string $in_key     For example "post__in" or "post_parent__in".
	 * @param string $not_in_key For example "post__not_in" or "post_parent__not_in".
	 * @param int[]  $ids        IDs to exclude.
	 * @return array
	 */
	public static function exclude( array $args, string $in_key, string $not_in_key, array $ids ): array {
		if ( ! $ids ) {
			return $args;
		}
		$in = array_filter( array_map( 'intval', (array) ( $args[ $in_key ] ?? [] ) ) );
		if ( $in ) {
			$left            = array_values( array_diff( $in, $ids ) );
			$args[ $in_key ] = $left ? $left : [ 0 ];
		} else {
			$current             = array_map( 'intval', (array) ( $args[ $not_in_key ] ?? [] ) );
			$args[ $not_in_key ] = array_values( array_unique( array_merge( $current, $ids ) ) );
		}
		return $args;
	}

	/**
	 * Keeps all protected galleries out of XML sitemaps.
	 *
	 * @param array  $args      Query arguments.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function sitemap_query_args( $args, $post_type ) {
		if ( PostType::POST_TYPE === $post_type ) {
			$protected = $this->access->protected_gallery_ids();
			if ( $protected ) {
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? [] ), $protected );
			}
		}
		return $args;
	}

	/**
	 * Refuses oEmbed for hidden galleries and restricted images.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function oembed_post_id( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $post_id;
		}
		if ( PostType::POST_TYPE === $post->post_type && ( $this->access->hidden_from_listings( $post ) || ! $this->access->can_view( $post ) ) ) {
			return 0;
		}
		if ( 'attachment' === $post->post_type && $this->attachment_restricted( $post ) ) {
			return 0;
		}
		return $post_id;
	}

	/**
	 * Whether an attachment belongs to a gallery the visitor may not see.
	 *
	 * @param WP_Post $attachment Attachment.
	 */
	public function attachment_restricted( WP_Post $attachment ): bool {
		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		return $parent && PostType::POST_TYPE === $parent->post_type && ! $this->access->can_view( $parent );
	}

	/**
	 * Whether a post embeds a protected gallery with the gallery block.
	 *
	 * @param WP_Post $post Post.
	 */
	private function embeds_protected_gallery( WP_Post $post ): bool {
		if ( ! has_block( 'slashphoto/gallery', $post ) ) {
			return false;
		}
		foreach ( self::gallery_block_ids( parse_blocks( $post->post_content ), $post->ID ) as $id ) {
			$gallery = get_post( $id );
			if ( $gallery && PostType::POST_TYPE === $gallery->post_type && $this->access->is_protected( $gallery ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collects gallery IDs referenced by gallery blocks, including nested ones.
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param int   $context Post ID used when the block has no galleryId.
	 * @return int[]
	 */
	private static function gallery_block_ids( array $blocks, int $context ): array {
		$ids = [];
		foreach ( $blocks as $block ) {
			if ( 'slashphoto/gallery' === $block['blockName'] ) {
				$id    = (int) ( $block['attrs']['galleryId'] ?? 0 );
				$ids[] = $id ? $id : $context;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::gallery_block_ids( $block['innerBlocks'], $context ) );
			}
		}
		return $ids;
	}

	/**
	 * Tells browsers, proxies and caching plugins not to cache this response.
	 */
	private function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Shared constant honoured by most caching plugins, hence no prefix.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * A generic "not found" REST error that does not reveal why.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'slashphoto' ), [ 'status' => 404 ] );
	}
}
