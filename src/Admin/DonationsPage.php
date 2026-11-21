<?php
/**
 * Donations screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the project's support links in one opt-in admin screen.
 */
final class DonationsPage {

	/** Registers the menu item after the other SlashPhoto screens. */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 99 );
	}

	/** Adds the submenu page. */
	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Donations', 'slashphoto' ),
			__( 'Donations', 'slashphoto' ),
			'edit_pages',
			'slashphoto-donations',
			[ $this, 'render' ]
		);
	}

	/** Shows the same funding options as the project's README. */
	public function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Donations', 'slashphoto' ); ?></h1>
			<p><?php esc_html_e( 'SlashPhoto is free to use. If you would like to support its development, choose a donation service.', 'slashphoto' ); ?></p>
			<ul class="ul-disc">
				<li><a href="https://github.com/sponsors/slashmad">GitHub Sponsors</a></li>
				<li><a href="https://ko-fi.com/slashmad">Ko-fi</a></li>
				<li><a href="https://liberapay.com/slashmad/">Liberapay</a></li>
			</ul>
		</div>
		<?php
	}
}
