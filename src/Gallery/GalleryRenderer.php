<?php
/**
 * Renders a gallery for the page content or the gallery block.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\EmailGate;
use SlashPhoto\Access\Terms;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Media\Panorama;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Revisions;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Shop;
use SlashPhoto\Visitors\Stats;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that turns a gallery into markup, so the automatic page
 * output and the block apply exactly the same access rules.
 */
final class GalleryRenderer {

	public const LAYOUT           = '_slashphoto_gallery_layout';
	private const IMAGES_PER_PAGE = 48;
	private const SEARCH          = 'sp_photo_search';
	private const PAGE            = 'sp_photo_page';

	/** Styles offered in the photographer's gallery workspace. */
	public static function layouts(): array {
		return [
			'auto'   => __( 'Automatic', 'slashphoto' ),
			'square' => __( 'Square grid', 'slashphoto' ),
			'full'   => __( 'One photo per row', 'slashphoto' ),
		];
	}

	/**
	 * The gallery's chosen layout, with the original grid as the default.
	 *
	 * @param int $gallery_id Gallery.
	 */
	public static function layout( int $gallery_id ): string {
		$layout = get_post_meta( $gallery_id, self::LAYOUT, true );
		return is_string( $layout ) && isset( self::layouts()[ $layout ] ) ? $layout : 'auto';
	}

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
	 * Proofing rules.
	 *
	 * @var Proofing
	 */
	private Proofing $proofing;

	/**
	 * Shop rules.
	 *
	 * @var Shop
	 */
	private Shop $shop;

	/**
	 * Constructor.
	 *
	 * @param TemplateLoader    $templates Template loader.
	 * @param GalleryRepository $galleries Gallery queries.
	 * @param Panorama          $panorama  Panorama detection.
	 * @param Access            $access    Access rules.
	 * @param Terms             $terms     Terms gate.
	 * @param Proofing          $proofing  Proofing rules.
	 * @param Shop              $shop      Shop rules.
	 * @param Revisions|null    $revisions Private versions of selected photos.
	 */
	public function __construct( TemplateLoader $templates, GalleryRepository $galleries, Panorama $panorama, Access $access, Terms $terms, Proofing $proofing, Shop $shop, private ?Revisions $revisions = null ) {
		$this->templates = $templates;
		$this->galleries = $galleries;
		$this->panorama  = $panorama;
		$this->access    = $access;
		$this->terms     = $terms;
		$this->proofing  = $proofing;
		$this->shop      = $shop;
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

		$step = $this->terms->needs_step( $gallery );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'email' === $step ) {
			return $this->templates->render(
				'email-gate.php',
				[
					'gallery' => $gallery,
					'align'   => $align,
					'action'  => admin_url( 'admin-post.php' ),
					'nonce'   => wp_create_nonce( EmailGate::ACTION . '_' . $gallery->ID ),
					'invalid' => isset( $_GET['slashphoto_email'] ),
					'days'    => (int) Settings::get( 'visitor_retention_days' ),
				]
			);
		}
		if ( 'terms' === $step ) {
			$source = Terms::source( $this->access, $gallery );
			return $this->templates->render(
				'terms.php',
				[
					'gallery'   => $gallery,
					'terms'     => $source ? Terms::text( $source ) : '',
					'align'     => $align,
					'action'    => admin_url( 'admin-post.php' ),
					'nonce'     => wp_create_nonce( Terms::ACTION . '_' . $gallery->ID ),
					'ask_email' => $this->terms->asks_email( $gallery ),
					'invalid'   => isset( $_GET['slashphoto_terms'] ),
				]
			);
		}
		// phpcs:enable

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

		$browse = [];
		$photos = null;
		if ( is_singular( PostType::POST_TYPE ) && get_queried_object_id() === $gallery->ID && ! is_feed() && ! is_embed() && ! wp_is_json_request() ) {
			[ $photos, $browse ] = $this->browse( $gallery->ID );
		}
		$items = $this->items( $gallery->ID, $photos );
		$root  = $items ? $this->proofing->root( $gallery ) : null;
		if ( $root ) {
			wp_enqueue_script_module( Assets::PROOFING_MODULE );
		}
		if ( Stats::enabled() && ! $this->access->is_editor( $gallery ) ) {
			wp_enqueue_script_module( Stats::MODULE );
		}
		$offer = $items ? $this->shop->offer( $gallery ) : [];
		if ( $offer ) {
			wp_enqueue_script_module( Assets::SHOP_MODULE );
		}

		return $this->templates->render(
			'gallery.php',
			[
				'gallery'         => $gallery,
				'children'        => $children,
				'locked_children' => $locked,
				'items'           => $items,
				'align'           => $align,
				'proofing'        => $root ? $root->ID : 0,
				'shop'            => $offer,
				'browse'          => $browse,
				'layout'          => self::layout( $gallery->ID ),
				'revisions'       => $this->revisions ? $this->revisions->urls( $gallery, array_column( array_column( $items, 'attachment' ), 'ID' ) ) : [],
			]
		);
	}

	/**
	 * Builds the image list for the gallery template.
	 *
	 * @param int            $gallery_id Gallery post ID.
	 * @param WP_Post[]|null $attachments Photos to render, or null for all.
	 * @return array<int, array{attachment: WP_Post, kind: string, panorama: array}>
	 */
	public function items( int $gallery_id, ?array $attachments = null ): array {
		$items = [];
		foreach ( $attachments ?? $this->galleries->images( $gallery_id ) as $attachment ) {
			$kind    = $this->panorama->kind( $attachment->ID );
			$items[] = [
				'attachment' => $attachment,
				'kind'       => $kind,
				'panorama'   => '360' === $kind ? $this->panorama->pano_data( $attachment->ID ) : [],
			];
		}
		return $items;
	}

	/**
	 * Returns the current gallery page and navigation after access checks.
	 *
	 * @param int $gallery_id Gallery.
	 * @return array{WP_Post[], array}
	 */
	private function browse( int $gallery_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- these parameters only change the rendered photo list.
		$search = isset( $_GET[ self::SEARCH ] ) && is_string( $_GET[ self::SEARCH ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET[ self::SEARCH ] ) ), 0, 120 ) : '';
		$page   = isset( $_GET[ self::PAGE ] ) && is_scalar( $_GET[ self::PAGE ] ) ? max( 1, min( 1000000, absint( $_GET[ self::PAGE ] ) ) ) : 1;
		// phpcs:enable
		$photos = $this->galleries->image_page( $gallery_id, self::IMAGES_PER_PAGE + 1, ( $page - 1 ) * self::IMAGES_PER_PAGE, $search );
		$next   = count( $photos ) > self::IMAGES_PER_PAGE;
		if ( $next ) {
			array_pop( $photos );
		}
		$permalink = (string) get_permalink( $gallery_id );
		$url       = '' !== $search ? add_query_arg( self::SEARCH, rawurlencode( $search ), $permalink ) : $permalink;
		parse_str( (string) wp_parse_url( $permalink, PHP_URL_QUERY ), $params );
		return [
			$photos,
			[
				'url'      => $permalink,
				'params'   => array_filter( $params, 'is_scalar' ),
				'field'    => self::SEARCH,
				'search'   => $search,
				'page'     => $page,
				'previous' => $page > 1 ? ( 2 === $page ? $url : add_query_arg( self::PAGE, $page - 1, $url ) ) : '',
				'next'     => $next ? add_query_arg( self::PAGE, $page + 1, $url ) : '',
			],
		];
	}
}
