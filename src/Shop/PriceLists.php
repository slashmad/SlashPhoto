<?php
/**
 * Price lists.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A price list is a private post with a list of products: downloads of the
 * original and prints in different sizes. A gallery uses the price list set
 * on it or the nearest parent gallery, and top-level galleries fall back to
 * the default price list in the settings. "Not for sale" stops inheritance.
 */
final class PriceLists {

	public const POST_TYPE    = 'slashphoto_pricelist';
	public const META         = '_slashphoto_products';
	public const GALLERY_META = '_slashphoto_price_list';
	public const NONE         = 'none';
	public const KINDS        = [ 'download', 'print' ];
	public const MAX_PRODUCTS = 100;
	public const NONCE        = 'slashphoto_pricelist';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Registers the post type.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => __( 'Price lists', 'slashphoto' ),
					'singular_name'      => __( 'Price list', 'slashphoto' ),
					'add_new'            => __( 'Add price list', 'slashphoto' ),
					'add_new_item'       => __( 'Add price list', 'slashphoto' ),
					'edit_item'          => __( 'Edit price list', 'slashphoto' ),
					'new_item'           => __( 'New price list', 'slashphoto' ),
					'search_items'       => __( 'Search price lists', 'slashphoto' ),
					'not_found'          => __( 'No price lists found.', 'slashphoto' ),
					'not_found_in_trash' => __( 'No price lists found in Trash.', 'slashphoto' ),
					'all_items'          => __( 'Price lists', 'slashphoto' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . PostType::POST_TYPE,
				'show_in_rest'    => false,
				'supports'        => [ 'title' ],
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			]
		);
	}

	/**
	 * Published price lists, for menus.
	 *
	 * @return array<int, string> ID → title.
	 */
	public static function all(): array {
		$lists = [];
		foreach ( get_posts(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => 'title',
				'order'       => 'ASC',
			]
		) as $post ) {
			$lists[ $post->ID ] = get_the_title( $post );
		}
		return $lists;
	}

	/**
	 * Products of a price list.
	 *
	 * @param int $list_id Price list.
	 * @return array<int, array{id: string, kind: string, name: string, price: int}>
	 */
	public static function products( int $list_id ): array {
		$post = get_post( $list_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return [];
		}
		$saved = json_decode( (string) get_post_meta( $list_id, self::META, true ), true );
		return self::clean( is_array( $saved ) ? $saved : [] );
	}

	/**
	 * One product of a price list.
	 *
	 * @param int    $list_id    Price list.
	 * @param string $product_id Product ID.
	 * @return array{id: string, kind: string, name: string, price: int}|null
	 */
	public static function product( int $list_id, string $product_id ): ?array {
		foreach ( self::products( $list_id ) as $product ) {
			if ( $product['id'] === $product_id ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * Saves products (already in minor units).
	 *
	 * @param int   $list_id  Price list.
	 * @param array $products Products.
	 */
	public static function save_products( int $list_id, array $products ): void {
		update_post_meta( $list_id, self::META, wp_slash( (string) wp_json_encode( self::clean( $products ) ) ) );
	}

	/**
	 * Normalises a product list.
	 *
	 * @param array $rows Rows.
	 * @return array<int, array{id: string, kind: string, name: string, price: int}>
	 */
	public static function clean( array $rows ): array {
		$products = [];
		$seen     = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $products ) >= self::MAX_PRODUCTS ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 120 );
			$kind = (string) ( $row['kind'] ?? '' );
			if ( '' === $name || ! in_array( $kind, self::KINDS, true ) ) {
				continue;
			}
			$id = (string) ( $row['id'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9]{8}$/', $id ) || isset( $seen[ $id ] ) ) {
				$id = bin2hex( random_bytes( 4 ) );
			}
			$seen[ $id ] = true;
			$products[]  = [
				'id'    => $id,
				'kind'  => $kind,
				'name'  => $name,
				'price' => max( 0, (int) ( $row['price'] ?? 0 ) ),
			];
		}//end foreach
		return $products;
	}

	/**
	 * The price list a gallery uses, or 0 when its images are not for sale.
	 *
	 * @param WP_Post $gallery Gallery.
	 * @param Access  $access  Access rules (for the parent chain).
	 */
	public static function for_gallery( WP_Post $gallery, Access $access ): int {
		foreach ( $access->chain( $gallery ) as $post ) {
			$value = (string) get_post_meta( $post->ID, self::GALLERY_META, true );
			if ( self::NONE === $value ) {
				return 0;
			}
			if ( ctype_digit( $value ) && (int) $value > 0 ) {
				return self::products( (int) $value ) ? (int) $value : 0;
			}
		}
		$default = (int) Settings::get( 'shop_price_list' );
		return $default && self::products( $default ) ? $default : 0;
	}

	/**
	 * Adds the products box.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'slashphoto-products', __( 'Products', 'slashphoto' ), [ $this, 'render' ], self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Suggested products for a new price list (prices left empty).
	 *
	 * @return array<int, array{id: string, kind: string, name: string, price: int}>
	 */
	public static function suggestions(): array {
		return [
			[
				'id'    => '',
				'kind'  => 'download',
				'name'  => __( 'Digital download, full resolution', 'slashphoto' ),
				'price' => 0,
			],
			[
				'id'    => '',
				'kind'  => 'print',
				'name'  => __( 'Print 13 × 18 cm', 'slashphoto' ),
				'price' => 0,
			],
			[
				'id'    => '',
				'kind'  => 'print',
				'name'  => __( 'Print 20 × 30 cm', 'slashphoto' ),
				'price' => 0,
			],
			[
				'id'    => '',
				'kind'  => 'print',
				'name'  => __( 'Print 30 × 45 cm', 'slashphoto' ),
				'price' => 0,
			],
		];
	}

	/**
	 * Renders the products box.
	 *
	 * @param WP_Post $post Price list.
	 */
	public function render( WP_Post $post ): void {
		$currency = (string) Settings::get( 'currency' );
		$saved    = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
		$products = is_array( $saved ) ? self::clean( $saved ) : [];
		$rows     = $products ? $products : self::suggestions();
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<p><?php esc_html_e( 'Customers choose one of these for each image. Downloads deliver the original file you uploaded; prints are made and sent by you. Rows without a price are not saved; enter 0 for something free.', 'slashphoto' ); ?></p>
		<table class="widefat striped slashphoto-products" data-slashphoto-products>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Type', 'slashphoto' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name shown to customers', 'slashphoto' ); ?></th>
					<th scope="col">
						<?php
						/* translators: %s: currency code such as SEK */
						echo esc_html( sprintf( __( 'Price (%s)', 'slashphoto' ), $currency ) );
						?>
					</th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'slashphoto' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $index => $row ) {
					$this->row( (string) $index, $row, $currency );
				}
				?>
			</tbody>
		</table>
		<template data-slashphoto-product-template>
			<?php
			$this->row(
				'__i__',
				[
					'id'    => '',
					'kind'  => 'print',
					'name'  => '',
					'price' => 0,
				],
				$currency
			);
			?>
		</template>
		<p><button type="button" class="button" data-slashphoto-add-product><?php esc_html_e( 'Add product', 'slashphoto' ); ?></button></p>
		<p class="description">
			<?php
			echo esc_html(
				Settings::get( 'prices_include_tax' )
					? __( 'Prices include VAT, as set under SlashPhoto → Settings.', 'slashphoto' )
					: __( 'VAT is added on top of these prices, as set under SlashPhoto → Settings.', 'slashphoto' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * One editable product row.
	 *
	 * @param string $index    Row index.
	 * @param array  $row      Product.
	 * @param string $currency Currency.
	 */
	private function row( string $index, array $row, string $currency ): void {
		$name = 'slashphoto_products[' . $index . ']';
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
				<label class="screen-reader-text" for="slashphoto-kind-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Type', 'slashphoto' ); ?></label>
				<select id="slashphoto-kind-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[kind]">
					<option value="download" <?php selected( $row['kind'], 'download' ); ?>><?php esc_html_e( 'Download', 'slashphoto' ); ?></option>
					<option value="print" <?php selected( $row['kind'], 'print' ); ?>><?php esc_html_e( 'Print', 'slashphoto' ); ?></option>
				</select>
			</td>
			<td>
				<label class="screen-reader-text" for="slashphoto-name-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Name shown to customers', 'slashphoto' ); ?></label>
				<input type="text" class="widefat" maxlength="120" id="slashphoto-name-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( (string) $row['name'] ); ?>">
			</td>
			<td>
				<label class="screen-reader-text" for="slashphoto-price-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Price', 'slashphoto' ); ?></label>
				<input type="text" inputmode="decimal" size="8" id="slashphoto-price-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( (int) $row['price'] ? Money::to_input( (int) $row['price'], $currency ) : '' ); ?>">
			</td>
			<td><button type="button" class="button-link button-link-delete" data-slashphoto-remove-product><?php esc_html_e( 'Remove', 'slashphoto' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Saves the products box.
	 *
	 * @param int     $post_id Price list.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$currency = (string) Settings::get( 'currency' );
		// Each field is sanitised in clean(); prices are parsed here.
		$rows     = isset( $_POST['slashphoto_products'] ) && is_array( $_POST['slashphoto_products'] ) ? wp_unslash( $_POST['slashphoto_products'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$products = [];
		foreach ( $rows as $index => $row ) {
			if ( '__i__' === (string) $index || ! is_array( $row ) ) {
				continue;
			}
			// A row without a price is a suggestion that was not filled in.
			if ( '' === trim( (string) ( $row['price'] ?? '' ) ) ) {
				continue;
			}
			$row['price'] = Money::parse( (string) ( $row['price'] ?? '' ), $currency );
			$products[]   = $row;
		}
		self::save_products( $post_id, $products );
	}

	/**
	 * Script for adding and removing rows.
	 *
	 * @param string $hook Admin page.
	 */
	public function enqueue( $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_register_script( 'slashphoto-pricelist', '', [], SLASHPHOTO_VERSION, true );
		wp_enqueue_script( 'slashphoto-pricelist' );
		wp_add_inline_script(
			'slashphoto-pricelist',
			<<<'JS'
( function () {
	const table = document.querySelector( '[data-slashphoto-products]' );
	const template = document.querySelector( '[data-slashphoto-product-template]' );
	const add = document.querySelector( '[data-slashphoto-add-product]' );
	if ( ! table || ! template || ! add ) {
		return;
	}
	let next = table.tBodies[ 0 ].rows.length + 1;
	add.addEventListener( 'click', () => {
		const html = template.innerHTML.replaceAll( '__i__', 'n' + next++ );
		table.tBodies[ 0 ].insertAdjacentHTML( 'beforeend', html );
		table.tBodies[ 0 ].lastElementChild.querySelector( 'input[type="text"]' ).focus();
	} );
	table.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-slashphoto-remove-product]' );
		if ( button ) {
			button.closest( 'tr' ).remove();
			add.focus();
		}
	} );
} )();
JS
		);
	}
}
