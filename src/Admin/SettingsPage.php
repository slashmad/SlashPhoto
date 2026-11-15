<?php
/**
 * SlashPhoto settings screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

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
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
	}

	/**
	 * Adds the submenu page.
	 */
	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'SlashPhoto settings', 'slashphoto' ),
			__( 'Settings', 'slashphoto' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
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
		$labels   = [
			'center'       => __( 'Centre', 'slashphoto' ),
			'top-left'     => __( 'Top left', 'slashphoto' ),
			'top-right'    => __( 'Top right', 'slashphoto' ),
			'bottom-left'  => __( 'Bottom left', 'slashphoto' ),
			'bottom-right' => __( 'Bottom right', 'slashphoto' ),
			'tile'         => __( 'Repeated across the image', 'slashphoto' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SlashPhoto settings', 'slashphoto' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Watermark', 'slashphoto' ); ?></h2>
				<p><?php esc_html_e( 'The watermark is stamped on the web copies of images uploaded to galleries that use it. Originals are never changed. Changes apply to images uploaded afterwards. 360° panoramas are not watermarked.', 'slashphoto' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-watermark-id"><?php esc_html_e( 'Watermark image', 'slashphoto' ); ?></label></th>
						<td>
							<select id="slashphoto-watermark-id" name="<?php echo esc_attr( $name ); ?>[watermark_id]">
								<option value="0"><?php esc_html_e( 'None', 'slashphoto' ); ?></option>
								<?php foreach ( $pngs as $png ) : ?>
									<option value="<?php echo esc_attr( (string) $png->ID ); ?>" <?php selected( $settings['watermark_id'], $png->ID ); ?>><?php echo esc_html( get_the_title( $png ) . ' (' . wp_basename( (string) get_attached_file( $png->ID ) ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'A PNG with a transparent background, uploaded to the media library.', 'slashphoto' ); ?></p>
							<?php if ( $settings['watermark_id'] ) : ?>
								<p><?php echo wp_get_attachment_image( (int) $settings['watermark_id'], 'thumbnail', false, [ 'style' => 'background:repeating-conic-gradient(#ccc 0 25%,#fff 0 50%) 0 0/16px 16px;max-width:200px;height:auto' ] ); ?></p>
							<?php endif; ?>
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
										__( 'The “Upload photos” panel in the gallery editor sends files in pieces, so originals of any size work, even though this server’s normal upload limit is %s. The WordPress media library keeps that limit.', 'slashphoto' ),
										size_format( wp_max_upload_size() )
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>

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
							<p class="description"><?php esc_html_e( 'Only applies when a SnapOtter server is connected below. If SnapOtter cannot be reached, WordPress makes the copy itself for JPEG, PNG, WebP and AVIF.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-snapotter-min-mp"><?php esc_html_e( '“Very large” from (megapixels)', 'slashphoto' ); ?></label></th>
						<td><input type="number" id="slashphoto-snapotter-min-mp" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[snapotter_min_mp]" value="<?php echo esc_attr( (string) $settings['snapotter_min_mp'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
			/**
			 * Fires below the SlashPhoto settings form (for sections with their own forms).
			 */
			do_action( 'slashphoto_settings_after_form' );
			?>
		</div>
		<?php
	}
}
