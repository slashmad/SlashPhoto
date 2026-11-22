<?php
/**
 * Shop rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what is for sale to the current visitor and prices a cart.
 *
 * The cart itself lives in the visitor's browser. Every quote and order is
 * built again here from image, product and print option IDs: prices, names and
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
		if ( PostType::POST_TYPE !== $gallery->post_type || ! $this->access->can_view( $gallery ) || null !== $this->terms->needs_step( $gallery ) ) {
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
		$offer    = [];
		foreach ( PriceLists::products( $list ) as $product ) {
			$entry = [
				'id'    => $product['id'],
				'kind'  => $product['kind'],
				'name'  => $product['name'],
				'price' => Money::format( $product['price'], $currency ),
			];
			if ( 'package' === $product['kind'] ) {
				$includes          = PriceLists::product( $list, $product['includes'] );
				$entry['count']    = $product['count'];
				$entry['includes'] = $includes ? $includes['name'] : '';
				$entry['gallery']  = $gallery->ID;
			}
			if ( ! empty( $product['variants'] ) ) {
				$entry['variants'] = array_values(
					array_map(
						static fn( array $variant ): array => [
							'id'    => $variant['id'],
							'label' => PriceLists::variant_label( $variant ),
							'price' => Money::format( $variant['price'], $currency ),
						],
						$product['variants']
					)
				);
				$entry['price']    = $entry['variants'][0]['price'];
			}
			$offer[] = $entry;
		}//end foreach
		return $offer;
	}

	/**
	 * Prices a cart.
	 *
	 * Items are either single products, { image, product, qty, variant? }, or package
	 * instances, { package: <client ID>, product, images: [ … ], gallery }.
	 * A package of a whole gallery ("count" 0) needs only the gallery.
	 *
	 * @param array  $items  Raw items.
	 * @param string $coupon Discount code, if any.
	 * @param string $email  Customer's email, for per-customer code limits.
	 * @return array{lines: array, unavailable: array, currency: string, subtotal: int, discount: int, coupon: string, coupon_error: string, shipping: int, tax: int, total: int, tax_rate: float, prices_include_tax: bool, needs_shipping: bool}
	 */
	public function quote( array $items, string $coupon = '', string $email = '' ): array {
		$currency = (string) Settings::get( 'currency' );
		$merged   = [];
		$packages = [];
		foreach ( array_slice( $items, 0, self::MAX_LINES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product = (string) ( $item['product'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9]{8}$/', $product ) ) {
				continue;
			}
			if ( isset( $item['package'] ) ) {
				$uid = (string) $item['package'];
				if ( preg_match( '/^[a-z0-9]{1,20}$/', $uid ) && ! isset( $packages[ $uid ] ) ) {
					$packages[ $uid ] = [
						'product' => $product,
						'gallery' => (int) ( $item['gallery'] ?? 0 ),
						'images'  => array_slice( array_values( array_unique( array_map( 'intval', (array) ( $item['images'] ?? [] ) ) ) ), 0, PriceLists::MAX_PACKAGE ),
					];
				}
				continue;
			}
			$image   = (int) ( $item['image'] ?? 0 );
			$variant = (string) ( $item['variant'] ?? '' );
			$key     = $image . ':' . $product . ( '' === $variant ? '' : ':' . $variant );
			if ( $image <= 0 ) {
				continue;
			}
			$merged[ $key ] = [
				'image'   => $image,
				'product' => $product,
				'variant' => $variant,
				'qty'     => ( $merged[ $key ]['qty'] ?? 0 ) + max( 1, (int) ( $item['qty'] ?? 1 ) ),
			];
		}//end foreach

		$lines       = [];
		$unavailable = [];
		foreach ( $merged as $key => $item ) {
			$line = $this->line( $key, $item );
			if ( $line ) {
				$lines[] = $line;
			} else {
				$unavailable[] = $key;
			}
		}
		foreach ( $packages as $uid => $package ) {
			$line = $this->package_line( (string) $uid, $package['product'], $package['gallery'], $package['images'] );
			if ( $line ) {
				$lines[] = $line;
			} else {
				$unavailable[] = 'pkg:' . $uid;
			}
		}

		$subtotal = 0;
		$prints   = false;
		foreach ( $lines as $line ) {
			$subtotal += $line['total'];
			$prints    = $prints || 'print' === ( $line['includes']['kind'] ?? $line['kind'] );
		}

		$applied = [
			'code'          => '',
			'discount'      => 0,
			'free_shipping' => false,
			'error'         => '',
		];
		if ( '' !== trim( $coupon ) && $lines ) {
			$applied = Coupons::apply( $coupon, $lines, $subtotal, $email );
		}
		$discount = min( $subtotal, $applied['discount'] );

		$shipping = 0;
		if ( $prints && ! $applied['free_shipping'] ) {
			$free     = (int) Settings::get( 'free_shipping_over' );
			$shipping = $free > 0 && $subtotal - $discount >= $free ? 0 : (int) Settings::get( 'shipping_flat' );
		}
		$rate     = (float) Settings::get( 'tax_rate' );
		$included = (bool) Settings::get( 'prices_include_tax' );
		$taxable  = $subtotal - $discount + $shipping;
		$tax      = Money::tax( $taxable, $rate, $included );

		return [
			'lines'              => $lines,
			'unavailable'        => $unavailable,
			'currency'           => $currency,
			'subtotal'           => $subtotal,
			'discount'           => $discount,
			'coupon'             => $applied['code'],
			'coupon_error'       => $applied['error'],
			'shipping'           => $shipping,
			'tax'                => $tax,
			'total'              => $taxable + ( $included ? 0 : $tax ),
			'tax_rate'           => $rate,
			'prices_include_tax' => $included,
			'needs_shipping'     => $prints,
		];
	}

	/**
	 * Prices a package, or returns null when it is not available.
	 *
	 * Every photo must be visible to the visitor and sold through the same
	 * price list as the package. Extra photos beyond the package size are
	 * ignored; fewer are allowed (the customer is told how many remain).
	 *
	 * @param string $uid        Client's package ID.
	 * @param string $product_id Package product.
	 * @param int    $gallery_id Gallery the package was chosen in.
	 * @param int[]  $image_ids  Chosen photos.
	 */
	private function package_line( string $uid, string $product_id, int $gallery_id, array $image_ids ): ?array {
		$gallery = $gallery_id ? get_post( $gallery_id ) : null;
		if ( ! $gallery && $image_ids ) {
			$first   = get_post( $image_ids[0] );
			$gallery = $first && $first->post_parent ? get_post( $first->post_parent ) : null;
		}
		if ( ! $gallery || 'publish' !== $gallery->post_status ) {
			return null;
		}
		$list    = $this->price_list( $gallery );
		$product = $list ? PriceLists::product( $list, $product_id ) : null;
		if ( ! $product || 'package' !== $product['kind'] ) {
			return null;
		}
		$includes = PriceLists::product( $list, $product['includes'] );
		if ( ! $includes ) {
			return null;
		}

		if ( 0 === $product['count'] ) {
			// The whole gallery: every photo in it, whatever the client sent.
			$image_ids = array_map( static fn( WP_Post $image ): int => $image->ID, ( new GalleryRepository() )->images( $gallery->ID ) );
		}
		$images = [];
		foreach ( $image_ids as $image_id ) {
			$image  = get_post( $image_id );
			$parent = $image && 'attachment' === $image->post_type && wp_attachment_is_image( $image ) && $image->post_parent ? get_post( $image->post_parent ) : null;
			if ( ! $parent || 'publish' !== $parent->post_status || $this->price_list( $parent ) !== $list ) {
				continue;
			}
			$thumb    = wp_get_attachment_image_src( $image_id, 'thumbnail' );
			$images[] = [
				'id'      => $image_id,
				'gallery' => $parent->ID,
				'title'   => get_the_title( $image ),
				'file'    => Proofing::file_label( $image_id ),
				'thumb'   => $thumb ? $thumb[0] : '',
			];
			if ( $product['count'] && count( $images ) >= $product['count'] ) {
				break;
			}
		}
		if ( ! $images ) {
			return null;
		}

		return [
			'key'      => 'pkg:' . $uid,
			'package'  => $uid,
			'image'    => 0,
			'gallery'  => $gallery->ID,
			'product'  => $product_id,
			'kind'     => 'package',
			'name'     => $product['name'],
			'title'    => get_the_title( $gallery ),
			'file'     => '',
			'thumb'    => $images[0]['thumb'],
			'qty'      => 1,
			'unit'     => $product['price'],
			'total'    => $product['price'],
			'count'    => $product['count'] ? $product['count'] : count( $images ),
			'images'   => $images,
			'includes' => [
				'id'   => $includes['id'],
				'kind' => $includes['kind'],
				'name' => $includes['name'],
				'size' => 'download' === $includes['kind'] ? (int) ( $includes['size'] ?? 0 ) : 0,
			],
		];
	}

	/**
	 * Prices one cart line, or returns null when it is not available.
	 *
	 * @param string $key  Cart line key.
	 * @param array  $item Image, product, option and quantity.
	 */
	private function line( string $key, array $item ): ?array {
		$image_id = $item['image'];
		$image    = get_post( $image_id );
		if ( ! $image || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image ) || ! $image->post_parent ) {
			return null;
		}
		$gallery = get_post( $image->post_parent );
		if ( ! $gallery || 'publish' !== $gallery->post_status ) {
			return null;
		}
		$list    = $this->price_list( $gallery );
		$product = $list ? PriceLists::product( $list, $item['product'] ) : null;
		if ( ! $product || 'package' === $product['kind'] ) {
			return null;
		}
		$name    = $product['name'];
		$unit    = $product['price'];
		$variant = null;
		if ( ! empty( $product['variants'] ) || '' !== $item['variant'] ) {
			$variant = $product['variants'][ $item['variant'] ] ?? null;
			if ( ! $variant ) {
				return null;
			}
			$label = PriceLists::variant_label( $variant );
			// The persisted order item name is limited to 200 characters.
			$name = mb_substr( $name, 0, max( 0, 197 - mb_strlen( $label ) ) ) . ' — ' . $label;
			$unit = $variant['price'];
		}
		// A download is delivered once, whatever the quantity.
		$qty   = 'download' === $product['kind'] ? 1 : min( self::MAX_QTY, max( 1, $item['qty'] ) );
		$thumb = wp_get_attachment_image_src( $image_id, 'thumbnail' );
		return [
			'key'           => $key,
			'image'         => $image_id,
			'gallery'       => $gallery->ID,
			'product'       => $item['product'],
			'kind'          => $product['kind'],
			'name'          => $name,
			'title'         => get_the_title( $image ),
			'file'          => Proofing::file_label( $image_id ),
			'thumb'         => $thumb ? $thumb[0] : '',
			'qty'           => $qty,
			'unit'          => $unit,
			'total'         => $unit * $qty,
			'print_product' => 'print' === $product['kind'] ? $product['name'] : '',
			'print_paper'   => $variant['paper'] ?? '',
			'print_frame'   => $variant['frame'] ?? '',
			'download_size' => 'download' === $product['kind'] ? (int) ( $product['size'] ?? 0 ) : 0,
		];
	}
}
