<?php
/**
 * Privacy policy text.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use SlashPhoto\Shop\Emails;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\Schema;
use SlashPhoto\Visitors\VisitorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests privacy policy text (Settings > Privacy) describing exactly what
 * SlashPhoto stores: no IP addresses or browser details, anonymous view
 * counts, the visitor log of email gates and terms acceptances, orders.
 * Client selections and orders are included in the WordPress personal data
 * export and erasure tools (Tools > Export / Erase Personal Data). Orders
 * that were paid are kept for bookkeeping; unpaid ones are erased.
 */
final class Privacy {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Adds the suggested text.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text from SlashPhoto. It describes the plugin as shipped; review it if you add other plugins.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Our photo galleries never store IP addresses or browser details, use no third-party analytics or tracking, and do not load anything from other websites.', 'slashphoto' ) . '</p>';
		if ( Settings::get( 'stats_enabled' ) ) {
			$text .= '<p>' . esc_html__( 'We count how often each gallery and photo is viewed, as anonymous daily totals. No cookie is set and nothing that identifies you is stored.', 'slashphoto' ) . '</p>';
		}
		$days = (int) Settings::get( 'visitor_retention_days' );
		$keep = $days
			/* translators: %d: number of days */
			? sprintf( __( 'We keep it for %d days.', 'slashphoto' ), $days )
			: __( 'We keep it until you ask us to delete it.', 'slashphoto' );
		$text     .= '<p>' . esc_html__( 'Some galleries ask for your email address before showing photos. We store the address you give, the gallery and the date, so we know who has seen the photos, and a cookie (slashphoto_email_…) remembers it in your browser for 30 days. If you are logged in, we note your account instead of asking.', 'slashphoto' ) . ' ' . esc_html( $keep ) . '</p>';
		$text     .= '<p>' . esc_html__( 'When you open a password-protected gallery, WordPress stores a cookie (wp-postpass) containing a scrambled form of the password, for up to 10 days.', 'slashphoto' ) . '</p>';
		$text     .= '<p>' . esc_html__( 'When you accept a gallery’s terms, a cookie (slashphoto_terms_…) remembers this in your browser for 30 days, and we record the acceptance with the date, which wording you accepted and your account or email address, so we can show what was agreed.', 'slashphoto' ) . ' ' . esc_html( $keep ) . '</p>';
		$text     .= '<p>' . esc_html__( 'When you open a gallery through a private link, a cookie (slashphoto_link_…) remembers this in your browser for 30 days. It contains only a signature of the gallery and the link; nothing about you is stored on our server.', 'slashphoto' ) . '</p>';
		$text     .= '<p>' . esc_html__( 'If you mark favourites without being logged in, they are kept only in your own browser (local storage) and never sent to us.', 'slashphoto' ) . '</p>';
		$text     .= '<p>' . esc_html__( 'If you choose images while logged in, we store your choice, any notes you write and the message you send with it, together with your account, so we can deliver your photos. It is deleted with your account or when we delete the gallery, and you can ask for a copy or for it to be erased.', 'slashphoto' ) . '</p>';
		$text     .= '<p>' . esc_html__( 'Your shopping cart is kept only in your own browser (local storage) until you order.', 'slashphoto' ) . '</p>';
		$text     .= '<p>' . esc_html__( 'When you order, we store your name, email address, phone number if you give it, delivery address for prints, your message and what you bought, to deliver the order and for our bookkeeping. Orders that are never paid are deleted after a week. Paid orders are kept as long as accounting law requires.', 'slashphoto' ) . '</p>';
		$providers = [
			'stripe' => 'Stripe (https://stripe.com/privacy)',
			'paypal' => 'PayPal (https://www.paypal.com/privacy)',
			'mollie' => 'Mollie (https://www.mollie.com/privacy)',
		];
		$active    = array_intersect_key( $providers, \SlashPhoto\Shop\Gateways\Gateways::available() );
		if ( $active ) {
			/* translators: %s: list of payment providers with links to their privacy policies */
			$text .= '<p>' . esc_html( sprintf( __( 'If you pay online, you enter your payment details on the page of our payment provider, not on this site: %s. The provider receives your email address (Stripe), the order lines and the amount, and processes the payment under its own privacy policy.', 'slashphoto' ), implode( ', ', $active ) ) ) . '</p>';
		}
		$text .= '<p>' . esc_html__( 'If we give you a client account, your name, email address and the galleries you may see are stored in that account until you ask us to delete it.', 'slashphoto' ) . '</p>';
		wp_add_privacy_policy_content( 'SlashPhoto', wp_kses_post( $text ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['slashphoto-selections'] = [
			'exporter_friendly_name' => __( 'Photo selections', 'slashphoto' ),
			'callback'               => [ self::class, 'export' ],
		];
		$exporters['slashphoto-visitors']   = [
			'exporter_friendly_name' => __( 'Photo gallery visitor log', 'slashphoto' ),
			'callback'               => [ self::class, 'export_visitors' ],
		];
		$exporters['slashphoto-orders']     = [
			'exporter_friendly_name' => __( 'Photo orders', 'slashphoto' ),
			'callback'               => [ self::class, 'export_orders' ],
		];
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['slashphoto-selections'] = [
			'eraser_friendly_name' => __( 'Photo selections', 'slashphoto' ),
			'callback'             => [ self::class, 'erase' ],
		];
		$erasers['slashphoto-visitors']   = [
			'eraser_friendly_name' => __( 'Photo gallery visitor log', 'slashphoto' ),
			'callback'             => [ self::class, 'erase_visitors' ],
		];
		$erasers['slashphoto-orders']     = [
			'eraser_friendly_name' => __( 'Photo orders', 'slashphoto' ),
			'callback'             => [ self::class, 'erase_orders' ],
		];
		return $erasers;
	}

	/**
	 * Exports the selections of the account with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = [];
		foreach ( Selections::for_user( $user ? $user->ID : 0 ) as $selection ) {
			$images = [];
			foreach ( Selections::items( $selection ) as $id => $note ) {
				$images[] = Proofing::file_label( (int) $id ) . ( '' !== $note ? ' (' . $note . ')' : '' );
			}
			$sent   = Selections::submitted_at( $selection );
			$data[] = [
				'group_id'    => 'slashphoto-selections',
				'group_label' => __( 'Photo selections', 'slashphoto' ),
				'item_id'     => 'slashphoto-selection-' . $selection->ID,
				'data'        => [
					[
						'name'  => __( 'Gallery', 'slashphoto' ),
						'value' => get_the_title( $selection->post_parent ),
					],
					[
						'name'  => __( 'Images', 'slashphoto' ),
						'value' => implode( ', ', $images ),
					],
					[
						'name'  => __( 'Message', 'slashphoto' ),
						'value' => Selections::message( $selection ),
					],
					[
						'name'  => __( 'Sent', 'slashphoto' ),
						'value' => $sent ? wp_date( 'Y-m-d H:i', $sent ) : '',
					],
				],
			];
		}//end foreach
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erases the selections of the account with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		foreach ( Selections::for_user( $user ? $user->ID : 0 ) as $selection ) {
			$removed = (bool) wp_delete_post( $selection->ID, true ) || $removed;
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Exports orders placed with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public static function export_orders( $email ): array {
		$data = [];
		foreach ( Orders::for_email( (string) $email ) as $order ) {
			$money  = static fn( int $amount ): string => Money::format( $amount, (string) $order['currency'] );
			$lines  = array_map(
				static fn( array $item ): string => $item['quantity'] . ' × ' . $item['name'] . ' – ' . $item['file_label'],
				Orders::items( (int) $order['id'] )
			);
			$data[] = [
				'group_id'    => 'slashphoto-orders',
				'group_label' => __( 'Photo orders', 'slashphoto' ),
				'item_id'     => 'slashphoto-order-' . $order['id'],
				'data'        => [
					[
						'name'  => __( 'Order', 'slashphoto' ),
						'value' => (string) $order['id'],
					],
					[
						'name'  => __( 'Date', 'slashphoto' ),
						'value' => (string) $order['created_at'] . ' UTC',
					],
					[
						'name'  => __( 'Status', 'slashphoto' ),
						'value' => Orders::labels()[ $order['status'] ] ?? (string) $order['status'],
					],
					[
						'name'  => __( 'Name', 'slashphoto' ),
						'value' => (string) $order['name'],
					],
					[
						'name'  => __( 'Email', 'slashphoto' ),
						'value' => (string) $order['email'],
					],
					[
						'name'  => __( 'Phone', 'slashphoto' ),
						'value' => (string) $order['phone'],
					],
					[
						'name'  => __( 'Delivery address', 'slashphoto' ),
						'value' => implode( ', ', Emails::address_lines( $order ) ),
					],
					[
						'name'  => __( 'Message', 'slashphoto' ),
						'value' => (string) $order['customer_note'],
					],
					[
						'name'  => __( 'Items', 'slashphoto' ),
						'value' => implode( '; ', $lines ),
					],
					[
						'name'  => __( 'Total', 'slashphoto' ),
						'value' => $money( (int) $order['total'] ),
					],
				],
			];
		}//end foreach
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erases unpaid orders placed with this email address. Paid, completed
	 * and refunded orders are bookkeeping records and are kept.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_orders( $email ): array {
		global $wpdb;
		$removed  = false;
		$retained = false;
		foreach ( Orders::for_email( (string) $email ) as $order ) {
			if ( in_array( $order['status'], [ 'pending', 'on-hold', 'failed', 'cancelled' ], true ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
				$wpdb->delete( Schema::items(), [ 'order_id' => $order['id'] ] );
				$wpdb->delete( Schema::orders(), [ 'id' => $order['id'] ] );
				// phpcs:enable
				$removed = true;
			} else {
				$retained = true;
			}
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? [ __( 'Paid photo orders were kept because they are bookkeeping records.', 'slashphoto' ) ] : [],
			'done'           => true,
		];
	}

	/**
	 * Exports visitor log entries (email gates, terms acceptances).
	 *
	 * @param string $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public static function export_visitors( $email ): array {
		$labels = [
			'email' => __( 'Gave email address to open the gallery', 'slashphoto' ),
			'terms' => __( 'Accepted the gallery terms', 'slashphoto' ),
		];
		$data   = [];
		foreach ( VisitorLog::for_person( (string) $email ) as $row ) {
			$data[] = [
				'group_id'    => 'slashphoto-visitors',
				'group_label' => __( 'Photo gallery visitor log', 'slashphoto' ),
				'item_id'     => 'slashphoto-visitor-' . $row['id'],
				'data'        => [
					[
						'name'  => __( 'Entry', 'slashphoto' ),
						'value' => $labels[ $row['type'] ] ?? $row['type'],
					],
					[
						'name'  => __( 'Gallery', 'slashphoto' ),
						'value' => get_the_title( (int) $row['gallery_id'] ),
					],
					[
						'name'  => __( 'Email', 'slashphoto' ),
						'value' => (string) $row['email'],
					],
					[
						'name'  => __( 'Date', 'slashphoto' ),
						'value' => $row['created_at'] . ' UTC',
					],
				],
			];
		}//end foreach
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erases visitor log entries.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_visitors( $email ): array {
		return [
			'items_removed'  => VisitorLog::delete_person( (string) $email ) > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
