<?php
/**
 * Opt-in expiry reminders for assigned client accounts.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Access\Access;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Reminds the gallery's assigned clients once per expiry date. */
final class ExpiryReminders {

	public const META = '_slashphoto_expiry_reminder';
	public const CRON = 'slashphoto_expiry_reminders';

	/**
	 * Uses the same inherited rules as the gallery page.
	 *
	 * @param Access $access The gallery's inherited access rules.
	 */
	public function __construct( private Access $access ) {}

	/** Registers scheduling and delivery hooks. */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::CRON, [ $this, 'run' ] );
		add_action( 'before_delete_post', [ $this, 'forget' ] );
	}

	/** Checks hourly so stores on low-traffic sites can still catch up. */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/** Finds published galleries with reminders enabled. */
	public function run(): void {
		$galleries = get_posts(
			[
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'meta_key'               => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only opt-in galleries.
				'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'update_post_term_cache' => false,
			]
		);
		foreach ( $galleries as $gallery ) {
			$this->notify( $gallery );
		}
	}

	/**
	 * Sends reminders within three days of expiry while the gallery is open.
	 *
	 * @param WP_Post  $gallery Gallery.
	 * @param int|null $now     Current Unix time.
	 * @return int Number of accepted messages.
	 */
	public function notify( WP_Post $gallery, ?int $now = null ): int {
		$now    = $now ?? time();
		$expiry = $this->access->expires_at( $gallery );
		if ( PostType::POST_TYPE !== $gallery->post_type || Access::MODE_CLIENTS !== Access::mode( $gallery ) || ! get_post_meta( $gallery->ID, self::META, true )
			|| ! $expiry || $expiry <= $now || $expiry - $now > 3 * DAY_IN_SECONDS || $this->access->link_only( $gallery ) ) {
			return 0;
		}
		foreach ( $this->access->chain( $gallery ) as $parent ) {
			if ( 'publish' !== $parent->post_status ) {
				return 0;
			}
		}
		$recipients = [];
		foreach ( Access::clients( $gallery ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user && is_email( $user->user_email ) && $this->access->account_allowed( $gallery, $id ) ) {
				$recipients[] = $user->user_email;
			}
		}
		if ( ! $recipients || ! $this->claim( $gallery->ID, $expiry ) ) {
			return 0;
		}

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $gallery ), true ), ENT_QUOTES, 'UTF-8' );
		$date  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry );
		/* translators: %s: gallery title. */
		$subject = sprintf( __( 'Gallery closing soon: %s', 'slashphoto' ), $title );
		/* translators: 1: gallery title, 2: date and time. */
		$body = sprintf( __( '%1$s is available until %2$s. Sign in with your account to open the gallery:', 'slashphoto' ), $title, $date ) . "\n\n" . get_permalink( $gallery );
		$sent = 0;
		foreach ( array_unique( $recipients ) as $email ) {
			if ( wp_mail( $email, $subject, $body ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Claims this expiry date before mailing, so retries cannot resend it.
	 *
	 * @param int $gallery_id Gallery.
	 * @param int $expiry     Expiry timestamp.
	 */
	private function claim( int $gallery_id, int $expiry ): bool {
		$key      = 'slashphoto_reminder_' . $gallery_id;
		$previous = get_option( $key, false );
		return false === $previous ? add_option( $key, $expiry, '', false )
			: (int) $previous !== $expiry && update_option( $key, $expiry, false );
	}

	/**
	 * Removes the per-gallery marker when the gallery is deleted.
	 *
	 * @param int $gallery_id Deleted post.
	 */
	public function forget( int $gallery_id ): void {
		if ( PostType::POST_TYPE === get_post_type( $gallery_id ) ) {
			delete_option( 'slashphoto_reminder_' . $gallery_id );
		}
	}
}
