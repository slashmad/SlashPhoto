<?php
/**
 * Front-end assets.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the front-end stylesheet only where it is needed.
 */
final class Assets {

	public const STYLE_HANDLE = 'slashphoto-frontend';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Registers the stylesheet and enqueues it on gallery views.
	 */
	public function enqueue(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			SLASHPHOTO_URL . 'assets/css/frontend.css',
			[],
			SLASHPHOTO_VERSION
		);

		$needed = is_singular( PostType::POST_TYPE ) || is_post_type_archive( PostType::POST_TYPE );

		/**
		 * Filters whether the SlashPhoto front-end stylesheet is enqueued.
		 *
		 * Themes that style galleries completely themselves can return false.
		 *
		 * @param bool $needed Whether the current view shows a gallery.
		 */
		if ( apply_filters( 'slashphoto_enqueue_frontend_styles', $needed ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}
}
