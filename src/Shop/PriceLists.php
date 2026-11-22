<?php
/**
 * Price lists.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Access\Access;
use SlashPhoto\Frontend\Assets;
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
	public const KINDS        = [ 'download', 'print', 'package' ];
	public const MAX_PACKAGE  = 500;
	public const MAX_PRODUCTS = 100;
	public const NONCE        = 'slashphoto_pricelist';
	/** Limits for text entered in the options editor. */
	private const MAX_VARIANTS     = 24;
	private const MAX_OPTION_LABEL = 36;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'variant_notice' ] );
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
		$saved    = json_decode( (string) get_post_meta( $list_id, self::META, true ), true );
		$products = self::clean( is_array( $saved ) ? $saved : [] );
		// A package must give each photo a download or print from the same list.
		$plain = [];
		foreach ( $products as $product ) {
			if ( 'package' !== $product['kind'] ) {
				$plain[ $product['id'] ] = true;
			}
		}
		return array_values( array_filter( $products, static fn( array $product ): bool => 'package' !== $product['kind'] || isset( $plain[ $product['includes'] ] ) ) );
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
	 * Normalises a product list. Packages also have "count" (number of
	 * photos, 0 for every photo in the gallery) and "includes" (the ID of
	 * the download or print each photo gets). Downloads may have "size",
	 * the longest side in pixels; without it the original is delivered.
	 *
	 * @param array $rows Rows.
	 * @return array<int, array{id: string, kind: string, name: string, price: int, count?: int, includes?: string, size?: int}>
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
			$product     = [
				'id'    => $id,
				'kind'  => $kind,
				'name'  => $name,
				'price' => max( 0, (int) ( $row['price'] ?? 0 ) ),
			];
			if ( 'package' === $kind ) {
				$product['count']    = min( self::MAX_PACKAGE, max( 0, (int) ( $row['count'] ?? 0 ) ) );
				$product['includes'] = preg_match( '/^[a-f0-9]{8}$/', (string) ( $row['includes'] ?? '' ) ) ? (string) $row['includes'] : '';
			}
			if ( 'download' === $kind ) {
				$size = DownloadSizes::clean( $row['size'] ?? 0 );
				if ( $size ) {
					$product['size'] = $size;
				}
			}
			if ( 'print' === $kind && ! empty( $row['variants'] ) ) {
				$product['variants'] = self::clean_variants( (array) $row['variants'] );
			}
			$products[] = $product;
		}//end foreach
		return $products;
	}

	/**
	 * Keeps explicitly offered paper/framing combinations. Their IDs survive
	 * price edits and reordering. A different combination gets a new ID.
	 *
	 * @param array $rows Variants, priced in minor units.
	 * @return array<string, array{id: string, paper: string, frame: string, price: int}>
	 */
	private static function clean_variants( array $rows ): array {
		$variants = [];
		foreach ( array_slice( $rows, 0, self::MAX_VARIANTS ) as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['paper'] ?? null ) || ! is_string( $row['frame'] ?? null ) || ! is_int( $row['price'] ?? null ) || $row['price'] < 0 ) {
				continue;
			}
			$paper = sanitize_text_field( $row['paper'] );
			$frame = sanitize_text_field( $row['frame'] );
			if ( '' === $paper || mb_strlen( $paper ) > self::MAX_OPTION_LABEL || mb_strlen( $frame ) > self::MAX_OPTION_LABEL || str_contains( $paper . $frame, '|' ) ) {
				continue;
			}
			$id              = substr( hash( 'sha256', $paper . "\0" . $frame ), 0, 16 );
			$variants[ $id ] = [
				'id'    => $id,
				'paper' => $paper,
				'frame' => $frame,
				'price' => $row['price'],
			];
		}
		return $variants;
	}

	/**
	 * Customer-facing name of a paper and framing combination.
	 *
	 * @param array $variant Configured print option.
	 */
	public static function variant_label( array $variant ): string {
		return $variant['paper'] . ' / ' . ( '' === $variant['frame'] ? __( 'Unframed', 'slashphoto' ) : $variant['frame'] );
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
				'kind'  => 'download',
				'name'  => __( 'Digital download, web size (2048 px)', 'slashphoto' ),
				'price' => 0,
				'size'  => 2048,
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
		$plain    = array_values( array_filter( $products, static fn( array $p ): bool => 'package' !== $p['kind'] ) );
		$presets  = array_intersect_key( PrintPresets::all(), array_flip( Settings::get( 'print_presets' ) ) );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<p><?php esc_html_e( 'Customers choose one of these for each image. Downloads deliver the original file you uploaded, or a smaller copy of it when you set the longest side. You make and send the prints. Enter a price or configure print options. Use 0 for something free.', 'slashphoto' ); ?></p>
		<p><?php esc_html_e( 'A package is a number of photos the customer chooses, each with the same download or print, for one price, e.g. “10 downloads”. Set the number to 0 for every photo in the gallery. Save new downloads and prints before choosing them in a package.', 'slashphoto' ); ?></p>
		<table class="widefat striped slashphoto-products" role="table" data-slashphoto-products>
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
					$this->row( (string) $index, $row, $currency, $plain, $presets );
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
				$currency,
				$plain,
				$presets
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
	 * @param array  $plain    Saved downloads and prints, for packages.
	 * @param array  $presets  Enabled paper and framing templates.
	 */
	private function row( string $index, array $row, string $currency, array $plain, array $presets ): void {
		$package       = 'package' === $row['kind'];
		$name          = 'slashphoto_products[' . $index . ']';
		$variant_lines = [];
		foreach ( $row['variants'] ?? [] as $variant ) {
			$variant_lines[] = $variant['paper'] . ' | ' . $variant['frame'] . ' | ' . Money::to_input( $variant['price'], $currency );
		}
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
				<label class="slashphoto-product-label" for="slashphoto-kind-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Type', 'slashphoto' ); ?></label>
				<select id="slashphoto-kind-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[kind]">
					<option value="download" <?php selected( $row['kind'], 'download' ); ?>><?php esc_html_e( 'Download', 'slashphoto' ); ?></option>
					<option value="print" <?php selected( $row['kind'], 'print' ); ?>><?php esc_html_e( 'Print', 'slashphoto' ); ?></option>
					<option value="package" <?php selected( $row['kind'], 'package' ); ?>><?php esc_html_e( 'Package', 'slashphoto' ); ?></option>
				</select>
			</td>
			<td>
				<label class="slashphoto-product-label" for="slashphoto-name-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Name shown to customers', 'slashphoto' ); ?></label>
				<input type="text" class="widefat" maxlength="120" id="slashphoto-name-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( (string) $row['name'] ); ?>">
				<p class="slashphoto-package-fields" data-slashphoto-package-fields <?php echo $package ? '' : 'hidden'; ?>>
					<label for="slashphoto-count-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Photos', 'slashphoto' ); ?></label>
					<input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_PACKAGE ); ?>" style="width:5em" id="slashphoto-count-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[count]" value="<?php echo esc_attr( (string) ( $row['count'] ?? 10 ) ); ?>">
					<label for="slashphoto-includes-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'each with', 'slashphoto' ); ?></label>
					<select id="slashphoto-includes-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[includes]">
						<?php foreach ( $plain as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( $row['includes'] ?? '', $option['id'] ); ?>><?php echo esc_html( $option['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="slashphoto-download-fields" data-slashphoto-download-fields <?php echo 'download' === $row['kind'] ? '' : 'hidden'; ?>>
					<label for="slashphoto-size-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Longest side (px)', 'slashphoto' ); ?></label>
					<input type="number" min="<?php echo esc_attr( (string) DownloadSizes::MIN ); ?>" max="<?php echo esc_attr( (string) DownloadSizes::MAX ); ?>" step="1" style="width:7em" id="slashphoto-size-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[size]" value="<?php echo esc_attr( ! empty( $row['size'] ) ? (string) $row['size'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Original', 'slashphoto' ); ?>" aria-describedby="slashphoto-size-help-<?php echo esc_attr( $index ); ?>">
					<span class="description" id="slashphoto-size-help-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Leave empty for the full-size original. Smaller originals are never enlarged.', 'slashphoto' ); ?></span>
				</p>
				<details data-slashphoto-print-options <?php echo 'print' === $row['kind'] ? '' : 'hidden'; ?>>
					<summary><?php esc_html_e( 'Paper and framing options', 'slashphoto' ); ?></summary>
					<?php if ( $presets ) : ?>
						<div class="slashphoto-print-template">
							<label for="slashphoto-print-template-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Print template', 'slashphoto' ); ?></label>
							<select id="slashphoto-print-template-<?php echo esc_attr( $index ); ?>" data-slashphoto-print-template>
								<option value=""><?php esc_html_e( 'Choose a template', 'slashphoto' ); ?></option>
								<?php foreach ( $presets as $key => $preset ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" data-combinations="<?php echo esc_attr( wp_json_encode( $preset['options'] ) ); ?>"><?php echo esc_html( $preset['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button" data-slashphoto-add-combinations disabled><?php esc_html_e( 'Add combinations', 'slashphoto' ); ?></button>
						</div>
						<p class="description slashphoto-print-template-status" data-slashphoto-template-status role="status"></p>
					<?php endif; ?>
					<label for="slashphoto-variants-<?php echo esc_attr( $index ); ?>" class="screen-reader-text"><?php esc_html_e( 'Print options', 'slashphoto' ); ?></label>
					<textarea id="slashphoto-variants-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[variant_input]" class="widefat" rows="4" data-slashphoto-variant-input aria-describedby="slashphoto-variants-help-<?php echo esc_attr( $index ); ?>"><?php echo esc_textarea( implode( "\n", $variant_lines ) ); ?></textarea>
					<p class="description" id="slashphoto-variants-help-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Enter one available combination per line as Paper | Frame | Full price. Leave the frame blank for an unframed print. The first combination is selected by default.', 'slashphoto' ); ?></p>
				</details>
			</td>
			<td>
				<label class="slashphoto-product-label" for="slashphoto-price-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Price', 'slashphoto' ); ?></label>
				<input type="text" inputmode="decimal" size="8" id="slashphoto-price-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( '' === (string) $row['id'] && ! (int) $row['price'] ? '' : Money::to_input( (int) $row['price'], $currency ) ); ?>">
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
			$variant_input = $row['variant_input'] ?? '';
			$variants      = [];
			if ( 'print' === ( $row['kind'] ?? '' ) && '' !== $variant_input ) {
				$variants = is_string( $variant_input ) ? self::parse_variants( $variant_input, $currency ) : null;
				if ( null === $variants ) {
					add_filter( 'redirect_post_location', static fn( string $url ): string => add_query_arg( 'slashphoto_invalid_print_options', '1', $url ) );
					return;
				}
			}
			$price = trim( (string) ( $row['price'] ?? '' ) );
			// A suggestion without a price or any options was not filled in.
			if ( '' === $price && ! $variants ) {
				continue;
			}
			$row['price']    = '' === $price ? reset( $variants )['price'] : Money::parse( $price, $currency );
			$row['variants'] = $variants;
			$products[]      = $row;
		}//end foreach
		self::save_products( $post_id, $products );
	}

	/**
	 * Parses a combination per line. A bad row rejects the whole price-list
	 * update so a typo cannot remove an option or turn its price into zero.
	 *
	 * @param string $input    Submitted options.
	 * @param string $currency Site currency.
	 * @return array|null Parsed combination list, or null for invalid input.
	 */
	private static function parse_variants( string $input, string $currency ): ?array {
		$lines = preg_split( '/\R/u', trim( $input ), -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $lines ) {
			return null;
		}
		$rows = [];
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', explode( '|', $line ) );
			if ( 3 !== count( $cells ) || ! preg_match( '/^\d{1,9}(?:[.,]\d{1,2})?$/D', $cells[2] ) || count( $rows ) >= self::MAX_VARIANTS ) {
				return null;
			}
			$rows[] = [
				'paper' => $cells[0],
				'frame' => $cells[1],
				'price' => Money::parse( $cells[2], $currency ),
			];
		}
		$variants = self::clean_variants( $rows );
		return count( $variants ) === count( $rows ) ? $variants : null;
	}

	/**
	 * Reports a rejected price-list edit after WordPress redirects back.
	 */
	public function variant_notice(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this flag only displays a notice.
		if ( $screen && self::POST_TYPE === $screen->post_type && isset( $_GET['slashphoto_invalid_print_options'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The price list was kept as it was. Enter each paper and frame combination once, with a price of zero or more. A print can have up to 24 combinations and each paper or frame name can be up to 36 characters.', 'slashphoto' ) . '</p></div>';
		}
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
		$script = 'assets/js/admin-price-lists.js';
		$style  = 'assets/css/admin-price-lists.css';
		wp_enqueue_script( 'slashphoto-pricelist', SLASHPHOTO_URL . $script, [], Assets::version( $script ), true );
		wp_enqueue_style( 'slashphoto-pricelist', SLASHPHOTO_URL . $style, [], Assets::version( $style ) );
		wp_localize_script(
			'slashphoto-pricelist',
			'slashPhotoPriceLists',
			[
				'limit'     => self::MAX_VARIANTS,
				'added'     => __( 'Combinations added. Enter a price for each new row.', 'slashphoto' ),
				'duplicate' => __( 'These combinations are already in this print.', 'slashphoto' ),
				'full'      => __( 'This template would exceed the maximum of 24 combinations per print.', 'slashphoto' ),
				'missing'   => __( 'Enter a price for every paper and frame combination before saving.', 'slashphoto' ),
			]
		);
	}
}
