<?php
/**
 * Anonymous view counts.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Visitors;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Schema;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Counts gallery and photo views per day. The browser reports a view with
 * a small request (so cached pages count too); the site adds one to that
 * day's total and keeps nothing else: no IP address, no cookie, no user
 * agent, no session. Views by people who can edit the gallery and by
 * obvious robots are not counted.
 */
final class Stats {

	public const MODULE = '@slashphoto/stats';

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Constructor.
	 *
	 * @param Access $access Access rules.
	 */
	public function __construct( Access $access ) {
		$this->access = $access;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'init', [ $this, 'register_module' ] );
		add_filter( 'script_module_data_' . self::MODULE, [ $this, 'module_data' ] );
	}

	/**
	 * Whether counting is switched on.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'stats_enabled' );
	}

	/**
	 * Registers the browser module.
	 */
	public function register_module(): void {
		wp_register_script_module( self::MODULE, SLASHPHOTO_URL . 'assets/js/stats.js', [], \SlashPhoto\Frontend\Assets::version( 'assets/js/stats.js' ) );
	}

	/**
	 * Data for the module.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public function module_data( $data ) {
		return array_merge( (array) $data, [ 'url' => esc_url_raw( rest_url( 'slashphoto/v1/stats' ) ) ] );
	}

	/**
	 * POST /slashphoto/v1/stats { gallery, image? }.
	 */
	public function register_routes(): void {
		register_rest_route(
			'slashphoto/v1',
			'/stats',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'count' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'gallery' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
					'image'   => [
						'type'    => 'integer',
						'minimum' => 0,
					],
				],
			]
		);
	}

	/**
	 * Counts one view. Always answers 204, so the answer says nothing about
	 * whether a gallery exists.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function count( WP_REST_Request $request ): WP_REST_Response {
		$gallery = get_post( (int) $request['gallery'] );
		$image   = (int) $request['image'];
		$valid   = self::enabled()
			&& $gallery
			&& PostType::POST_TYPE === $gallery->post_type
			&& 'publish' === $gallery->post_status
			&& $this->access->can_view( $gallery )
			&& ! $this->access->is_editor( $gallery )
			&& ! self::is_robot( (string) $request->get_header( 'user_agent' ) )
			&& ( ! $image || ( 'attachment' === get_post_type( $image ) && (int) get_post_field( 'post_parent', $image ) === $gallery->ID ) );
		if ( $valid ) {
			self::add( $gallery->ID, $image );
		}
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Adds one view to today's total.
	 *
	 * @param int $gallery_id Gallery.
	 * @param int $image_id   Photo, or 0 for the gallery page.
	 */
	public static function add( int $gallery_id, int $image_id = 0 ): void {
		global $wpdb;
		$table = Schema::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, atomic increment.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (day, gallery_id, image_id, views) VALUES (%s, %d, %d, 1) ON DUPLICATE KEY UPDATE views = views + 1", wp_date( 'Y-m-d' ), $gallery_id, $image_id ) );
	}

	/**
	 * Whether a user agent is an obvious robot. The user agent is only read
	 * here, never stored.
	 *
	 * @param string $user_agent User agent.
	 */
	public static function is_robot( string $user_agent ): bool {
		return '' === $user_agent || (bool) preg_match( '/bot|crawl|spider|slurp|preview|fetch|headless|lighthouse|monitor|curl|wget|python|httpclient/i', $user_agent );
	}

	/**
	 * Views per gallery since a day.
	 *
	 * @param string $since First day (Y-m-d).
	 * @param int    $limit Rows.
	 * @return array<int, array{gallery_id: int, page_views: int, photo_views: int}>
	 */
	public static function galleries( string $since, int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT gallery_id, SUM(CASE WHEN image_id = 0 THEN views ELSE 0 END) AS page_views, SUM(CASE WHEN image_id > 0 THEN views ELSE 0 END) AS photo_views FROM {$table} WHERE day >= %s GROUP BY gallery_id ORDER BY page_views DESC, photo_views DESC LIMIT %d", $since, $limit ), ARRAY_A );
		return array_map( static fn( array $row ): array => array_map( 'intval', $row ), $rows );
	}

	/**
	 * Most viewed photos since a day.
	 *
	 * @param string $since First day (Y-m-d).
	 * @param int    $limit Rows.
	 * @return array<int, array{image_id: int, gallery_id: int, views: int}>
	 */
	public static function photos( string $since, int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT image_id, gallery_id, SUM(views) AS views FROM {$table} WHERE day >= %s AND image_id > 0 GROUP BY image_id, gallery_id ORDER BY views DESC LIMIT %d", $since, $limit ), ARRAY_A );
		return array_map( static fn( array $row ): array => array_map( 'intval', $row ), $rows );
	}

	/**
	 * Total page views per day since a day.
	 *
	 * @param string $since First day (Y-m-d).
	 * @return array<string, int> Day → views.
	 */
	public static function days( string $since ): array {
		global $wpdb;
		$table = Schema::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) AS views FROM {$table} WHERE day >= %s AND image_id = 0 GROUP BY day ORDER BY day", $since ), ARRAY_A );
		return array_combine( array_column( $rows, 'day' ), array_map( 'intval', array_column( $rows, 'views' ) ) );
	}
}
