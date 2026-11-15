<?php
/**
 * Shop rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what is for sale to the current visitor and prices a cart.
 *
 * The cart itself lives in the visitor's browser. Every quote and order is
 * built again here from image IDs and product IDs only: prices, names and
 * shipping come from the price lists, and each image must be in a gallery
 * the visitor can see right now (passwords, client lists, links, expiry,
 * terms). An image the visitor cannot see is reported as unavailable,
 * exactly like one that does not exist.
 */
final class Shop {

	public const MAX_LINES = 200;
	public const MAX_QTY   = 99;

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
	 * @param Access $access Access rules.
	 * @param Terms  $terms  Terms gate.
	 */
	public function __construct( Access $access, Terms $terms ) {
		$this->access = $access;
		$this->terms  = $terms;
	}

	/**
	 * Access rules.
	 */
	public function access(): Access {
		return $this->access;
	}

	/**
	 * The price list for images in this gallery, or 0 when they are not
	 * for sale to the current visitor.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function price_list( WP_Post $gallery ): int {
		if ( PostType::POST_TYPE !== $gallery->post_type || ! $this->access->can_view( $gallery ) || $this->terms->needs_acceptance( $gallery ) ) {
			return 0;
		}
		return PriceLists::for_gallery( $gallery, $this->access );
	}

	/**
	 * Products offered for a gallery, prepared for the front end.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @return array<int, array{id: string, kind: string, name: string, price: string}>
	 */
	public function offer( WP_Post $gallery ): array {
		$list = $this->price_list( $gallery );
		if ( ! $list ) {
			return [];
		}
		$currency = (string) Settings::get( 'currency' );
		return array_map(
			static fn( array $product ): array => [
				'id'    => $product['id'],
				'kind'  => $product['kind'],
				'name'  => $product['name'],
				'price' => Money::format( $product['price'], $currency ),
			],
			PriceLists::products( $list )
		);
	}

	/**
	 * Prices a cart.
	 *
	 * @param array $items Raw items: [ { image, product, qty } ].
	 * @return array{lines: array, unavailable: array, currency: string, subtotal: int, shipping: int, tax: int, total: int, tax_rate: float, prices_include_tax: bool, needs_shipping: bool}
	 */
	public function quote( array $items ): array {
		$currency = (string) Settings::get( 'currency' );
		$merged   = [];
		foreach ( array_slice( $items, 0, self::MAX_LINES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$image   = (int) ( $item['image'] ?? 0 );
			$product = (string) ( $item['product'] ?? '' );
			$key     = $image . ':' . $product;
			if ( $image <= 0 || ! preg_match( '/^[a-f0-9]{8}$/', $product ) ) {
				continue;
			}
			$merged[ $key ] = [
				'image'   => $image,
				'product' => $product,
				'qty'     => ( $merged[ $key ]['qty'] ?? 0 ) + max( 1, (int) ( $item['qty'] ?? 1 ) ),
			];
		}

		$lines       = [];
		$unavailable = [];
		$subtotal    = 0;
		$prints      = false;
		foreach ( $merged as $key => $item ) {
			$line = $this->line( $item['image'], $item['product'], $item['qty'] );
			if ( ! $line ) {
				$unavailable[] = $key;
				continue;
			}
			$lines[]   = $line;
			$subtotal += $line['total'];
			$prints    = $prints || 'print' === $line['kind'];
		}

		$shipping = 0;
		if ( $prints ) {
			$free     = (int) Settings::get( 'free_shipping_over' );
			$shipping = $free > 0 && $subtotal >= $free ? 0 : (int) Settings::get( 'shipping_flat' );
		}
		$rate     = (float) Settings::get( 'tax_rate' );
		$included = (bool) Settings::get( 'prices_include_tax' );
		$tax      = Money::tax( $subtotal + $shipping, $rate, $included );

		return [
			'lines'              => $lines,
			'unavailable'        => $unavailable,
			'currency'           => $currency,
			'subtotal'           => $subtotal,
			'shipping'           => $shipping,
			'tax'                => $tax,
			'total'              => $subtotal + $shipping + ( $included ? 0 : $tax ),
			'tax_rate'           => $rate,
			'prices_include_tax' => $included,
			'needs_shipping'     => $prints,
		];
	}

	/**
	 * Prices one cart line, or returns null when it is not available.
	 *
	 * @param int    $image_id   Image.
	 * @param string $product_id Product.
	 * @param int    $qty        Quantity.
	 */
	private function line( int $image_id, string $product_id, int $qty ): ?array {
		$image = get_post( $image_id );
		if ( ! $image || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image ) || ! $image->post_parent ) {
			return null;
		}
		$gallery = get_post( $image->post_parent );
		if ( ! $gallery || 'publish' !== $gallery->post_status ) {
			return null;
		}
		$list    = $this->price_list( $gallery );
		$product = $list ? PriceLists::product( $list, $product_id ) : null;
		if ( ! $product ) {
			return null;
		}
		// A download is delivered once, whatever the quantity.
		$qty   = 'download' === $product['kind'] ? 1 : min( self::MAX_QTY, max( 1, $qty ) );
		$thumb = wp_get_attachment_image_src( $image_id, 'thumbnail' );
		return [
			'key'     => $image_id . ':' . $product_id,
			'image'   => $image_id,
			'gallery' => $gallery->ID,
			'product' => $product_id,
			'kind'    => $product['kind'],
			'name'    => $product['name'],
			'title'   => get_the_title( $image ),
			'file'    => Proofing::file_label( $image_id ),
			'thumb'   => $thumb ? $thumb[0] : '',
			'qty'     => $qty,
			'unit'    => $product['price'],
			'total'   => $product['price'] * $qty,
		];
	}
}
