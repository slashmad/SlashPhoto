<?php
/**
 * Discount codes.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A discount code is a private post whose title is the code. Published
 * codes work; drafts and trashed codes do not.
 *
 * Settings: percent or fixed amount, which products it applies to
 * (everything, downloads or prints), free shipping, minimum order value,
 * first and last day, total number of uses and uses per email address.
 * Uses are counted from orders (placed and not cancelled), so there is no
 * counter to drift.
 */
final class Coupons {

	public const POST_TYPE = 'slashphoto_coupon';
	public const META      = '_slashphoto_coupon';
	public const NONCE     = 'slashphoto_coupon';
	public const TYPES     = [ 'percent', 'fixed' ];
	public const APPLIES   = [ 'all', 'download', 'print' ];

	/**
	 * Order statuses that use up a code.
	 */
	public const COUNTED = [ 'pending', 'on-hold', 'paid', 'completed' ];

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ $this, 'normalize_title' ], 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'enter_title_here', [ $this, 'title_placeholder' ], 10, 2 );
	}

	/**
	 * Registers the post type.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => __( 'Discount codes', 'slashphoto' ),
					'singular_name'      => __( 'Discount code', 'slashphoto' ),
					'add_new'            => __( 'Add discount code', 'slashphoto' ),
					'add_new_item'       => __( 'Add discount code', 'slashphoto' ),
					'edit_item'          => __( 'Edit discount code', 'slashphoto' ),
					'search_items'       => __( 'Search discount codes', 'slashphoto' ),
					'not_found'          => __( 'No discount codes found.', 'slashphoto' ),
					'not_found_in_trash' => __( 'No discount codes found in Trash.', 'slashphoto' ),
					'all_items'          => __( 'Discount codes', 'slashphoto' ),
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
	 * Codes are compared in upper case without spaces.
	 *
	 * @param string $code Code as typed.
	 */
	public static function normalize( string $code ): string {
		return mb_substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $code ) ), 0, 50 );
	}

	/**
	 * Stores codes in their normal form.
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function normalize_title( $data, $postarr ) {
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && '' !== (string) $data['post_title'] ) {
			$data['post_title'] = self::normalize( (string) $data['post_title'] );
		}
		unset( $postarr );
		return $data;
	}

	/**
	 * Default rules.
	 *
	 * @return array{type: string, amount: int|float, applies: string, free_shipping: bool, min_subtotal: int, starts: string, ends: string, limit: int, limit_per_email: int}
	 */
	public static function defaults(): array {
		return [
			'type'            => 'percent',
			'amount'          => 10,
			'applies'         => 'all',
			'free_shipping'   => false,
			'min_subtotal'    => 0,
			'starts'          => '',
			'ends'            => '',
			'limit'           => 0,
			'limit_per_email' => 0,
		];
	}

	/**
	 * Cleans rules. Fixed amounts are in minor units; percent is 0–100.
	 *
	 * @param array $rules Rules.
	 */
	public static function clean( array $rules ): array {
		$d    = self::defaults();
		$type = in_array( $rules['type'] ?? '', self::TYPES, true ) ? $rules['type'] : $d['type'];
		$date = static fn( $value ): string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
		return [
			'type'            => $type,
			'amount'          => 'percent' === $type ? min( 100.0, max( 0.0, round( (float) ( $rules['amount'] ?? 0 ), 2 ) ) ) : max( 0, (int) ( $rules['amount'] ?? 0 ) ),
			'applies'         => in_array( $rules['applies'] ?? '', self::APPLIES, true ) ? $rules['applies'] : 'all',
			'free_shipping'   => ! empty( $rules['free_shipping'] ),
			'min_subtotal'    => max( 0, (int) ( $rules['min_subtotal'] ?? 0 ) ),
			'starts'          => $date( $rules['starts'] ?? '' ),
			'ends'            => $date( $rules['ends'] ?? '' ),
			'limit'           => max( 0, (int) ( $rules['limit'] ?? 0 ) ),
			'limit_per_email' => max( 0, (int) ( $rules['limit_per_email'] ?? 0 ) ),
		];
	}

	/**
	 * Rules of a code post.
	 *
	 * @param int $id Post.
	 */
	public static function rules( int $id ): array {
		$saved = json_decode( (string) get_post_meta( $id, self::META, true ), true );
		return self::clean( is_array( $saved ) ? $saved : self::defaults() );
	}

	/**
	 * Saves rules.
	 *
	 * @param int   $id    Post.
	 * @param array $rules Rules.
	 */
	public static function save_rules( int $id, array $rules ): void {
		update_post_meta( $id, self::META, wp_slash( (string) wp_json_encode( self::clean( $rules ) ) ) );
	}

	/**
	 * A published code.
	 *
	 * @param string $code Code as typed.
	 * @return array{id: int, code: string, rules: array}|null
	 */
	public static function find( string $code ): ?array {
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$posts = get_posts(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'title'       => $code,
				'numberposts' => 1,
			]
		);
		if ( ! $posts || $posts[0]->post_title !== $code ) {
			return null;
		}
		return [
			'id'    => $posts[0]->ID,
			'code'  => $code,
			'rules' => self::rules( $posts[0]->ID ),
		];
	}

	/**
	 * Applies a code to a quote's lines.
	 *
	 * @param string $code     Code.
	 * @param array  $lines    Priced lines (kind, total).
	 * @param int    $subtotal Subtotal.
	 * @param string $email    Customer's email, when known.
	 * @return array{code: string, discount: int, free_shipping: bool, error: string}
	 */
	public static function apply( string $code, array $lines, int $subtotal, string $email = '' ): array {
		$none   = [
			'code'          => '',
			'discount'      => 0,
			'free_shipping' => false,
			'error'         => '',
		];
		$coupon = self::find( $code );
		if ( ! $coupon ) {
			return array_merge( $none, [ 'error' => __( 'This discount code is not valid.', 'slashphoto' ) ] );
		}
		$rules    = $coupon['rules'];
		$currency = (string) Settings::get( 'currency' );
		$today    = wp_date( 'Y-m-d' );

		if ( ( '' !== $rules['starts'] && $today < $rules['starts'] ) || ( '' !== $rules['ends'] && $today > $rules['ends'] ) ) {
			return array_merge( $none, [ 'error' => __( 'This discount code is not valid right now.', 'slashphoto' ) ] );
		}
		if ( $rules['min_subtotal'] > 0 && $subtotal < $rules['min_subtotal'] ) {
			return array_merge(
				$none,
				/* translators: %s: amount */
				[ 'error' => sprintf( __( 'This discount code needs an order of at least %s.', 'slashphoto' ), Money::format( $rules['min_subtotal'], $currency ) ) ]
			);
		}
		if ( $rules['limit'] > 0 && self::uses( $coupon['code'] ) >= $rules['limit'] ) {
			return array_merge( $none, [ 'error' => __( 'This discount code has been used up.', 'slashphoto' ) ] );
		}
		if ( $rules['limit_per_email'] > 0 && '' !== $email && self::uses( $coupon['code'], $email ) >= $rules['limit_per_email'] ) {
			return array_merge( $none, [ 'error' => __( 'You have already used this discount code.', 'slashphoto' ) ] );
		}

		$eligible = 0;
		foreach ( $lines as $line ) {
			// A package counts as the kind of product it gives each photo.
			$kind = $line['includes']['kind'] ?? $line['kind'];
			if ( 'all' === $rules['applies'] || $kind === $rules['applies'] ) {
				$eligible += (int) $line['total'];
			}
		}
		if ( ! $eligible && ! ( $rules['free_shipping'] && 'download' !== $rules['applies'] ) ) {
			return array_merge( $none, [ 'error' => __( 'This discount code does not apply to anything in your cart.', 'slashphoto' ) ] );
		}
		$discount = 'percent' === $rules['type'] ? (int) round( $eligible * $rules['amount'] / 100 ) : min( (int) $rules['amount'], $eligible );

		return [
			'code'          => $coupon['code'],
			'discount'      => max( 0, $discount ),
			'free_shipping' => (bool) $rules['free_shipping'],
			'error'         => '',
		];
	}

	/**
	 * Number of orders that used a code.
	 *
	 * @param string $code  Code.
	 * @param string $email Only orders from this address.
	 */
	public static function uses( string $code, string $email = '' ): int {
		global $wpdb;
		$table    = Schema::orders();
		$statuses = "'" . implode( "','", self::COUNTED ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; statuses are constants.
		if ( '' !== $email ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon = %s AND email = %s AND status IN ({$statuses})", $code, $email ) );
		} else {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon = %s AND status IN ({$statuses})", $code ) );
		}
		// phpcs:enable
		return (int) $count;
	}

	/**
	 * Placeholder for the code field.
	 *
	 * @param string  $text Placeholder.
	 * @param WP_Post $post Post.
	 */
	public function title_placeholder( $text, $post ) {
		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type ? __( 'Code, e.g. SUMMER20', 'slashphoto' ) : $text;
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['title']               = __( 'Code', 'slashphoto' );
		$columns['slashphoto_discount'] = __( 'Discount', 'slashphoto' );
		$columns['slashphoto_uses']     = __( 'Used', 'slashphoto' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * List column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public function column( $column, $post_id ): void {
		$rules = self::rules( (int) $post_id );
		if ( 'slashphoto_discount' === $column ) {
			echo esc_html( self::describe( $rules ) );
		} elseif ( 'slashphoto_uses' === $column ) {
			$uses = self::uses( (string) get_the_title( $post_id ) );
			echo esc_html( $rules['limit'] ? $uses . ' / ' . $rules['limit'] : (string) $uses );
		}
	}

	/**
	 * Short description of the rules.
	 *
	 * @param array $rules Rules.
	 */
	public static function describe( array $rules ): string {
		$currency = (string) Settings::get( 'currency' );
		$parts    = [];
		if ( $rules['amount'] > 0 ) {
			$parts[] = 'percent' === $rules['type'] ? number_format_i18n( $rules['amount'], floor( $rules['amount'] ) === (float) $rules['amount'] ? 0 : 2 ) . ' %' : Money::format( (int) $rules['amount'], $currency );
		}
		if ( $rules['free_shipping'] ) {
			$parts[] = __( 'free shipping', 'slashphoto' );
		}
		$labels = [
			'download' => __( 'downloads only', 'slashphoto' ),
			'print'    => __( 'prints only', 'slashphoto' ),
		];
		if ( isset( $labels[ $rules['applies'] ] ) ) {
			$parts[] = $labels[ $rules['applies'] ];
		}
		return implode( ', ', $parts );
	}

	/**
	 * Adds the rules box.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'slashphoto-coupon', __( 'Discount', 'slashphoto' ), [ $this, 'render' ], self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renders the rules box.
	 *
	 * @param WP_Post $post Code.
	 */
	public function render( WP_Post $post ): void {
		$r        = self::rules( $post->ID );
		$currency = (string) Settings::get( 'currency' );
		$fixed    = 'fixed' === $r['type'] ? Money::to_input( (int) $r['amount'], $currency ) : (string) $r['amount'];
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<p><?php esc_html_e( 'Customers type the code (the title above) in the cart. Publish it to switch it on; save it as a draft to switch it off.', 'slashphoto' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="slashphoto-coupon-amount"><?php esc_html_e( 'Discount', 'slashphoto' ); ?></label></th>
				<td>
					<input type="text" inputmode="decimal" size="8" id="slashphoto-coupon-amount" name="slashphoto_coupon[amount]" value="<?php echo esc_attr( $fixed ); ?>">
					<label class="screen-reader-text" for="slashphoto-coupon-type"><?php esc_html_e( 'Type', 'slashphoto' ); ?></label>
					<select id="slashphoto-coupon-type" name="slashphoto_coupon[type]">
						<option value="percent" <?php selected( $r['type'], 'percent' ); ?>>%</option>
						<option value="fixed" <?php selected( $r['type'], 'fixed' ); ?>><?php echo esc_html( $currency ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slashphoto-coupon-applies"><?php esc_html_e( 'Applies to', 'slashphoto' ); ?></label></th>
				<td>
					<select id="slashphoto-coupon-applies" name="slashphoto_coupon[applies]">
						<option value="all" <?php selected( $r['applies'], 'all' ); ?>><?php esc_html_e( 'Everything (downloads, prints, packages)', 'slashphoto' ); ?></option>
						<option value="download" <?php selected( $r['applies'], 'download' ); ?>><?php esc_html_e( 'Downloads', 'slashphoto' ); ?></option>
						<option value="print" <?php selected( $r['applies'], 'print' ); ?>><?php esc_html_e( 'Prints', 'slashphoto' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shipping', 'slashphoto' ); ?></th>
				<td><label><input type="checkbox" name="slashphoto_coupon[free_shipping]" value="1" <?php checked( $r['free_shipping'] ); ?>> <?php esc_html_e( 'Free shipping', 'slashphoto' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="slashphoto-coupon-min"><?php esc_html_e( 'Minimum order', 'slashphoto' ); ?></label></th>
				<td><input type="text" inputmode="decimal" size="8" id="slashphoto-coupon-min" name="slashphoto_coupon[min_subtotal]" value="<?php echo esc_attr( $r['min_subtotal'] ? Money::to_input( $r['min_subtotal'], $currency ) : '' ); ?>"> <?php echo esc_html( $currency ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Valid', 'slashphoto' ); ?></th>
				<td>
					<label for="slashphoto-coupon-starts"><?php esc_html_e( 'From', 'slashphoto' ); ?></label>
					<input type="date" id="slashphoto-coupon-starts" name="slashphoto_coupon[starts]" value="<?php echo esc_attr( $r['starts'] ); ?>">
					<label for="slashphoto-coupon-ends"><?php esc_html_e( 'until', 'slashphoto' ); ?></label>
					<input type="date" id="slashphoto-coupon-ends" name="slashphoto_coupon[ends]" value="<?php echo esc_attr( $r['ends'] ); ?>">
					<p class="description"><?php esc_html_e( 'Both days included. Leave empty for no limit.', 'slashphoto' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slashphoto-coupon-limit"><?php esc_html_e( 'Uses in total', 'slashphoto' ); ?></label></th>
				<td><input type="number" min="0" id="slashphoto-coupon-limit" name="slashphoto_coupon[limit]" value="<?php echo esc_attr( (string) $r['limit'] ); ?>"> <span class="description"><?php esc_html_e( '0 means no limit.', 'slashphoto' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="slashphoto-coupon-per-email"><?php esc_html_e( 'Uses per customer', 'slashphoto' ); ?></label></th>
				<td><input type="number" min="0" id="slashphoto-coupon-per-email" name="slashphoto_coupon[limit_per_email]" value="<?php echo esc_attr( (string) $r['limit_per_email'] ); ?>"> <span class="description"><?php esc_html_e( 'Counted by email address; 0 means no limit.', 'slashphoto' ); ?></span></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the rules box.
	 *
	 * @param int     $post_id Code.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$raw      = isset( $_POST['slashphoto_coupon'] ) && is_array( $_POST['slashphoto_coupon'] ) ? map_deep( wp_unslash( $_POST['slashphoto_coupon'] ), 'sanitize_text_field' ) : [];
		$currency = (string) Settings::get( 'currency' );
		$type     = (string) ( $raw['type'] ?? 'percent' );
		self::save_rules(
			$post_id,
			[
				'type'            => $type,
				'amount'          => 'fixed' === $type ? Money::parse( (string) ( $raw['amount'] ?? '' ), $currency ) : (float) str_replace( ',', '.', (string) ( $raw['amount'] ?? '' ) ),
				'applies'         => $raw['applies'] ?? 'all',
				'free_shipping'   => ! empty( $raw['free_shipping'] ),
				'min_subtotal'    => Money::parse( (string) ( $raw['min_subtotal'] ?? '' ), $currency ),
				'starts'          => $raw['starts'] ?? '',
				'ends'            => $raw['ends'] ?? '',
				'limit'           => $raw['limit'] ?? 0,
				'limit_per_email' => $raw['limit_per_email'] ?? 0,
			]
		);
	}
}
