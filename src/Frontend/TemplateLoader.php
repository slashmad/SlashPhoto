<?php
/**
 * Overridable template loading.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and renders template partials.
 *
 * Lookup order for a partial such as "gallery.php":
 *  1. child theme:  wp-content/themes/<child>/slashphoto/gallery.php
 *  2. parent theme: wp-content/themes/<parent>/slashphoto/gallery.php
 *  3. the plugin:   templates/gallery.php
 * The result can be changed with the slashphoto_locate_template filter.
 */
final class TemplateLoader {

	public const THEME_DIR = 'slashphoto';

	/**
	 * Returns the absolute path of a template partial.
	 *
	 * @param string $name Partial name relative to templates/, e.g. "gallery.php".
	 */
	public function locate( string $name ): string {
		$name = ltrim( str_replace( '\\', '/', $name ), '/' );
		if ( '' === $name || str_contains( $name, '..' ) ) {
			return '';
		}

		$path = locate_template( [ self::THEME_DIR . '/' . $name ] );
		if ( '' === $path ) {
			$path = SLASHPHOTO_DIR . 'templates/' . $name;
		}

		/**
		 * Filters the resolved path of a SlashPhoto template partial.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Partial name, e.g. "gallery.php".
		 */
		return (string) apply_filters( 'slashphoto_locate_template', $path, $name );
	}

	/**
	 * Renders a template partial and returns the markup.
	 *
	 * The partial receives $args, like get_template_part().
	 *
	 * @param string               $name Partial name.
	 * @param array<string, mixed> $args Variables for the partial.
	 */
	public function render( string $name, array $args = [] ): string {
		$path = $this->locate( $name );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		load_template( $path, false, $args );
		return (string) ob_get_clean();
	}
}
