<?php
/**
 * Client selection overview in SlashPhoto admin.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Lists recent client selections across galleries the current user can edit. */
final class SelectionsPage {

	public const SLUG      = 'slashphoto-selections';
	private const PARENT   = 'edit.php?post_type=' . PostType::POST_TYPE;
	private const PER_PAGE = 12;

	/**
	 * Admin screen hook for the overview.
	 *
	 * @var string
	 */
	private string $screen = '';

	/**
	 * Uses the proofing permissions for every gallery in the overview.
	 *
	 * @param Proofing $proofing Selection and gallery rules.
	 */
	public function __construct( private Proofing $proofing ) {}

	/** Registers the page and its assets. */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** Adds the overview to the SlashPhoto menu. */
	public function menu(): void {
		$this->screen = (string) add_submenu_page(
			self::PARENT,
			__( 'Client selections', 'slashphoto' ),
			__( 'Client selections', 'slashphoto' ),
			get_post_type_object( PostType::POST_TYPE )->cap->edit_posts,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Loads styles for the overview only.
	 *
	 * @param string $hook Admin screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( $hook === $this->screen ) {
			AdminTabs::enqueue();
			SelectionsBox::enqueue_style();
		}
	}

	/** Returns the overview URL. */
	public static function url(): string {
		return admin_url( self::PARENT . '&page=' . self::SLUG );
	}

	/**
	 * Returns only editable galleries with existing selection records.
	 *
	 * @return array<int, WP_Post>
	 */
	public function galleries(): array {
		$ids = Selections::root_ids();
		if ( ! $ids ) {
			return [];
		}
		$posts  = get_posts(
			[
				'post_type'   => PostType::POST_TYPE,
				'post_status' => array_values( array_diff( get_post_stati(), [ 'trash', 'auto-draft', 'inherit' ] ) ),
				'include'     => $ids,
				'orderby'     => 'title',
				'order'       => 'ASC',
			]
		);
		$result = [];
		foreach ( $posts as $gallery ) {
			if ( $this->proofing->can_review( $gallery ) ) {
				$result[ $gallery->ID ] = $gallery;
			}
		}
		return $result;
	}

	/** Renders the filters and paginated selection cards. */
	public function render(): void {
		$tabs   = [
			'all'       => __( 'All', 'slashphoto' ),
			'submitted' => __( 'Submitted', 'slashphoto' ),
			'choosing'  => __( 'Still choosing', 'slashphoto' ),
		];
		$active = AdminTabs::active( $tabs );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$id   = isset( $_GET['gallery_id'] ) ? absint( $_GET['gallery_id'] ) : 0;
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$galleries = $this->galleries();
		$roots     = $id ? array_intersect( [ $id ], array_keys( $galleries ) ) : array_keys( $galleries );
		$results   = Selections::review_page( $roots, $active, $page, self::PER_PAGE );
		$url       = $id ? add_query_arg( 'gallery_id', $id, self::url() ) : self::url();
		?>
		<div class="wrap slashphoto-selections">
			<h1><?php esc_html_e( 'Client selections', 'slashphoto' ); ?></h1>
			<?php AdminTabs::render( $tabs, $active, $url, __( 'Selection status', 'slashphoto' ) ); ?>
			<?php if ( $galleries ) : ?>
				<form class="slashphoto-selections__filter" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::POST_TYPE ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $active ); ?>">
					<label for="slashphoto-selection-gallery"><?php esc_html_e( 'Gallery', 'slashphoto' ); ?></label>
					<select name="gallery_id" id="slashphoto-selection-gallery">
						<option value="0"><?php esc_html_e( 'All galleries', 'slashphoto' ); ?></option>
						<?php foreach ( $galleries as $gallery ) : ?>
							<option value="<?php echo esc_attr( (string) $gallery->ID ); ?>" <?php selected( $id, $gallery->ID ); ?>><?php echo esc_html( _draft_or_post_title( $gallery->ID ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'slashphoto' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
			<?php if ( $results->posts ) : ?>
				<p class="slashphoto-selections__count">
					<?php
					/* translators: %s: number of selections. */
					echo esc_html( sprintf( _n( '%s selection', '%s selections', $results->found_posts, 'slashphoto' ), number_format_i18n( $results->found_posts ) ) );
					?>
				</p>
				<div class="slashphoto-selections__grid">
					<?php foreach ( $results->posts as $selection ) : ?>
						<?php $this->card( $selection, $galleries[ $selection->post_parent ] ); ?>
					<?php endforeach; ?>
				</div>
				<?php if ( $results->max_num_pages > 1 ) : ?>
					<nav class="slashphoto-selections__pagination" aria-label="<?php esc_attr_e( 'Selection pages', 'slashphoto' ); ?>">
						<?php
						echo wp_kses_post(
							paginate_links(
								[
									'base'    => add_query_arg(
										[
											'tab'   => $active,
											'paged' => '%#%',
										],
										$url
									),
									'format'  => '',
									'current' => $page,
									'total'   => $results->max_num_pages,
								]
							) ?? ''
						);
						?>
					</nav>
				<?php endif; ?>
			<?php else : ?>
				<p class="slashphoto-selection-card"><?php esc_html_e( 'No client selections match these filters.', 'slashphoto' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a summary and a link to the selection in its gallery.
	 *
	 * @param WP_Post $selection Client selection.
	 * @param WP_Post $gallery   Editable gallery.
	 */
	private function card( WP_Post $selection, WP_Post $gallery ): void {
		$client = Selections::client( $selection );
		$sent   = Selections::submitted_at( $selection );
		$time   = $sent ? $sent : (int) get_post_modified_time( 'U', true, $selection );
		$link   = add_query_arg( 'selection_id', $selection->ID, GalleryWorkspace::url( $gallery->ID, 'selections' ) );
		?>
		<article class="slashphoto-selection-card">
			<div class="slashphoto-selection-card__head">
				<h2><?php echo esc_html( _draft_or_post_title( $gallery->ID ) ); ?></h2>
				<?php SelectionsBox::status( $sent ); ?>
			</div>
			<p><?php echo esc_html( $client ? $client->display_name : __( 'Deleted user', 'slashphoto' ) ); ?></p>
			<p class="slashphoto-selection-card__meta"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?></p>
			<div class="slashphoto-selection-card__actions">
				<a class="button" href="<?php echo esc_url( $link ); ?>">
					<?php esc_html_e( 'Review selection', 'slashphoto' ); ?>
				</a>
			</div>
		</article>
		<?php
	}
}
