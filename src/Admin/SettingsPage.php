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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
