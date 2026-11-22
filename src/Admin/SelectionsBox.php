<?php
/**
 * Client selections on the gallery edit screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box listing every client's selection of a proofing gallery: images
 * with notes, the message, and the file names to paste into a photo
 * catalogue. The photographer can reopen a sent selection or delete it.
 */
final class SelectionsBox {

	public const ACTION = 'slashphoto_selection';

	/**
	 * Proofing rules.
	 *
	 * @var Proofing
	 */
	private Proofing $proofing;

	/**
	 * Constructor.
	 *
	 * @param Proofing $proofing Proofing rules.
	 */
	public function __construct( Proofing $proofing ) {
		$this->proofing = $proofing;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
	}

	/** Loads the appearance shared by the workspace, editor and overview. */
	public static function enqueue_style(): void {
		$file = 'assets/css/admin-selections.css';
		wp_enqueue_style( 'slashphoto-selections', SLASHPHOTO_URL . $file, [], Assets::version( $file ) );
	}

	/** Loads the selection styles in the native gallery editor. */
	public function enqueue_editor(): void {
		$screen = get_current_screen();
		if ( $screen && 'post' === $screen->base && PostType::POST_TYPE === $screen->post_type ) {
			self::enqueue_style();
		}
	}

	/**
	 * Adds the box when the gallery is a proofing root or already has selections.
	 *
	 * @param WP_Post $post Gallery.
	 */
	public function add_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post || ! $this->proofing->can_review( $post ) || ( ! $this->proofing->is_root( $post ) && ! Selections::for_root( $post->ID ) ) ) {
			return;
		}
		add_meta_box( 'slashphoto-selections', __( 'Client selections', 'slashphoto' ), [ $this, 'render' ], PostType::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renders the box.
	 *
	 * @param WP_Post $post Gallery.
	 */
	public function render( WP_Post $post ): void {
		if ( ! $this->proofing->can_review( $post ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail navigation within the authorized gallery.
		$id = isset( $_GET['selection_id'] ) ? absint( $_GET['selection_id'] ) : 0;
		if ( $id ) {
			echo '<p><a href="' . esc_url( GalleryWorkspace::url( $post->ID, 'selections' ) ) . '">' . esc_html__( 'All selections in this gallery', 'slashphoto' ) . '</a></p>';
			$selection = get_post( $id );
			if ( ! $selection || Selections::POST_TYPE !== $selection->post_type || 'private' !== $selection->post_status || $post->ID !== $selection->post_parent ) {
				echo '<p>' . esc_html__( 'This selection could not be found in the gallery.', 'slashphoto' ) . '</p>';
				return;
			}
			$selections = [ $selection ];
		} else {
			$selections = Selections::for_root( $post->ID );
		}
		if ( ! $selections ) {
			echo '<p>' . esc_html__( 'No client has chosen any images yet. Clients choose favourites while logged in and send them to you from the gallery page.', 'slashphoto' ) . '</p>';
			return;
		}
		echo '<div class="slashphoto-selection-details">';
		foreach ( $selections as $selection ) {
			$this->render_selection( $post, $selection );
		}
		echo '</div>';
	}

	/**
	 * Displays a client selection and the available actions.
	 *
	 * @param WP_Post $gallery   Authorized gallery.
	 * @param WP_Post $selection Client selection.
	 */
	private function render_selection( WP_Post $gallery, WP_Post $selection ): void {
		$client  = Selections::client( $selection );
		$items   = $this->proofing->review_items( $gallery, $selection );
		$sent    = Selections::submitted_at( $selection );
		$message = Selections::message( $selection );
		$count   = count( $items );
		?>
		<article class="slashphoto-selection-card">
			<header class="slashphoto-selection-card__head">
				<div>
					<h3><?php echo esc_html( $client ? $client->display_name : __( 'Deleted user', 'slashphoto' ) ); ?></h3>
					<?php if ( $client ) : ?>
						<a href="<?php echo esc_url( 'mailto:' . $client->user_email ); ?>"><?php echo esc_html( $client->user_email ); ?></a>
					<?php endif; ?>
				</div>
				<?php self::status( $sent ); ?>
			</header>
			<p class="slashphoto-selection-card__meta">
				<?php
				/* translators: %s: number of photos in the selection. */
				echo esc_html( sprintf( _n( '%s photo', '%s photos', $count, 'slashphoto' ), number_format_i18n( $count ) ) );
				if ( $sent ) {
					echo ' · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent ) );
				}
				?>
			</p>
			<?php if ( '' !== $message ) : ?>
				<blockquote class="slashphoto-selection-card__message"><?php echo esc_html( $message ); ?></blockquote>
			<?php endif; ?>
			<?php if ( $items ) : ?>
				<ul class="slashphoto-selection-photos">
					<?php foreach ( $items as $id => $note ) : ?>
						<li>
							<?php echo wp_get_attachment_image( $id, 'medium', false, [ 'alt' => '' ] ); ?>
							<div class="slashphoto-selection-photos__caption">
								<strong><?php echo esc_html( Proofing::file_label( $id ) ); ?></strong>
								<?php if ( '' !== $note ) : ?>
									<span><?php echo esc_html( $note ); ?></span>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<details class="slashphoto-selection-card__filenames">
					<summary><?php esc_html_e( 'File names for Lightroom', 'slashphoto' ); ?></summary>
					<label class="screen-reader-text" for="slashphoto-sel-files-<?php echo esc_attr( (string) $selection->ID ); ?>"><?php esc_html_e( 'Selected photo file names', 'slashphoto' ); ?></label>
					<textarea id="slashphoto-sel-files-<?php echo esc_attr( (string) $selection->ID ); ?>" class="widefat" rows="2" readonly onfocus="this.select()"><?php echo esc_textarea( implode( ', ', array_map( [ Proofing::class, 'file_label' ], array_keys( $items ) ) ) ); ?></textarea>
				</details>
			<?php endif; ?>
			<footer class="slashphoto-selection-card__actions">
				<?php if ( $items ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $this->action_url( $selection, 'export' ) ); ?>"><?php esc_html_e( 'Download CSV', 'slashphoto' ); ?></a>
				<?php endif; ?>
				<?php if ( $sent ) : ?>
					<a class="button" href="<?php echo esc_url( $this->action_url( $selection, 'reopen' ) ); ?>"><?php esc_html_e( 'Let the client change it', 'slashphoto' ); ?></a>
				<?php endif; ?>
				<a class="button-link button-link-delete" href="<?php echo esc_url( $this->action_url( $selection, 'delete' ) ); ?>" onclick="return window.confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Delete this selection? This cannot be undone.', 'slashphoto' ); ?>"><?php esc_html_e( 'Delete selection', 'slashphoto' ); ?></a>
			</footer>
		</article>
		<?php
	}

	/**
	 * Labels the selection in the overview and review views.
	 *
	 * @param int $sent Submission timestamp, or zero.
	 */
	public static function status( int $sent ): void {
		$class = $sent ? ' slashphoto-selection-status--submitted' : '';
		echo '<span class="slashphoto-selection-status' . esc_attr( $class ) . '">' . ( $sent ? esc_html__( 'Submitted', 'slashphoto' ) : esc_html__( 'Still choosing', 'slashphoto' ) ) . '</span>';
	}

	/**
	 * Signed link for a selection action.
	 *
	 * @param WP_Post $selection Selection.
	 * @param string  $operation "export", "reopen" or "delete".
	 */
	private function action_url( WP_Post $selection, string $operation ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'    => self::ACTION,
					'operation' => $operation,
					'selection' => $selection->ID,
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $operation . '_' . $selection->ID
		);
	}

	/**
	 * Handles CSV downloads, reopening and deletion.
	 */
	public function handle_action(): void {
		$id        = isset( $_GET['selection'] ) ? absint( $_GET['selection'] ) : 0;
		$operation = isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( $_GET['operation'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $operation . '_' . $id );

		$selection = $id ? get_post( $id ) : null;
		$gallery   = $selection && $selection->post_parent ? get_post( $selection->post_parent ) : null;
		if ( ! $selection || Selections::POST_TYPE !== $selection->post_type || 'private' !== $selection->post_status || ! $gallery || ! $this->proofing->can_review( $gallery ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this selection.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}

		$url = GalleryWorkspace::url( $gallery->ID, 'selections' );
		if ( 'export' === $operation ) {
			$this->download_csv( $gallery, $selection );
		}
		if ( 'reopen' === $operation ) {
			Selections::reopen( $selection );
			$url = add_query_arg( 'selection_id', $id, $url );
		} elseif ( 'delete' === $operation ) {
			wp_delete_post( $selection->ID, true );
		} else {
			wp_die( esc_html__( 'Unknown selection action.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}

		/**
		 * Fires after the photographer reopened or deleted a selection.
		 *
		 * @param string  $operation "reopen" or "delete".
		 * @param WP_Post $selection The selection (already deleted for "delete").
		 */
		do_action( 'slashphoto_selection_changed', $operation, $selection );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Downloads the selected photos and their notes in the client's order.
	 *
	 * @param WP_Post $gallery   Authorized gallery.
	 * @param WP_Post $selection Client selection.
	 */
	private function download_csv( WP_Post $gallery, WP_Post $selection ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'The export could not be opened.', 'slashphoto' ), '', [ 'response' => 500 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="slashphoto-selection-' . $selection->ID . '.csv"' );
		fputcsv( $output, [ 'image_id', 'file_label', 'note' ], ',', '"', '' );
		foreach ( $this->proofing->review_items( $gallery, $selection ) as $id => $note ) {
			fputcsv( $output, [ $id, Csv::safe( Proofing::file_label( $id ) ), Csv::safe( $note ) ], ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
		fclose( $output );
		exit;
	}
}
