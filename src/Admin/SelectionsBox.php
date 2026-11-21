<?php
/**
 * Client selections on the gallery edit screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

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
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
	}

	/**
	 * Adds the box when the gallery is a proofing root or already has selections.
	 *
	 * @param WP_Post $post Gallery.
	 */
	public function add_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post || ( ! $this->proofing->is_root( $post ) && ! Selections::for_root( $post->ID ) ) ) {
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
		$selections = Selections::for_root( $post->ID );
		if ( ! $selections ) {
			echo '<p>' . esc_html__( 'No client has chosen any images yet. Clients choose favourites while logged in and send them to you from the gallery page.', 'slashphoto' ) . '</p>';
			return;
		}
		echo '<style>.slashphoto-sel{border-top:1px solid #dcdcde;padding:12px 0}.slashphoto-sel:first-child{border-top:0;padding-top:0}.slashphoto-sel h4{margin:0 0 4px}.slashphoto-sel-grid{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0;padding:0;list-style:none}.slashphoto-sel-grid li{width:96px;margin:0;font-size:12px;overflow-wrap:anywhere}.slashphoto-sel-grid img{display:block;width:96px;height:72px;object-fit:cover}.slashphoto-sel-note{display:block;color:#50575e;font-style:italic}.slashphoto-sel .button-link-delete{margin-left:12px}</style>';

		foreach ( $selections as $selection ) {
			$client = Selections::client( $selection );
			$items  = array_filter( Selections::items( $selection ), static fn( $note, $id ): bool => (bool) get_post( (int) $id ), ARRAY_FILTER_USE_BOTH );
			$sent   = Selections::submitted_at( $selection );
			$labels = array_map( [ Proofing::class, 'file_label' ], array_keys( $items ) );
			?>
			<div class="slashphoto-sel">
				<h4>
					<?php echo esc_html( $client ? $client->display_name : __( 'Deleted user', 'slashphoto' ) ); ?>
					<?php if ( $client ) : ?>
						<a href="<?php echo esc_url( 'mailto:' . $client->user_email ); ?>"><?php echo esc_html( $client->user_email ); ?></a>
					<?php endif; ?>
				</h4>
				<p>
					<?php
					echo esc_html(
						$sent
							/* translators: 1: number of images, 2: date and time */
							? sprintf( _n( '%1$d image, sent %2$s', '%1$d images, sent %2$s', count( $items ), 'slashphoto' ), count( $items ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent ) )
							/* translators: %d: number of images */
							: sprintf( _n( '%d image, still choosing', '%d images, still choosing', count( $items ), 'slashphoto' ), count( $items ) )
					);
					?>
				</p>
				<?php if ( '' !== Selections::message( $selection ) ) : ?>
					<blockquote><?php echo nl2br( esc_html( Selections::message( $selection ) ) ); ?></blockquote>
				<?php endif; ?>
				<?php if ( $items ) : ?>
					<ul class="slashphoto-sel-grid">
						<?php foreach ( $items as $id => $note ) : ?>
							<li>
								<?php echo wp_get_attachment_image( (int) $id, 'thumbnail' ); ?>
								<?php echo esc_html( Proofing::file_label( (int) $id ) ); ?>
								<?php if ( '' !== $note ) : ?>
									<span class="slashphoto-sel-note"><?php echo esc_html( $note ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p>
						<label for="slashphoto-sel-files-<?php echo esc_attr( (string) $selection->ID ); ?>"><?php esc_html_e( 'File names (for a catalogue filter such as Lightroom):', 'slashphoto' ); ?></label>
						<textarea id="slashphoto-sel-files-<?php echo esc_attr( (string) $selection->ID ); ?>" class="widefat" rows="2" readonly onfocus="this.select()"><?php echo esc_textarea( implode( ', ', $labels ) ); ?></textarea>
					</p>
				<?php endif; ?>
				<p>
					<?php if ( $sent ) : ?>
						<a class="button" href="<?php echo esc_url( $this->action_url( $selection, 'reopen' ) ); ?>"><?php esc_html_e( 'Let the client change it', 'slashphoto' ); ?></a>
					<?php endif; ?>
					<a class="button-link button-link-delete" href="<?php echo esc_url( $this->action_url( $selection, 'delete' ) ); ?>" onclick="return window.confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Delete this selection? This cannot be undone.', 'slashphoto' ); ?>"><?php esc_html_e( 'Delete selection', 'slashphoto' ); ?></a>
				</p>
			</div>
			<?php
		}//end foreach
	}

	/**
	 * Signed link for reopen/delete.
	 *
	 * @param WP_Post $selection Selection.
	 * @param string  $operation "reopen" or "delete".
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
	 * Handles reopen/delete.
	 */
	public function handle_action(): void {
		$id        = isset( $_GET['selection'] ) ? absint( $_GET['selection'] ) : 0;
		$operation = isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( $_GET['operation'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $operation . '_' . $id );

		$selection = get_post( $id );
		if ( ! $selection || Selections::POST_TYPE !== $selection->post_type || ! current_user_can( 'edit_post', $selection->post_parent ) ) {
			wp_die( esc_html__( 'You are not allowed to change this selection.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}

		if ( 'reopen' === $operation ) {
			Selections::reopen( $selection );
		} elseif ( 'delete' === $operation ) {
			wp_delete_post( $selection->ID, true );
		}

		/**
		 * Fires after the photographer reopened or deleted a selection.
		 *
		 * @param string  $operation "reopen" or "delete".
		 * @param WP_Post $selection The selection (already deleted for "delete").
		 */
		do_action( 'slashphoto_selection_changed', $operation, $selection );

		wp_safe_redirect( GalleryWorkspace::url( $selection->post_parent, 'selections' ) );
		exit;
	}
}
