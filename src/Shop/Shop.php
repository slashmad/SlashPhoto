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
		$products = array_column( PriceLists::products( $list ), null, 'id' );
		foreach ( $products as $product ) {
			$entry   = [
				'id'    => $product['id'],
				'kind'  => $product['kind'],
				'name'  => $product['name'],
				'price' => Money::format( $product['price'], $currency ),
			];
			$licence = 'download' === $product['kind'] ? Licences::get( (int) ( $product['licence'] ?? 0 ) ) : null;
			if ( $licence ) {
				$entry['licence'] = $licence['name'];
			}
			if ( 'print' === $product['kind'] ) {
				$entry['format'] = PrintCrop::format( $product['name'] );
			}
			if ( 'package' === $product['kind'] ) {
				$entry['count']    = $product['count'];
				$entry['includes'] = implode( ', ', array_column( array_intersect_key( $products, array_flip( $product['includes'] ) ), 'name' ) );
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
	 * @param array  $items   Raw items.
	 * @param string $coupon  Discount code, if any.
	 * @param string $email   Customer's email, for per-customer code limits.
	 * Gift cards are { giftcard: <amount in minor units>, qty }.
	 *
	 * @param string $country Customer's country (delivery country for prints), for VAT by country.
	 * @param string $gift    Gift card code to pay with, if any.
	 * @param string $delivery Selected print delivery method, or the default.
	 * @return array{lines: array, unavailable: array, currency: string, subtotal: int, discount: int, coupon: string, coupon_error: string, shipping: int, tax: int, total: int, tax_rate: float, tax_country: string, tax_by_country: bool, prices_include_tax: bool, needs_shipping: bool, gift_card: array{id: int, hint: string, amount: int, error: string}, due: int}
	 */
	public function quote( array $items, string $coupon = '', string $email = '', string $country = '', string $gift = '', string $delivery = '' ): array {
		$currency = (string) Settings::get( 'currency' );
		$merged   = [];
		$packages = [];
		$cards    = [];
		foreach ( array_slice( $items, 0, self::MAX_LINES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['giftcard'] ) ) {
				$amount = (int) $item['giftcard'];
				if ( $amount > 0 ) {
					$cards[ $amount ] = min( self::MAX_QTY, ( $cards[ $amount ] ?? 0 ) + max( 1, (int) ( $item['qty'] ?? 1 ) ) );
				}
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
			$crop    = isset( $item['crop'] ) ? (int) $item['crop'] : null;
			$view    = (string) ( $item['view'] ?? '' );
			if ( null !== $crop ) {
				$key .= ':crop-' . $crop;
			}
			if ( '' !== $view ) {
				$key .= ':view-' . $view;
			}
			if ( $image <= 0 ) {
				continue;
			}
			$merged[ $key ] = [
				'image'   => $image,
				'product' => $product,
				'variant' => $variant,
				'crop'    => $crop,
				'view'    => $view,
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
			$prints    = $prints || in_array( 'print', self::line_kinds( $line ), true );
		}
		$goods = $lines;

		// Licences the customer must accept, once each.
		$licences = [];
		foreach ( $lines as $line ) {
			foreach ( $line['includes'] ?? [ $line ] as $component ) {
				$licence = $component['licence'] ?? null;
				if ( $licence ) {
					$licences[ $licence['id'] ] = $licence;
				}
			}
		}

		// Gift cards: vouchers without VAT, shipping or discounts.
		$vouchers = 0;
		foreach ( $cards as $amount => $qty ) {
			if ( ! GiftCards::enabled() || ! in_array( $amount, GiftCards::amounts(), true ) ) {
				$unavailable[] = 'gc:' . $amount;
				continue;
			}
			$lines[]   = [
				'key'     => 'gc:' . $amount,
				'image'   => 0,
				'gallery' => 0,
				'product' => GiftCards::KIND,
				'kind'    => GiftCards::KIND,
				'name'    => GiftCards::name( $amount, $currency ),
				'title'   => '',
				'file'    => '',
				'thumb'   => '',
				'qty'     => $qty,
				'unit'    => $amount,
				'total'   => $amount * $qty,
				'amount'  => $amount,
			];
			$vouchers += $amount * $qty;
		}//end foreach

		$applied = [
			'code'          => '',
			'discount'      => 0,
			'free_shipping' => false,
			'error'         => '',
		];
		if ( '' !== trim( $coupon ) && $goods ) {
			$applied = Coupons::apply( $coupon, $goods, $subtotal, $email );
		}
		$discount = min( $subtotal, $applied['discount'] );

		$method   = $prints ? Delivery::quote( $delivery, $subtotal - $discount, $applied['free_shipping'] ) : [
			'id'           => '',
			'label'        => '',
			'instructions' => '',
			'price'        => 0,
		];
		$shipping = $method['price'];
		if ( 'pickup' === $method['id'] ) {
			$country = (string) Settings::get( 'pickup_country' );
		}
		[ $rate, $tax_country ] = self::tax_rate( $country );

		$included = (bool) Settings::get( 'prices_include_tax' );
		$taxable  = $subtotal - $discount + $shipping;
		$tax      = Money::tax( $taxable, $rate, $included );
		$total    = $taxable + ( $included ? 0 : $tax ) + $vouchers;

		$card = [
			'id'     => 0,
			'hint'   => '',
			'amount' => 0,
			'error'  => '',
		];
		if ( '' !== trim( $gift ) && $lines ) {
			$found         = $vouchers ? null : GiftCards::find( $gift );
			$card['error'] = $vouchers ? __( 'Gift cards cannot be paid for with a gift card.', 'slashphoto' ) : GiftCards::problem( $found, $currency );
			if ( '' === $card['error'] && $found ) {
				$card['id']     = $found['id'];
				$card['hint']   = $found['hint'];
				$card['amount'] = min( $found['balance'], $total );
			}
		}

		return [
			'lines'              => $lines,
			'unavailable'        => $unavailable,
			'currency'           => $currency,
			'subtotal'           => $subtotal + $vouchers,
			'discount'           => $discount,
			'coupon'             => $applied['code'],
			'coupon_error'       => $applied['error'],
			'shipping'           => $shipping,
			'delivery'           => $method,
			'tax'                => $tax,
			'total'              => $total,
			'gift_card'          => $card,
			'licences'           => array_values( $licences ),
			'due'                => $total - $card['amount'],
			'tax_rate'           => $rate,
			// Only recorded when VAT depends on the country.
			'tax_country'        => Settings::get( 'tax_rates' ) ? $tax_country : '',
			'tax_by_country'     => (bool) Settings::get( 'tax_rates' ),
			'prices_include_tax' => $included,
			'needs_shipping'     => $prints,
		];
	}

	/**
	 * The VAT rate for a customer's country: its own rate when one is set
	 * under VAT by country, otherwise the general rate.
	 *
	 * @param string $country Country code, or '' when not known yet.
	 * @return array{0: float, 1: string} Rate and the normalised country code ('' when unknown).
	 */
	public static function tax_rate( string $country ): array {
		$country = strtoupper( trim( $country ) );
		$country = in_array( $country, Countries::all(), true ) ? $country : '';
		$rates   = (array) Settings::get( 'tax_rates' );
		return [ (float) ( '' !== $country && isset( $rates[ $country ] ) ? $rates[ $country ] : Settings::get( 'tax_rate' ) ), $country ];
	}

	/**
	 * Product kinds represented by a cart line.
	 *
	 * @param array $line Cart line.
	 * @return string[]
	 */
	public static function line_kinds( array $line ): array {
		return array_values( array_unique( array_column( $line['includes'] ?? [ $line ], 'kind' ) ) );
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
		$list     = $this->price_list( $gallery );
		$products = $list ? array_column( PriceLists::products( $list ), null, 'id' ) : [];
		$product  = $products[ $product_id ] ?? null;
		if ( ! $product || 'package' !== $product['kind'] ) {
			return null;
		}
		$includes = array_map( static fn( string $id ): array => $products[ $id ], $product['includes'] );

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
			'includes' => array_map(
				static fn( array $component ): array => [
					'id'      => $component['id'],
					'kind'    => $component['kind'],
					'name'    => $component['name'],
					'size'    => 'download' === $component['kind'] ? (int) ( $component['size'] ?? 0 ) : 0,
					'licence' => 'download' === $component['kind'] ? Licences::get( (int) ( $component['licence'] ?? 0 ) ) : null,
				],
				$includes
			),
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
		$crop = '';
		$view = '';
		if ( '' !== $item['view'] ) {
			$view = 'print' === $product['kind'] ? PanoramaPrint::snapshot( $image_id, $product['name'], $item['view'] ) : '';
			if ( '' === $view || null !== $item['crop'] ) {
				return null;
			}
		}
		if ( null !== $item['crop'] ) {
			$crop = 'print' === $product['kind'] ? PrintCrop::rectangle( $image_id, PrintCrop::format( $product['name'] ), $item['crop'] ) : null;
			if ( null === $crop ) {
				return null;
			}
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
			'print_crop'    => $crop,
			'print_view'    => $view,
			'download_size' => 'download' === $product['kind'] ? (int) ( $product['size'] ?? 0 ) : 0,
			'licence'       => 'download' === $product['kind'] ? Licences::get( (int) ( $product['licence'] ?? 0 ) ) : null,
		];
	}
}
