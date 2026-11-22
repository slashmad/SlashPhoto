<?php
/**
 * Imports photos dropped into a folder on the server.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Media\Originals;
use SlashPhoto\Settings;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The import folder ("inbox") lets photographers copy originals with SFTP,
 * FTP, a synced folder or a script instead of the browser, using the layout
 * "slashphoto-inbox/<gallery ID or slug>/<photos>".
 *
 * - A sub-folder named after an existing gallery (its ID or slug) adds the
 *   photos to that gallery. Any other name creates a new gallery with that
 *   title as an unpublished draft, so nothing becomes public by itself.
 * - Files are imported like browser uploads (display copy, private
 *   original, watermark, SnapOtter) and are moved out of the folder. Files
 *   that cannot be imported go to "_failed/<folder>/" with the reason in the
 *   last-run report.
 * - Files changed in the last minute are left for the next run, so copies
 *   still in progress are not imported half-way.
 * - The folder is wp-content/uploads/slashphoto-inbox (protected against web
 *   access) or the path in the SLASHPHOTO_INBOX_DIR constant, which can be
 *   outside the web root.
 * - It runs every five minutes with WP-Cron when switched on, from the
 *   settings screen, and with `wp slashphoto inbox`.
 */
final class Inbox {

	public const CRON   = 'slashphoto_inbox';
	public const STATUS = 'slashphoto_inbox_status';
	public const FAILED = '_failed';

	/** Seconds a file must be unchanged before it is imported. */
	public const SETTLE = MINUTE_IN_SECONDS;

	private const LOCK = 'slashphoto_inbox_running';

	public const ACTION = 'slashphoto_inbox_run';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes is the point.
		add_action( self::CRON, [ self::class, 'run_cron' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_run' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'slashphoto inbox', [ self::class, 'cli' ] );
		}
	}

	/**
	 * Adds a five-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['slashphoto_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'slashphoto' ),
		];
		return $schedules;
	}

	/**
	 * Keeps the schedule in step with the setting.
	 */
	public static function schedule(): void {
		$next = wp_next_scheduled( self::CRON );
		if ( Settings::get( 'inbox_enabled' ) && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'slashphoto_five_minutes', self::CRON );
		} elseif ( ! Settings::get( 'inbox_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * The inbox folder.
	 */
	public static function dir(): string {
		if ( defined( 'SLASHPHOTO_INBOX_DIR' ) && is_string( SLASHPHOTO_INBOX_DIR ) && '' !== SLASHPHOTO_INBOX_DIR ) {
			return untrailingslashit( wp_normalize_path( SLASHPHOTO_INBOX_DIR ) );
		}
		return untrailingslashit( wp_normalize_path( (string) wp_get_upload_dir()['basedir'] ) ) . '/slashphoto-inbox';
	}

	/**
	 * Creates the folder with its web-access protection.
	 */
	public static function prepare(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			Originals::protect( $dir );
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Cron entry point.
	 */
	public static function run_cron(): void {
		if ( Settings::get( 'inbox_enabled' ) ) {
			self::run( 20.0 );
		}
	}

	/**
	 * Imports what is ready, within a time budget, and stores a report.
	 *
	 * @param float $budget Seconds.
	 * @param bool  $dry    Only report what would be imported.
	 * @return array{time: int, imported: int, waiting: int, ignored: int, galleries: array<int, int>, failed: array<int, array{file: string, message: string}>, error: string}
	 */
	public static function run( float $budget, bool $dry = false ): array {
		$report = [
			'time'      => time(),
			'imported'  => 0,
			'waiting'   => 0,
			'ignored'   => 0,
			'galleries' => [],
			'failed'    => [],
			'error'     => '',
		];
		if ( ! $dry && get_transient( self::LOCK ) ) {
			$report['error'] = __( 'An import is already running.', 'slashphoto' );
			return $report;
		}
		if ( ! self::prepare() ) {
			$report['error'] = __( 'The import folder cannot be created or written.', 'slashphoto' );
			return self::save( $report, $dry );
		}
		if ( ! $dry ) {
			set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$start = microtime( true );
		try {
			foreach ( self::folders() as $folder ) {
				$files = self::files( $folder );
				if ( ! $files ) {
					continue;
				}
				$gallery = $dry ? self::find_gallery( wp_basename( $folder ) ) : self::gallery_for( wp_basename( $folder ) );
				foreach ( $files as $file ) {
					$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
					if ( ! in_array( $ext, array_merge( Importer::WEB_TYPES, Importer::SNAPOTTER_TYPES ), true ) ) {
						// Sidecars (.xmp), notes and other files stay where they are.
						++$report['ignored'];
						continue;
					}
					if ( time() - (int) filemtime( $file ) < self::SETTLE ) {
						++$report['waiting'];
						continue;
					}
					if ( ! $dry && microtime( true ) - $start > $budget && ( $report['imported'] || $report['failed'] ) ) {
						++$report['waiting'];
						continue;
					}
					if ( $dry ) {
						++$report['imported'];
						continue;
					}
					$result = $gallery instanceof WP_Post ? self::import( $file, $gallery ) : $gallery;
					if ( is_wp_error( $result ) ) {
						$report['failed'][] = [
							'file'    => wp_basename( $folder ) . '/' . wp_basename( $file ),
							'message' => $result->get_error_message(),
						];
						self::park( $folder, $file );
						continue;
					}
					++$report['imported'];
					$report['galleries'][ $gallery->ID ] = ( $report['galleries'][ $gallery->ID ] ?? 0 ) + 1;
				}//end foreach
			}//end foreach
		} finally {
			if ( ! $dry ) {
				delete_transient( self::LOCK );
			}
		}//end try
		return self::save( $report, $dry );
	}

	/**
	 * Imports a copy, so the file in the inbox is only removed after the
	 * photo is safely in the gallery (the importer consumes its input, also
	 * when it fails).
	 *
	 * @param string  $file    File in the inbox.
	 * @param WP_Post $gallery Gallery.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function import( string $file, WP_Post $gallery ) {
		$copy = untrailingslashit( get_temp_dir() ) . '/slashphoto-inbox-' . bin2hex( random_bytes( 8 ) ) . '-' . sanitize_file_name( wp_basename( $file ) );
		if ( ! copy( $file, $copy ) ) {
			return new WP_Error( 'slashphoto_inbox_read', __( 'The file could not be read.', 'slashphoto' ) );
		}
		$result = Importer::import( $copy, wp_basename( $file ), $gallery->ID );
		if ( is_file( $copy ) ) {
			wp_delete_file( $copy );
		}
		if ( ! is_wp_error( $result ) ) {
			wp_delete_file( $file );
		}
		return $result;
	}

	/**
	 * Stores the report of a real run.
	 *
	 * @param array $report Report.
	 * @param bool  $dry    Dry run.
	 */
	private static function save( array $report, bool $dry ): array {
		if ( ! $dry ) {
			update_option( self::STATUS, $report, false );
		}
		return $report;
	}

	/**
	 * Gallery folders in the inbox, by name.
	 *
	 * @return string[]
	 */
	private static function folders(): array {
		$folders = array_filter( (array) glob( self::dir() . '/*', GLOB_ONLYDIR ), static fn( $dir ): bool => is_string( $dir ) && ! str_starts_with( wp_basename( $dir ), '_' ) && ! str_starts_with( wp_basename( $dir ), '.' ) );
		natcasesort( $folders );
		return array_values( $folders );
	}

	/**
	 * Image files in a folder, in natural name order (camera numbering).
	 *
	 * @param string $folder Folder.
	 * @return string[]
	 */
	private static function files( string $folder ): array {
		$files = [];
		foreach ( (array) scandir( $folder ) as $name ) {
			$path = $folder . '/' . $name;
			if ( is_string( $name ) && '' !== $name && '.' !== $name[0] && 'index.php' !== $name && is_file( $path ) && ! is_link( $path ) ) {
				$files[] = $path;
			}
		}
		natcasesort( $files );
		return array_values( $files );
	}

	/**
	 * Gallery a folder name refers to: its ID or slug.
	 *
	 * @param string $name Folder name.
	 */
	public static function find_gallery( string $name ): ?WP_Post {
		if ( ctype_digit( $name ) ) {
			$post = get_post( (int) $name );
			if ( $post && PostType::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ) {
				return $post;
			}
		}
		$found = get_posts(
			[
				'post_type'        => PostType::POST_TYPE,
				'name'             => sanitize_title( $name ),
				'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts'      => 1,
				'suppress_filters' => true,
			]
		);
		return $found ? $found[0] : null;
	}

	/**
	 * The gallery for a folder, creating an unpublished one when needed.
	 *
	 * @param string $name Folder name.
	 * @return WP_Post|WP_Error
	 */
	private static function gallery_for( string $name ) {
		$gallery = self::find_gallery( $name );
		if ( $gallery ) {
			return $gallery;
		}
		$id = wp_insert_post(
			[
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( str_replace( [ '-', '_' ], ' ', $name ) ),
				'post_name'   => sanitize_title( $name ),
				'post_author' => self::owner(),
			],
			true
		);
		return is_wp_error( $id ) ? $id : get_post( $id );
	}

	/**
	 * Owner of galleries the import folder creates: whoever switched the
	 * automatic import on, else the user running the import.
	 */
	private static function owner(): int {
		$user = (int) Settings::get( 'inbox_user' );
		return $user && get_userdata( $user ) ? $user : get_current_user_id();
	}

	/**
	 * Moves a file that could not be imported out of the way.
	 *
	 * @param string $folder Gallery folder.
	 * @param string $file   File.
	 */
	private static function park( string $folder, string $file ): void {
		if ( ! is_file( $file ) ) {
			return;
		}
		$target = self::dir() . '/' . self::FAILED . '/' . wp_basename( $folder );
		if ( ! wp_mkdir_p( $target ) ) {
			return;
		}
		// Keep the exact name (wp_unique_filename would lower-case ".ARW").
		$name = wp_basename( $file );
		$info = pathinfo( $name );
		$ext  = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$n    = 1;
		while ( file_exists( $target . '/' . $name ) ) {
			$name = $info['filename'] . '-' . $n . $ext;
			++$n;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same file system.
		rename( $file, $target . '/' . $name );
	}

	/**
	 * "Import now" on the settings screen.
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		self::run( 20.0 );
		wp_safe_redirect(
			add_query_arg(
				[
					'tab'   => 'inbox',
					'inbox' => 'ran',
				],
				Settings::admin_url()
			),
			303
		);
		exit;
	}

	/**
	 * Imports photos from the import folder now.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only show what would be imported.
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public static function cli( array $args, array $assoc_args ): void {
		unset( $args );
		$dry    = ! empty( $assoc_args['dry-run'] );
		$report = self::run( $dry ? 0.0 : 3600.0, $dry );
		if ( '' !== $report['error'] ) {
			\WP_CLI::error( $report['error'] );
		}
		\WP_CLI::log( sprintf( '%s: %s', $dry ? 'Would import' : 'Imported', $report['imported'] ) );
		\WP_CLI::log( sprintf( 'Waiting (changed in the last minute): %d', $report['waiting'] ) );
		\WP_CLI::log( sprintf( 'Ignored (not photos): %d', $report['ignored'] ) );
		foreach ( $report['failed'] as $failure ) {
			\WP_CLI::warning( $failure['file'] . ': ' . $failure['message'] );
		}
		\WP_CLI::success( self::dir() );
	}
}
