<?php
/**
 * The public gallery directory shortcode.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\ShopPage;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Lists galleries that can be discovered on a public, cacheable page. */
final class GalleryDirectory {

	public const SHORTCODE = 'slashphoto_galleries';

	/**
	 * Sets the renderer dependencies.
	 *
	 * @param Access            $access    Gallery access rules.
	 * @param GalleryRepository $galleries Gallery image queries.
	 * @param TemplateLoader    $templates Theme-compatible templates.
	 */
	public function __construct(
		private Access $access,
		private GalleryRepository $galleries,
		private TemplateLoader $templates
	) {}

	/** Registers the public directory shortcode. */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'redirect_archive' ] );
	}

	/**
	 * Whether a published page can be used as the public directory.
	 *
	 * @param WP_Post|null $page Candidate page.
	 */
	public static function is_directory_page( ?WP_Post $page ): bool {
		return $page && 'page' === $page->post_type && 'publish' === $page->post_status
			&& '' === $page->post_password && has_shortcode( $page->post_content, self::SHORTCODE )
			&& untrailingslashit( (string) get_permalink( $page ) ) !== untrailingslashit( (string) get_post_type_archive_link( PostType::POST_TYPE ) );
	}

	/** Uses the selected WordPress page for the gallery archive entry point. */
	public function redirect_archive(): void {
		$target = self::archive_target();
		if ( '' !== $target && wp_safe_redirect( $target, 302, 'SlashPhoto' ) ) {
			exit;
		}
	}

	/** Resolves the destination for a request to the main gallery archive. */
	public static function archive_target(): string {
		if ( ! is_post_type_archive( PostType::POST_TYPE ) || is_paged() || is_feed() || is_search() || is_embed() || is_preview() ) {
			return '';
		}

		$id = (int) Settings::get( 'gallery_directory_page' );
		return $id && self::is_directory_page( get_post( $id ) ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Renders a directory or a homepage with featured gallery and previews.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 */
	public function render( $attributes = [] ): string {
		$settings  = shortcode_atts(
			[
				'layout'    => 'directory',
				'directory' => 0,
				'featured'  => 0,
			],
			$attributes,
			self::SHORTCODE
		);
		$homepage  = 'home' === $settings['layout'];
		$explicit  = absint( $settings['directory'] );
		$page_id   = $explicit ? $explicit : (int) Settings::get( 'gallery_directory_page' );
		$directory = $page_id ? get_post( $page_id ) : null;
		$url       = $directory && 'page' === $directory->post_type && 'publish' === $directory->post_status && '' === $directory->post_password
			&& ( $explicit || self::is_directory_page( $directory ) )
			? get_permalink( $directory )
			: '';
		$galleries = get_posts(
			[
				'post_type'              => PostType::POST_TYPE,
				'post_parent'            => 0,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'has_password'           => false,
				'orderby'                => [
					'menu_order' => 'ASC',
					'date'       => 'DESC',
					'ID'         => 'DESC',
				],
				'update_post_term_cache' => false,
			]
		);
		$galleries = array_values(
			array_filter(
				$galleries,
				fn( WP_Post $gallery ): bool => ! $this->access->is_protected( $gallery ) && $this->access->can_view( $gallery )
			)
		);

		$feature = null;
		if ( $homepage ) {
			$featured  = absint( $settings['featured'] );
			$mapped    = array_column( $galleries, null, 'ID' );
			$feature   = $mapped[ $featured ] ?? $galleries[0] ?? null;
			$galleries = array_slice( $galleries, 0, 6 );
		}

		return $this->templates->render(
			'gallery-directory.php',
			[
				'cards'     => array_map( fn( WP_Post $gallery ): array => $this->card( $gallery ), $galleries ),
				'feature'   => $feature ? $this->card( $feature ) : null,
				'homepage'  => $homepage,
				'directory' => $url,
				'cart'      => ShopPage::page_id() ? ShopPage::url() : '',
				'section'   => wp_unique_id( 'slashphoto-collections-' ),
			]
		);
	}

	/**
	 * Takes cover images only from the public gallery itself.
	 *
	 * @param WP_Post $gallery Public gallery.
	 * @return array{url: string, title: string, image: int}
	 */
	private function card( WP_Post $gallery ): array {
		$cover_id = get_post_thumbnail_id( $gallery );
		$cover    = $cover_id ? get_post( $cover_id ) : null;
		if ( ! $cover || $gallery->ID !== (int) $cover->post_parent || 'inherit' !== $cover->post_status || ! wp_attachment_is_image( $cover ) ) {
			$photos = $this->galleries->images( $gallery->ID, 1 );
			$cover  = $photos[0] ?? null;
		}

		return [
			'url'   => (string) get_permalink( $gallery ),
			'title' => get_the_title( $gallery ),
			'image' => $cover ? $cover->ID : 0,
		];
	}
}
