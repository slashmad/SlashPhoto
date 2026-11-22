<?php
/**
 * SlashPhoto settings screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\GalleryDirectory;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Settings: watermark image and defaults.
 */
final class SettingsPage {

	public const SLUG  = 'slashphoto-settings';
	public const GROUP = 'slashphoto_settings_group';

	/**
	 * Registered settings screen.
	 *
	 * @var string
	 */
	private string $screen = '';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Adds the submenu page.
	 */
	public function menu(): void {
		$this->screen = (string) add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'SlashPhoto settings', 'slashphoto' ),
			__( 'Settings', 'slashphoto' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Loads settings and watermark preview assets.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->screen === $hook ) {
			AdminTabs::enqueue();
			$file = 'assets/js/admin-watermark.js';
			wp_enqueue_script( 'slashphoto-watermark', SLASHPHOTO_URL . $file, [], Assets::version( $file ), true );
		}
	}

	/**
	 * Registers the option with its sanitiser.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			[
				'type'              => 'object',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$name     = Settings::OPTION;
		$labels   = [
			'center'       => __( 'Centre', 'slashphoto' ),
			'top-left'     => __( 'Top left', 'slashphoto' ),
			'top-right'    => __( 'Top right', 'slashphoto' ),
			'bottom-left'  => __( 'Bottom left', 'slashphoto' ),
			'bottom-right' => __( 'Bottom right', 'slashphoto' ),
			'tile'         => __( 'Repeated across the image', 'slashphoto' ),
		];
		$tabs     = [
			'watermark' => __( 'Watermark', 'slashphoto' ),
			'galleries' => __( 'Galleries', 'slashphoto' ),
			'copies'    => __( 'Display copies', 'slashphoto' ),
			'snapotter' => __( 'SnapOtter', 'slashphoto' ),
			'proofing'  => __( 'Proofing', 'slashphoto' ),
			'visitors'  => __( 'Visitors', 'slashphoto' ),
		];
		$tab      = AdminTabs::active( $tabs );
		$pngs     = [];
		if ( 'watermark' === $tab ) {
			$pngs     = get_posts(
				[
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/png',
					'post_status'    => 'inherit',
					'numberposts'    => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
				]
			);
			$selected = (int) $settings['watermark_id'];
			if ( $selected && ! in_array( $selected, wp_list_pluck( $pngs, 'ID' ), true ) ) {
				$pngs[] = get_post( $selected );
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SlashPhoto settings', 'slashphoto' ); ?></h1>
			<?php AdminTabs::render( $tabs, $tab, Settings::admin_url(), __( 'Settings sections', 'slashphoto' ) ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_section]" value="<?php echo esc_attr( 'general_' . $tab ); ?>">
				<?php if ( 'watermark' === $tab ) : ?>
					<h2><?php esc_html_e( 'Watermark', 'slashphoto' ); ?></h2>
					<p><?php esc_html_e( 'The watermark is stamped on the web copies of images uploaded to galleries that use it. Originals are never changed. Changes apply to images uploaded afterwards. 360° panoramas are not watermarked.', 'slashphoto' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slashphoto-watermark-id"><?php esc_html_e( 'Watermark image', 'slashphoto' ); ?></label></th>
							<td>
								<select id="slashphoto-watermark-id" name="<?php echo esc_attr( $name ); ?>[watermark_id]">
									<option value="0"><?php esc_html_e( 'None', 'slashphoto' ); ?></option>
									<?php foreach ( $pngs as $png ) : ?>
										<option value="<?php echo esc_attr( (string) $png->ID ); ?>" data-preview="<?php echo esc_url( wp_get_attachment_image_url( $png->ID, 'full' ) ); ?>" <?php selected( $settings['watermark_id'], $png->ID ); ?>><?php echo esc_html( get_the_title( $png ) . ' (' . wp_basename( (string) get_attached_file( $png->ID ) ) . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'A PNG with a transparent background, uploaded to the media library.', 'slashphoto' ); ?></p>
								<?php $preview = wp_get_attachment_image_url( $settings['watermark_id'], 'full' ); ?>
								<div class="slashphoto-watermark-preview" <?php echo $preview ? '' : 'hidden'; ?>>
									<img id="slashphoto-watermark-preview" alt="<?php esc_attr_e( 'Watermark preview', 'slashphoto' ); ?>"
									<?php
									if ( $preview ) {
										echo 'src="' . esc_url( $preview ) . '"';
									}
									?>
									>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Use by default', 'slashphoto' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[watermark_default]" value="1" <?php checked( $settings['watermark_default'] ); ?>> <?php esc_html_e( 'Watermark new galleries unless a gallery says otherwise', 'slashphoto' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-watermark-position"><?php esc_html_e( 'Position', 'slashphoto' ); ?></label></th>
							<td>
								<select id="slashphoto-watermark-position" name="<?php echo esc_attr( $name ); ?>[watermark_position]">
									<?php foreach ( Settings::POSITIONS as $position ) : ?>
										<option value="<?php echo esc_attr( $position ); ?>" <?php selected( $settings['watermark_position'], $position ); ?>><?php echo esc_html( $labels[ $position ] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-watermark-scale"><?php esc_html_e( 'Size (% of image width)', 'slashphoto' ); ?></label></th>
							<td><input type="number" id="slashphoto-watermark-scale" min="5" max="100" name="<?php echo esc_attr( $name ); ?>[watermark_scale]" value="<?php echo esc_attr( (string) $settings['watermark_scale'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-watermark-opacity"><?php esc_html_e( 'Opacity (%)', 'slashphoto' ); ?></label></th>
							<td><input type="number" id="slashphoto-watermark-opacity" min="5" max="100" name="<?php echo esc_attr( $name ); ?>[watermark_opacity]" value="<?php echo esc_attr( (string) $settings['watermark_opacity'] ); ?>"></td>
						</tr>
					</table>
				<?php elseif ( 'galleries' === $tab ) : ?>
					<h2><?php esc_html_e( 'Public gallery directory', 'slashphoto' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slashphoto-directory-page"><?php esc_html_e( 'Gallery directory page', 'slashphoto' ); ?></label></th>
							<td>
								<select id="slashphoto-directory-page" name="<?php echo esc_attr( $name ); ?>[gallery_directory_page]" aria-describedby="slashphoto-directory-help">
									<option value="0"><?php esc_html_e( 'Use the theme’s gallery archive', 'slashphoto' ); ?></option>
									<?php foreach ( array_filter( (array) get_pages(), [ GalleryDirectory::class, 'is_directory_page' ] ) as $page ) : ?>
										<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $settings['gallery_directory_page'], $page->ID ); ?>><?php echo esc_html( get_the_title( $page ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description" id="slashphoto-directory-help">
									<?php esc_html_e( 'Choose a public page with the following shortcode. Visitors who open the gallery archive will land on that page.', 'slashphoto' ); ?>
									<code>[slashphoto_galleries]</code>
								</p>
							</td>
						</tr>
					</table>
				<?php elseif ( 'copies' === $tab ) : ?>
					<h2><?php esc_html_e( 'Display copies', 'slashphoto' ); ?></h2>
					<p><?php esc_html_e( 'Upload one full-size original per image. Visitors see a display copy; the original is stored privately for downloads and sales. Changes apply to images uploaded afterwards.', 'slashphoto' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slashphoto-display-size"><?php esc_html_e( 'Longest side (pixels)', 'slashphoto' ); ?></label></th>
							<td>
								<input type="number" id="slashphoto-display-size" min="1024" max="8192" step="1" name="<?php echo esc_attr( $name ); ?>[display_size]" value="<?php echo esc_attr( (string) $settings['display_size'] ); ?>">
								<p class="description"><?php esc_html_e( '2560 suits most screens. 360° panoramas keep up to 8192 pixels.', 'slashphoto' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-display-quality"><?php esc_html_e( 'JPEG quality', 'slashphoto' ); ?></label></th>
							<td><input type="number" id="slashphoto-display-quality" min="60" max="100" name="<?php echo esc_attr( $name ); ?>[display_quality]" value="<?php echo esc_attr( (string) $settings['display_quality'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Upload size', 'slashphoto' ); ?></th>
							<td>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: size such as "8 MB" */
											__( 'The gallery’s photo uploader transfers files in pieces. The WordPress media library uses this server’s normal upload limit of %s.', 'slashphoto' ),
											size_format( wp_max_upload_size() )
										)
									);
									?>
								</p>
							</td>
						</tr>
					</table>
				<?php elseif ( 'snapotter' === $tab ) : ?>
					<h2><?php esc_html_e( 'Image processing with SnapOtter', 'slashphoto' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slashphoto-snapotter-mode"><?php esc_html_e( 'Use SnapOtter for', 'slashphoto' ); ?></label></th>
							<td>
								<select id="slashphoto-snapotter-mode" name="<?php echo esc_attr( $name ); ?>[snapotter_mode]">
									<option value="heavy" <?php selected( $settings['snapotter_mode'], 'heavy' ); ?>><?php esc_html_e( 'RAW, HEIC, TIFF, PSD and very large images', 'slashphoto' ); ?></option>
									<option value="all" <?php selected( $settings['snapotter_mode'], 'all' ); ?>><?php esc_html_e( 'All gallery uploads', 'slashphoto' ); ?></option>
									<option value="off" <?php selected( $settings['snapotter_mode'], 'off' ); ?>><?php esc_html_e( 'Nothing (WordPress makes all display copies)', 'slashphoto' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Applies when a SnapOtter server is connected. If SnapOtter cannot be reached, WordPress makes the copy itself for JPEG, PNG, WebP and AVIF.', 'slashphoto' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-snapotter-min-mp"><?php esc_html_e( '“Very large” from (megapixels)', 'slashphoto' ); ?></label></th>
							<td><input type="number" id="slashphoto-snapotter-min-mp" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[snapotter_min_mp]" value="<?php echo esc_attr( (string) $settings['snapotter_min_mp'] ); ?>"></td>
						</tr>
					</table>
				<?php elseif ( 'proofing' === $tab ) : ?>
					<h2><?php esc_html_e( 'Client selections', 'slashphoto' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Confirmation', 'slashphoto' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[selection_client_email]" value="1" <?php checked( $settings['selection_client_email'] ); ?>> <?php esc_html_e( 'Email clients a copy of the selection they send', 'slashphoto' ); ?></label>
								<p class="description"><?php esc_html_e( 'Goes to the email address of the client’s account and lists the chosen file names, notes and message. Replies go to the gallery’s author. You are always emailed when a selection arrives.', 'slashphoto' ); ?></p>
							</td>
						</tr>
					</table>
				<?php elseif ( 'visitors' === $tab ) : ?>
					<h2><?php esc_html_e( 'Visitors', 'slashphoto' ); ?></h2>
					<p><?php esc_html_e( 'SlashPhoto never stores IP addresses or browser details. What it can store about visitors is listed here and in the suggested privacy policy text.', 'slashphoto' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Statistics', 'slashphoto' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[stats_enabled]" value="1" <?php checked( $settings['stats_enabled'] ); ?>> <?php esc_html_e( 'Count how often galleries and photos are viewed', 'slashphoto' ); ?></label>
								<p class="description"><?php esc_html_e( 'Anonymous daily totals only: no cookies, no IP addresses, nothing that identifies a visitor. Your own views and those of search robots are not counted.', 'slashphoto' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Terms', 'slashphoto' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[terms_ask_email]" value="1" <?php checked( $settings['terms_ask_email'] ); ?>> <?php esc_html_e( 'Ask visitors who are not logged in for their email address when they accept a gallery’s terms', 'slashphoto' ); ?></label>
								<p class="description"><?php esc_html_e( 'Every acceptance is logged with the date, the wording that was accepted and the account or email address, so you can show who agreed to what.', 'slashphoto' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="slashphoto-retention"><?php esc_html_e( 'Keep the visitor log for (days)', 'slashphoto' ); ?></label></th>
							<td>
								<input type="number" id="slashphoto-retention" min="0" max="3650" name="<?php echo esc_attr( $name ); ?>[visitor_retention_days]" value="<?php echo esc_attr( (string) $settings['visitor_retention_days'] ); ?>">
								<p class="description"><?php esc_html_e( 'Email addresses given to open a gallery and terms acceptances are deleted after this many days; 0 keeps them. View counts contain nothing personal and are kept.', 'slashphoto' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
			<?php
			/**
			 * Fires below the SlashPhoto settings form.
			 *
			 * @param string $tab Active settings section.
			 */
			do_action( 'slashphoto_settings_after_form', $tab );
			?>
		</div>
		<?php
	}
}
