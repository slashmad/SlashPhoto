<?php
/**
 * Shared SlashPhoto admin navigation.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

/** Renders links to independently loaded sections. */
final class AdminTabs {

	/** Loads the layout shared by admin screens with tabs. */
	public static function enqueue(): void {
		$file = 'assets/css/admin-tabs.css';
		wp_enqueue_style( 'slashphoto-admin-tabs', SLASHPHOTO_URL . $file, [], Assets::version( $file ) );
	}

	/**
	 * Selects the requested section or the first one.
	 *
	 * @param array $tabs Supported sections in display order.
	 */
	public static function active( array $tabs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( $tabs[ $tab ] ) ? $tab : array_key_first( $tabs );
	}

	/**
	 * Renders bookmarkable navigation.
	 *
	 * @param array  $tabs   Map of section keys to labels.
	 * @param string $active Active section.
	 * @param string $url    Screen address.
	 * @param string $label  Accessible navigation label.
	 */
	public static function render( array $tabs, string $active, string $url, string $label ): void {
		?>
		<nav class="nav-tab-wrapper slashphoto-admin-tabs" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $tabs as $key => $title ) : ?>
				<a class="nav-tab<?php echo $active === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $url ) ); ?>" <?php echo $active === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $title ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
