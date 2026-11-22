<?php
/**
 * Visibility views on the gallery list screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Selections;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds admin-only filters using the gallery's effective access rules.
 */
final class GalleryList {

	private const VIEW = 'slashphoto_visibility';

	private const SELECTIONS = 'slashphoto_selections';

	/**
	 * Selection counts for the galleries on the current list page.
	 *
	 * @var array<int, array{submitted: int, choosing: int}>|null
	 */
	private ?array $counts = null;

	/**
	 * Uses the plugin's existing access rules.
	 *
	 * @param Access $access Gallery access rules.
	 */
	public function __construct( private Access $access ) {}

	/**
	 * Registers hooks for the native WordPress gallery list.
	 */
	public function register_hooks(): void {
		add_filter( 'views_edit-' . PostType::POST_TYPE, [ $this, 'views' ] );
		add_filter( 'manage_' . PostType::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . PostType::POST_TYPE . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'keep_view' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'filter' ], 20 );
	}

	/**
	 * Provides titles for the views and visibility column.
	 *
	 * @return array<string,string> View keys and human-readable names.
	 */
	public static function labels(): array {
		return [
			'public'     => __( 'Public', 'slashphoto' ),
			'private'    => __( 'Private / unpublished', 'slashphoto' ),
			'password'   => __( 'Password protected', 'slashphoto' ),
			'restricted' => __( 'Restricted', 'slashphoto' ),
		];
	}

	/**
	 * Returns the native list URL, optionally narrowed to a visibility group.
	 *
	 * @param string $group Visibility group.
	 */
	public static function url( string $group = 'all' ): string {
		return add_query_arg(
			[
				'post_type' => PostType::POST_TYPE,
				self::VIEW  => $group,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The effective group, regardless of the current user's editor access.
	 *
	 * @param WP_Post $gallery Gallery post.
	 */
	public function group( WP_Post $gallery ): string {
		foreach ( $this->access->chain( $gallery ) as $ancestor ) {
			if ( 'publish' !== $ancestor->post_status ) {
				return 'private';
			}
		}
		if ( $this->access->password_source( $gallery ) ) {
			return 'password';
		}
		return $this->access->is_protected( $gallery ) ? 'restricted' : 'public';
	}

	/**
	 * Reads the current list view from the URL.
	 */
	private static function current(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only filter does not change gallery data.
		$view = isset( $_GET[ self::VIEW ] ) && is_string( $_GET[ self::VIEW ] ) ? sanitize_key( wp_unslash( $_GET[ self::VIEW ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return isset( self::labels()[ $view ] ) ? $view : '';
	}

	/**
	 * Replaces publication status links with visibility links. Keeps All,
	 * Mine and Trash so WordPress navigation and recovery still work.
	 *
	 * @param array $views Core list views.
	 */
	public function views( array $views ): array {
		$view = self::current();
		unset( $views['publish'], $views['private'], $views['draft'], $views['pending'], $views['future'] );
		if ( isset( $views['all'] ) ) {
			// Core's unfiltered link points to the grouped overview by default.
			$views['all'] = preg_replace( '/href="[^"]*"/', 'href="' . esc_url( self::url() ) . '"', $views['all'], 1 );
			if ( '' !== $view ) {
				$views['all'] = str_replace( [ ' class="current"', ' aria-current="page"' ], '', $views['all'] );
			}
		}
		$links = [];
		foreach ( self::labels() as $key => $label ) {
			$links[ $key ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( self::url( $key ) ), $view === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}
		$overview = sprintf( '<a href="%s">%s</a>', esc_url( GalleryOverview::url() ), esc_html__( 'Overview', 'slashphoto' ) );
		return [ 'slashphoto-overview' => $overview ] + array_slice( $views, 0, 1, true ) + $links + $views;
	}

	/**
	 * Adds the visibility column next to the title.
	 *
	 * @param array $columns Core columns.
	 */
	public function columns( array $columns ): array {
		$columns = array_slice( $columns, 0, 2, true ) + [ self::VIEW => __( 'Visibility', 'slashphoto' ) ] + $columns;
		$extra   = [ self::SELECTIONS => __( 'Selections', 'slashphoto' ) ];
		return isset( $columns['date'] ) ? array_slice( $columns, 0, -1, true ) + $extra + array_slice( $columns, -1, null, true ) : $columns + $extra;
	}

	/**
	 * Prints the visibility of a listed gallery.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Gallery post ID.
	 */
	public function column( string $column, int $id ): void {
		$gallery = in_array( $column, [ self::VIEW, self::SELECTIONS ], true ) ? get_post( $id ) : null;
		if ( ! $gallery ) {
			return;
		}
		if ( self::VIEW === $column ) {
			echo esc_html( self::labels()[ $this->group( $gallery ) ] );
			return;
		}
		$this->selections_cell( $gallery );
	}

	/**
	 * Prints how many clients sent or are still choosing a selection, linked
	 * to the selection overview. Only gallery editors see the numbers.
	 *
	 * @param WP_Post $gallery Listed gallery.
	 */
	private function selections_cell( WP_Post $gallery ): void {
		if ( null === $this->counts ) {
			global $wp_query;
			$ids          = $wp_query instanceof WP_Query ? array_map( static fn( $post ) => (int) ( $post->ID ?? $post ), (array) $wp_query->posts ) : [];
			$this->counts = Selections::counts( $ids ? $ids : [ $gallery->ID ] );
		}
		$count = $this->access->is_editor( $gallery ) ? ( $this->counts[ $gallery->ID ] ?? null ) : null;
		if ( ! $count ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No selections', 'slashphoto' ) . '</span>';
			return;
		}
		$url   = add_query_arg( 'gallery_id', $gallery->ID, SelectionsPage::url() );
		$parts = [];
		if ( $count['submitted'] ) {
			$parts[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'tab', 'submitted', $url ) ),
				/* translators: %d: number of selections sent by clients */
				esc_html( sprintf( _n( '%d sent', '%d sent', $count['submitted'], 'slashphoto' ), $count['submitted'] ) )
			);
		}
		if ( $count['choosing'] ) {
			$parts[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'tab', 'choosing', $url ) ),
				/* translators: %d: number of clients still choosing */
				esc_html( sprintf( _n( '%d choosing', '%d choosing', $count['choosing'], 'slashphoto' ), $count['choosing'] ) )
			);
		}
		echo wp_kses_post( implode( '<br>', $parts ) );
	}

	/**
	 * Preserves the view when submitting search, date or bulk-action controls.
	 *
	 * @param string $post_type List post type.
	 * @param string $which     List controls position.
	 */
	public function keep_view( string $post_type, string $which ): void {
		$view = self::current();
		if ( PostType::POST_TYPE === $post_type && 'top' === $which && '' !== $view ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::VIEW ), esc_attr( $view ) );
		}
	}

	/**
	 * Narrows the native list query. WordPress still applies user permissions,
	 * search, date, ordering and pagination.
	 *
	 * @param WP_Query $query Post query.
	 */
	public function filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$screen = get_current_screen();
		$view   = self::current();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id || '' === $view ) {
			return;
		}

		$matches = [];
		foreach ( get_posts(
			[
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ),
				'numberposts'            => -1,
				'orderby'                => 'none',
				'update_post_term_cache' => false,
			]
		) as $gallery ) {
			if ( $view === $this->group( $gallery ) ) {
				$matches[] = $gallery->ID;
			}
		}
		$included = (array) $query->get( 'post__in' );
		if ( $included ) {
			$matches = array_intersect( $matches, $included );
		}
		$matches = array_diff( $matches, (array) $query->get( 'post__not_in' ) );
		$query->set( 'post__in', $matches ? array_values( $matches ) : [ 0 ] );
	}
}
