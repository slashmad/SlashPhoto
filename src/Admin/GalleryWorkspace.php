<?php
/**
 * The SlashPhoto gallery editing workspace.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Media\Reprocessor;
use SlashPhoto\Shop\PriceLists;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Opens galleries in their photo management screen. */
final class GalleryWorkspace {

	public const SLUG             = 'slashphoto-gallery';
	private const PARENT          = 'edit.php?post_type=' . PostType::POST_TYPE;
	private const ACTION          = 'slashphoto_gallery_workspace';
	private const PHOTOS_PER_PAGE = 48;

	/**
	 * Workspace screen hook.
	 *
	 * @var string
	 */
	private string $screen = '';

	/**
	 * Reuses gallery permissions, image order and editing controls.
	 *
	 * @param Access             $access     Gallery permissions.
	 * @param GalleryRepository  $repository Gallery images.
	 * @param AccessSettings     $settings   Gallery settings form.
	 * @param SelectionsBox      $selections Client selections.
	 * @param UploadPanel        $upload     Chunked upload.
	 * @param GalleryInvitations $invitations Client invitation form.
	 */
	public function __construct(
		private Access $access,
		private GalleryRepository $repository,
		private AccessSettings $settings,
		private SelectionsBox $selections,
		private UploadPanel $upload,
		private GalleryInvitations $invitations
	) {}

	/** Registers the workspace and the native gallery entry points. */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'load-post.php', [ $this, 'open_existing' ] );
		add_action( 'load-post-new.php', [ $this, 'open_new' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'save' ] );
	}

	/** Registers the workspace and its Add new gallery link. */
	public function menu(): void {
		$this->screen = (string) add_submenu_page(
			self::PARENT,
			__( 'Gallery workspace', 'slashphoto' ),
			__( 'Add new gallery', 'slashphoto' ),
			get_post_type_object( PostType::POST_TYPE )->cap->edit_posts,
			self::SLUG,
			[ $this, 'render' ],
			1
		);
		remove_submenu_page( self::PARENT, 'post-new.php?post_type=' . PostType::POST_TYPE );
	}

	/**
	 * Returns a workspace address. With no ID it opens the create screen.
	 *
	 * @param int    $id  Gallery ID.
	 * @param string $tab Gallery section.
	 */
	public static function url( int $id = 0, string $tab = '' ): string {
		$url = admin_url( self::PARENT . '&page=' . self::SLUG );
		if ( $id ) {
			$url = add_query_arg( 'gallery_id', $id, $url );
		}
		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Opens the content editor explicitly.
	 *
	 * @param int $id Gallery ID.
	 */
	public static function content_url( int $id ): string {
		return add_query_arg( 'slashphoto_content', '1', admin_url( 'post.php?post=' . $id . '&action=edit' ) );
	}

	/** Routes normal gallery edit requests into the workspace. */
	public function open_existing(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only edit screen routing.
		if ( 'GET' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || 'edit' !== sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) ) || isset( $_GET['slashphoto_content'] ) ) {
			return;
		}
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $this->editable_gallery( $id ) && wp_safe_redirect( self::url( $id ) ) ) {
			exit;
		}
	}

	/** Routes WordPress gallery creation into the workspace. */
	public function open_new(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form routing.
		if ( 'GET' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && PostType::POST_TYPE === sanitize_text_field( wp_unslash( $_GET['post_type'] ?? '' ) ) && ! isset( $_GET['slashphoto_content'] ) && wp_safe_redirect( self::url() ) ) {
			exit;
		}
	}

	/**
	 * Resolves a gallery the current editor can manage.
	 *
	 * @param int $id Gallery ID.
	 */
	private function editable_gallery( int $id ): ?WP_Post {
		$post = $id ? get_post( $id ) : null;
		return $post && PostType::POST_TYPE === $post->post_type && 'trash' !== $post->post_status && $this->access->is_editor( $post ) ? $post : null;
	}

	/**
	 * Loads workspace assets.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( string $hook ): void {
		if ( $hook !== $this->screen ) {
			return;
		}
		AdminTabs::enqueue();
		SelectionsBox::enqueue_style();
		$file = 'assets/css/admin-workspace.css';
		wp_enqueue_style( 'slashphoto-workspace', SLASHPHOTO_URL . $file, [], Assets::version( $file ) );
		if ( current_user_can( 'upload_files' ) ) {
			$this->upload->enqueue_uploader();
			$file = 'assets/js/admin-workspace.js';
			wp_enqueue_script( 'slashphoto-workspace', SLASHPHOTO_URL . $file, [ UploadPanel::UPLOADER ], Assets::version( $file ), true );
			$file = 'assets/js/admin-reprocess.js';
			wp_enqueue_script( 'slashphoto-reprocess', SLASHPHOTO_URL . $file, [ 'wp-api-fetch', 'wp-i18n' ], Assets::version( $file ), true );
			wp_set_script_translations( 'slashphoto-reprocess', 'slashphoto', SLASHPHOTO_DIR . 'languages' );
		}
	}

	/** Renders the create form or the requested gallery. */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
		$id      = isset( $_GET['gallery_id'] ) ? absint( $_GET['gallery_id'] ) : 0;
		$saved   = isset( $_GET['saved'] );
		$gallery = $this->editable_gallery( $id );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ( $id && ! $gallery ) || ( ! $id && ! current_user_can( get_post_type_object( PostType::POST_TYPE )->cap->create_posts ) ) ) {
			wp_die( esc_html__( 'You cannot edit this gallery.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		$tabs = [
			'photos'     => __( 'Photos', 'slashphoto' ),
			'settings'   => __( 'Settings', 'slashphoto' ),
			'selections' => __( 'Client selections', 'slashphoto' ),
		];
		if ( $gallery && 'publish' === $gallery->post_status ) {
			$tabs['invite'] = __( 'Invite', 'slashphoto' );
		}
		$tab = AdminTabs::active( $tabs );
		?>
		<div class="wrap slashphoto-workspace">
			<p><a href="<?php echo esc_url( GalleryOverview::url() ); ?>"><?php esc_html_e( '← All galleries', 'slashphoto' ); ?></a></p>
			<header class="slashphoto-workspace__header">
				<div>
					<h1><?php echo esc_html( $gallery ? ( '' !== $gallery->post_title ? $gallery->post_title : __( '(Untitled gallery)', 'slashphoto' ) ) : __( 'Add new gallery', 'slashphoto' ) ); ?></h1>
					<?php if ( $gallery ) : ?>
						<span class="slashphoto-workspace__status"><?php echo esc_html( get_post_status_object( $gallery->post_status )->label ?? $gallery->post_status ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $gallery ) : ?>
					<div class="slashphoto-workspace__actions">
						<a class="button" href="<?php echo esc_url( self::content_url( $id ) ); ?>"><?php esc_html_e( 'Edit page content', 'slashphoto' ); ?></a>
						<?php if ( 'publish' === $gallery->post_status ) : ?>
							<a class="button" href="<?php echo esc_url( get_permalink( $gallery ) ); ?>"><?php esc_html_e( 'View gallery', 'slashphoto' ); ?></a>
						<?php elseif ( self::can_publish( $gallery ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php $this->fields( 'publish', $id ); ?>
								<?php submit_button( __( 'Publish gallery', 'slashphoto' ), 'primary', 'submit', false ); ?>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</header>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Gallery settings saved.', 'slashphoto' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $gallery ) : ?>
				<?php $this->edit_form(); ?>
			<?php else : ?>
				<?php AdminTabs::render( $tabs, $tab, self::url( $id ), __( 'Gallery sections', 'slashphoto' ) ); ?>
				<?php
				if ( 'settings' === $tab ) {
					$this->edit_form( $gallery );
				} elseif ( 'selections' === $tab ) {
					echo '<section class="slashphoto-workspace__panel" id="slashphoto-selections"><h2>' . esc_html( $tabs['selections'] ) . '</h2>';
					echo '<p><a href="' . esc_url( SelectionsPage::url() ) . '">' . esc_html__( 'View selections across galleries', 'slashphoto' ) . '</a></p>';
					$this->selections->render( $gallery );
					echo '</section>';
				} elseif ( 'invite' === $tab ) {
					$this->invitations->render( $gallery );
				} else {
					$this->photos( $gallery );
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the uploader and gallery previews.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	private function photos( WP_Post $gallery ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
		$arrange = isset( $_GET['arrange'] );
		$result  = isset( $_GET['photo_order'] ) ? sanitize_key( wp_unslash( $_GET['photo_order'] ) ) : '';
		$page    = isset( $_GET['photo_page'] ) ? absint( $_GET['photo_page'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$images = $this->repository->images( $gallery->ID );
		$total  = count( $images );
		$pages  = max( 1, (int) ceil( $total / self::PHOTOS_PER_PAGE ) );
		$page   = max( 1, min( $page, $pages ) );
		$offset = ( $page - 1 ) * self::PHOTOS_PER_PAGE;
		$base   = self::url( $gallery->ID );
		$order  = add_query_arg( 'arrange', 1, $base );
		$token  = $arrange ? GalleryRepository::order_token( $images ) : '';
		?>
		<?php if ( ! $arrange && current_user_can( 'upload_files' ) ) : ?>
			<section class="slashphoto-workspace__panel">
				<h2><?php esc_html_e( 'Upload photos', 'slashphoto' ); ?></h2>
				<?php $this->upload->render_meta_box( $gallery ); ?>
			</section>
		<?php endif; ?>
		<section class="slashphoto-workspace__panel">
			<div class="slashphoto-workspace__photo-header">
				<h2>
					<?php echo $arrange ? esc_html__( 'Reorder photos', 'slashphoto' ) : esc_html__( 'Photos', 'slashphoto' ); ?>
					<span class="slashphoto-workspace__status" data-photo-count="<?php echo esc_attr( (string) $total ); ?>"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</h2>
				<?php if ( $arrange ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'photo_page', $page, $base ) ); ?>"><?php esc_html_e( 'Done', 'slashphoto' ); ?></a>
				<?php else : ?>
					<div class="slashphoto-workspace__photo-actions">
						<a class="button" data-reorder-photos href="<?php echo esc_url( add_query_arg( 'photo_page', $page, $order ) ); ?>" <?php echo $total < 2 ? 'hidden' : ''; ?>><?php esc_html_e( 'Reorder photos', 'slashphoto' ); ?></a>
						<?php if ( $total && Reprocessor::can( $gallery->ID ) ) : ?>
							<button type="button" class="button" data-reprocess-photos data-gallery="<?php echo esc_attr( (string) $gallery->ID ); ?>" aria-describedby="slashphoto-reprocess-help"><?php esc_html_e( 'Make new copies', 'slashphoto' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! $arrange && $total && Reprocessor::can( $gallery->ID ) ) : ?>
				<p class="description" id="slashphoto-reprocess-help"><?php esc_html_e( 'Make new copies remakes the web copies of every photo in this gallery from the originals, with the current watermark, display size and image-processing settings. The originals are not changed.', 'slashphoto' ); ?></p>
				<div class="slashphoto-workspace__reprocess" data-reprocess-status role="status" hidden></div>
			<?php endif; ?>
			<?php if ( $arrange ) : ?>
				<?php
				$messages = [
					'moved'   => __( 'Photo order saved.', 'slashphoto' ),
					'stale'   => __( 'The photo order could not be saved. Review the current order and try again.', 'slashphoto' ),
					'invalid' => __( 'Choose a photo in this gallery and a position within the photo count.', 'slashphoto' ),
				];
				?>
				<?php if ( isset( $messages[ $result ] ) ) : ?>
					<div class="notice inline <?php echo 'moved' === $result ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $messages[ $result ] ); ?></p></div>
				<?php endif; ?>
				<?php if ( $total > 1 ) : ?>
					<p class="description"><?php esc_html_e( 'Enter a position and select Move. Position 1 is the first photo visitors see.', 'slashphoto' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="slashphoto-workspace__empty" <?php echo $images ? 'hidden' : ''; ?>><?php esc_html_e( 'This gallery has no photos yet.', 'slashphoto' ); ?></p>
			<ul class="slashphoto-workspace__photos" data-photo-grid>
				<?php foreach ( array_slice( $images, $offset, self::PHOTOS_PER_PAGE ) as $index => $image ) : ?>
					<?php $title = '' === $image->post_title ? __( '(Untitled photo)', 'slashphoto' ) : $image->post_title; ?>
					<li data-photo-id="<?php echo esc_attr( (string) $image->ID ); ?>">
						<?php echo wp_get_attachment_image( $image->ID, 'medium', false, [ 'alt' => '' ] ); ?>
						<span><?php echo esc_html( $title ); ?></span>
						<?php if ( $arrange && $total > 1 ) : ?>
							<form class="slashphoto-workspace__move-photo" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php $this->fields( 'move_photo', $gallery->ID ); ?>
								<input type="hidden" name="photo_id" value="<?php echo esc_attr( (string) $image->ID ); ?>">
								<input type="hidden" name="photo_order_token" value="<?php echo esc_attr( $token ); ?>">
								<?php /* translators: %s: photo title. */ ?>
								<input name="photo_position" type="number" min="1" max="<?php echo esc_attr( (string) $total ); ?>" value="<?php echo esc_attr( (string) ( $offset + $index + 1 ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Position for %s', 'slashphoto' ), $title ) ); ?>" required>
								<?php /* translators: %s: photo title. */ ?>
								<button class="button" type="submit" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s', 'slashphoto' ), $title ) ); ?>"><?php esc_html_e( 'Move', 'slashphoto' ); ?></button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $pages > 1 ) : ?>
				<div class="slashphoto-workspace__pagination">
					<?php
					echo wp_kses_post(
						paginate_links(
							[
								'base'    => add_query_arg( 'photo_page', '%#%', $arrange ? $order : $base ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							]
						)
					);
					?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders creation or gallery settings fields.
	 *
	 * @param WP_Post|null $gallery Existing gallery.
	 */
	private function edit_form( ?WP_Post $gallery = null ): void {
		?>
		<form class="slashphoto-workspace__panel slashphoto-workspace__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->fields( $gallery ? 'settings' : 'create', $gallery->ID ?? 0 ); ?>
			<p>
				<label for="slashphoto-gallery-title"><?php esc_html_e( 'Gallery name', 'slashphoto' ); ?></label>
				<input class="widefat" type="text" id="slashphoto-gallery-title" name="gallery_title" value="<?php echo esc_attr( $gallery->post_title ?? '' ); ?>" required>
			</p>
			<?php if ( $gallery ) : ?>
				<h2><?php esc_html_e( 'Access and proofing', 'slashphoto' ); ?></h2>
				<?php $this->settings->render_meta_box( $gallery, [ 'workspace' => true ] ); ?>
				<h2><?php esc_html_e( 'Shop', 'slashphoto' ); ?></h2>
				<?php $this->price_list( $gallery ); ?>
				<?php submit_button(); ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Start with a draft. You can upload photos and choose who can see the gallery before publishing.', 'slashphoto' ); ?></p>
				<?php submit_button( __( 'Create draft and add photos', 'slashphoto' ) ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Renders available price lists.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	private function price_list( WP_Post $gallery ): void {
		$current = (string) get_post_meta( $gallery->ID, PriceLists::GALLERY_META, true );
		$lists   = [
			''               => __( 'Site default or parent gallery', 'slashphoto' ),
			PriceLists::NONE => __( 'Not for sale', 'slashphoto' ),
		] + PriceLists::all();
		if ( ! isset( $lists[ $current ] ) ) {
			$lists[ $current ] = __( 'Current price list unavailable', 'slashphoto' );
		}
		?>
		<p>
			<label for="slashphoto-workspace-price-list"><?php esc_html_e( 'Price list', 'slashphoto' ); ?></label>
			<select class="widefat" name="gallery_price_list" id="slashphoto-workspace-price-list">
				<?php foreach ( $lists as $id => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, (string) $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Renders fields common to workspace actions.
	 *
	 * @param string $operation Action name.
	 * @param int    $id        Gallery ID, if any.
	 */
	private function fields( string $operation, int $id ): void {
		?>
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION . '_' . $operation . '_' . $id ) ); ?>">
		<?php wp_referer_field(); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
		<input type="hidden" name="gallery_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php
	}

	/**
	 * Whether the editor can publish an existing draft.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	private static function can_publish( WP_Post $gallery ): bool {
		return in_array( $gallery->post_status, [ 'draft', 'pending' ], true ) && current_user_can( get_post_type_object( PostType::POST_TYPE )->cap->publish_posts );
	}

	/** Creates a draft, saves gallery settings or publishes a draft. */
	public function save(): void {
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$id        = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $operation . '_' . $id );
		$gallery = $this->editable_gallery( $id );
		if ( 'move_photo' === $operation && $gallery ) {
			$this->save_photo_order( $gallery );
		}
		$title = isset( $_POST['gallery_title'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_title'] ) ) : '';
		$tab   = '';

		if ( 'create' === $operation && ! $id && current_user_can( get_post_type_object( PostType::POST_TYPE )->cap->create_posts ) ) {
			$this->require_title( $title );
			$fields = [
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $title,
			];
		} elseif ( 'settings' === $operation && $gallery ) {
			$this->require_title( $title );
			$fields = [
				'ID'         => $id,
				'post_title' => $title,
			];
			$tab    = 'settings';
		} elseif ( 'publish' === $operation && $gallery && self::can_publish( $gallery ) ) {
			$this->require_title( $gallery->post_title );
			$fields = [
				'ID'          => $id,
				'post_status' => 'publish',
			];
		} else {
			wp_die( esc_html__( 'You cannot make this change to the gallery.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}//end if
		$result = $id ? wp_update_post( wp_slash( $fields ), true ) : wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$url = self::url( (int) $result, $tab );
		if ( 'settings' === $operation ) {
			$price_list = isset( $_POST['gallery_price_list'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_price_list'] ) ) : null;
			if ( null !== $price_list && ( '' === $price_list || PriceLists::NONE === $price_list || isset( PriceLists::all()[ $price_list ] ) ) ) {
				update_post_meta( $id, PriceLists::GALLERY_META, $price_list );
			}
			$url = add_query_arg( 'saved', 1, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Moves a photo and, on success, opens the page containing its new position.
	 *
	 * @param WP_Post $gallery Authorized gallery.
	 */
	private function save_photo_order( WP_Post $gallery ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The workspace action was verified in save().
		$id       = isset( $_POST['photo_id'] ) ? absint( $_POST['photo_id'] ) : 0;
		$position = (int) filter_var( wp_unslash( $_POST['photo_position'] ?? '' ), FILTER_VALIDATE_INT );
		$token    = sanitize_text_field( wp_unslash( $_POST['photo_order_token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = $this->repository->move_photo( $gallery->ID, $id, $position, $token );
		$args   = [
			'arrange'     => 1,
			'photo_order' => $result,
		];
		if ( 'moved' === $result ) {
			$args['photo_page'] = intdiv( $position - 1, self::PHOTOS_PER_PAGE ) + 1;
		}
		wp_safe_redirect( add_query_arg( $args, self::url( $gallery->ID ) ), 303 );
		exit;
	}

	/**
	 * Validates the gallery name.
	 *
	 * @param string $title Gallery name.
	 */
	private function require_title( string $title ): void {
		if ( '' === trim( $title ) ) {
			wp_die( esc_html__( 'Enter a name for the gallery.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}
	}
}
