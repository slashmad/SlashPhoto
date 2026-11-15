<?php
/**
 * Renders a gallery for the page content or the gallery block.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Media\Panorama;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that turns a gallery into markup, so the automatic page
 * output and the block apply exactly the same access rules.
 */
final class GalleryRenderer {

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Gallery queries.
	 *
	 * @var GalleryRepository
	 */
	private GalleryRepository $galleries;

	/**
	 * Panorama detection.
	 *
	 * @var Panorama
	 */
	private Panorama $panorama;

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Terms gate.
	 *
	 * @var Terms
	 */
	private Terms $terms;

	/**
	 * Constructor.
	 *
	 * @param TemplateLoader    $templates Template loader.
	 * @param GalleryRepository $galleries Gallery queries.
	 * @param Panorama          $panorama  Panorama detection.
	 * @param Access            $access    Access rules.
	 * @param Terms             $terms     Terms gate.
	 */
	public function __construct( TemplateLoader $templates, GalleryRepository $galleries, Panorama $panorama, Access $access, Terms $terms ) {
		$this->templates = $templates;
		$this->galleries = $galleries;
		$this->panorama  = $panorama;
		$this->access    = $access;
		$this->terms     = $terms;
	}

	/**
	 * Returns the gallery markup, the terms form, or an empty string when the
	 * visitor may not see the gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @param string  $align   "wide", "full" or "".
	 */
	public function render( WP_Post $gallery, string $align = '' ): string {
		if ( ! $this->access->can_view( $gallery ) ) {
			return '';
		}

		if ( $this->terms->needs_acceptance( $gallery ) ) {
			$source = Terms::source( $this->access, $gallery );
			return $this->templates->render(
				'terms.php',
				[
					'gallery' => $gallery,
					'terms'   => $source ? Terms::text( $source ) : '',
					'align'   => $align,
					'action'  => admin_url( 'admin-post.php' ),
					'nonce'   => wp_create_nonce( Terms::ACTION . '_' . $gallery->ID ),
				]
			);
		}

		$children = [];
		$locked   = [];
		foreach ( $this->galleries->children( $gallery->ID ) as $child ) {
			if ( $this->access->hidden_from_listings( $child ) ) {
				continue;
			}
			$children[] = $child;
			if ( ! $this->access->can_view( $child ) ) {
				$locked[] = $child->ID;
			}
		}

		return $this->templates->render(
			'gallery.php',
			[
				'gallery'         => $gallery,
				'children'        => $children,
				'locked_children' => $locked,
				'items'           => $this->items( $gallery->ID ),
				'align'           => $align,
			]
		);
	}

	/**
	 * Builds the image list for the gallery template.
	 *
	 * @param int $gallery_id Gallery post ID.
	 * @return array<int, array{attachment: WP_Post, kind: string, panorama: array}>
	 */
	public function items( int $gallery_id ): array {
		$items = [];
		foreach ( $this->galleries->images( $gallery_id ) as $attachment ) {
			$kind    = $this->panorama->kind( $attachment->ID );
			$items[] = [
				'attachment' => $attachment,
				'kind'       => $kind,
				'panorama'   => '360' === $kind ? $this->panorama->pano_data( $attachment->ID ) : [],
			];
		}
		return $items;
	}
}
