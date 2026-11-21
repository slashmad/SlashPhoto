<?php
/**
 * The grouped gallery overview in WordPress admin.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Shows editable galleries by visibility and links to the full native list.
 */
final class GalleryOverview {

	private const PARENT = 'edit.php?post_type=' . PostType::POST_TYPE;
	private const SLUG   = 'slashphoto-galleries';
	private const LIMIT  = 6;

	/**
	 * The overview's screen hook, used to enqueue its stylesheet locally.
	 *
	 * @var string
	 */
	private string $screen_hook = '';

	/**
	 * Uses the same visibility groups as the native list.
	 *
	 * @param GalleryList $gallery_list Gallery list and visibility rules.
	 * @param Access      $access       Permissions for individual galleries.
	 */
	public function __construct( private GalleryList $gallery_list, private Access $access ) {}

	/** Registers the overview and the legacy list entry point. */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'load-edit.php', [ $this, 'redirect_bare_list' ] );
		add_filter( 'submenu_file', [ $this, 'highlight_menu' ] );
	}

	/** Adds the overview as the first SlashPhoto menu item. */
	public function menu(): void {
		$this->screen_hook = (string) add_submenu_page(
			self::PARENT,
			__( 'Galleries', 'slashphoto' ),
			__( 'All galleries', 'slashphoto' ),
			get_post_type_object( PostType::POST_TYPE )->cap->edit_posts,
			self::SLUG,
			[ $this, 'render' ],
			0
		);
		if ( '' !== $this->screen_hook ) {
			remove_submenu_page( self::PARENT, self::PARENT );
		}
	}

	/**
	 * Keeps the gallery menu selected in the detail list.
	 *
	 * @param string|null $file Active submenu item.
	 */
	public function highlight_menu( ?string $file ): ?string {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Menu highlighting only.
		$editing = $screen && str_ends_with( $screen->id, '_page_' . GalleryWorkspace::SLUG ) && isset( $_GET['gallery_id'] );
		return $editing || ( $screen && 'edit-' . PostType::POST_TYPE === $screen->id ) ? self::SLUG : $file;
	}

	/** Returns the overview's bookmarkable URL. */
	public static function url(): string {
		return admin_url( self::PARENT . '&page=' . self::SLUG );
	}

	/**
	 * Opens the overview for old gallery bookmarks. Requests with query
	 * parameters for searching, sorting or actions use the native list.
	 */
	public function redirect_bare_list(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id || ! current_user_can( get_post_type_object( PostType::POST_TYPE )->cap->edit_posts ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- This only redirects a read-only page request.
		if ( empty( $_POST ) && [ 'post_type' ] === array_keys( $_GET ) && wp_safe_redirect( self::url() ) ) {
			exit;
		}
	}

	/**
	 * Loads the overview stylesheet.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( $hook === $this->screen_hook ) {
			$file = 'assets/css/admin-galleries.css';
			wp_enqueue_style( 'slashphoto-gallery-overview', SLASHPHOTO_URL . $file, [ 'dashicons' ], Assets::version( $file ) );
		}
	}

	/** Renders the overview for people who can edit galleries. */
	public function render(): void {
		$caps = get_post_type_object( PostType::POST_TYPE )->cap;
		if ( ! current_user_can( $caps->edit_posts ) ) {
			return;
		}
		$groups = $this->groups();
		$icons  = [
			'public'     => 'visibility',
			'private'    => 'edit',
			'password'   => 'lock',
			'restricted' => 'admin-users',
		];
		?>
		<div class="wrap slashphoto-gallery-overview">
			<header class="slashphoto-gallery-overview__header">
				<h1><?php esc_html_e( 'Galleries', 'slashphoto' ); ?></h1>
				<div class="slashphoto-gallery-overview__actions">
					<a class="button" href="<?php echo esc_url( GalleryList::url() ); ?>"><?php esc_html_e( 'List view', 'slashphoto' ); ?></a>
					<?php if ( current_user_can( $caps->create_posts ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( GalleryWorkspace::url() ); ?>"><?php esc_html_e( 'Add new gallery', 'slashphoto' ); ?></a>
					<?php endif; ?>
				</div>
			</header>
			<div class="slashphoto-gallery-overview__groups">
				<?php
				foreach ( GalleryList::labels() as $key => $label ) {
					$this->section( $key, $label, $icons[ $key ], $groups[ $key ] );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Counts editable galleries and keeps the most recently edited in each group.
	 *
	 * @return array<string, array{count: int, items: array}>
	 */
	private function groups(): array {
		$groups = array_fill_keys(
			array_keys( GalleryList::labels() ),
			[
				'count' => 0,
				'items' => [],
			]
		);
		$posts  = get_posts(
			[
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ),
				'numberposts'            => -1,
				'orderby'                => [
					'modified' => 'DESC',
					'ID'       => 'DESC',
				],
				'update_post_term_cache' => false,
			]
		);
		foreach ( $posts as $post ) {
			if ( ! $this->access->is_editor( $post ) ) {
				continue;
			}
			$key = $this->gallery_list->group( $post );
			++$groups[ $key ]['count'];
			if ( count( $groups[ $key ]['items'] ) >= self::LIMIT ) {
				continue;
			}
			$parent = $post->post_parent ? get_post( $post->post_parent ) : null;

			$groups[ $key ]['items'][] = [
				'id'     => $post->ID,
				'edit'   => GalleryWorkspace::url( $post->ID ),
				'title'  => self::title( $post->ID ),
				'parent' => $parent && PostType::POST_TYPE === $parent->post_type && $this->access->is_editor( $parent ) ? self::title( $parent->ID ) : '',
			];
		}//end foreach
		return $groups;
	}

	/**
	 * Returns a gallery title, including for untitled drafts.
	 *
	 * @param int $id Gallery post ID.
	 */
	private static function title( int $id ): string {
		$title = get_the_title( $id );
		return '' !== $title ? $title : __( '(Untitled gallery)', 'slashphoto' );
	}

	/**
	 * Renders one visibility group.
	 *
	 * @param string $key   Group key.
	 * @param string $label Group name.
	 * @param string $icon  Dashicon name.
	 * @param array  $group Count and preview items.
	 */
	private function section( string $key, string $label, string $icon, array $group ): void {
		$id = 'slashphoto-gallery-group-' . $key;
		?>
		<section class="slashphoto-gallery-section" aria-labelledby="<?php echo esc_attr( $id ); ?>" data-visibility="<?php echo esc_attr( $key ); ?>">
			<header class="slashphoto-gallery-section__header">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<h2 id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></h2>
				<span class="slashphoto-gallery-section__count"><?php echo esc_html( number_format_i18n( $group['count'] ) ); ?></span>
				<?php if ( $group['count'] ) : ?>
					<a class="slashphoto-gallery-section__view" href="<?php echo esc_url( GalleryList::url( $key ) ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'View list', 'slashphoto' ); ?></a>
				<?php endif; ?>
			</header>
			<?php if ( $group['items'] ) : ?>
				<ul class="slashphoto-gallery-section__items">
					<?php foreach ( $group['items'] as $item ) : ?>
						<li data-gallery-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
							<a href="<?php echo esc_url( $item['edit'] ); ?>">
								<span><?php echo esc_html( $item['title'] ); ?></span>
								<?php if ( '' !== $item['parent'] ) : ?>
									<small><?php echo esc_html( $item['parent'] ); ?></small>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="slashphoto-gallery-section__empty"><?php esc_html_e( 'No galleries in this group yet.', 'slashphoto' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
