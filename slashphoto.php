<?php
/**
 * Plugin Name:       SlashPhoto
 * Description:       Open-source photography galleries, client proofing and photo sales for WordPress. No subscriptions, no locked features.
 * Version:           0.1.0-dev
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            Slashmad
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slashphoto
 * Domain Path:       /languages
 *
 * @package SlashPhoto
 */

defined( 'ABSPATH' ) || exit;

define( 'SLASHPHOTO_VERSION', '0.1.0-dev' );
define( 'SLASHPHOTO_FILE', __FILE__ );
define( 'SLASHPHOTO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLASHPHOTO_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 autoloader for the SlashPhoto\ namespace.
 *
 * The plugin runs without Composer at runtime; Composer is only used for
 * development tools (tests and coding standards).
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SlashPhoto\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = SLASHPHOTO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, [ \SlashPhoto\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \SlashPhoto\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \SlashPhoto\Plugin::instance(), 'boot' ] );
