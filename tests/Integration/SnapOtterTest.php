<?php
/**
 * SnapOtter connection and processing (simulated server).
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\Importer;
use SlashPhoto\Settings;
use SlashPhoto\SnapOtter\Connection;

final class SnapOtterTest extends AccessTestCase {

	/** @var array<int, array{method: string, path: string, args: array}> */
	private array $calls = [];

	private bool $mfa = false;

	private bool $down = false;

	private string $download_url = '/api/v1/download/job-1/out.jpg';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'fake_server' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_server' ], 10 );
		delete_option( Connection::OPTION );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * A tiny SnapOtter 2.3 API.
	 */
	public function fake_server( $pre, $args, $url ) {
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$method = strtoupper( $args['method'] ?? 'GET' );
		$auth   = (string) ( $args['headers']['Authorization'] ?? '' );

		$args['_url']  = $url;
		$this->calls[] = compact( 'method', 'path', 'args' );
		if ( $this->down ) {
			return new \WP_Error( 'http_request_failed', 'Connection refused' );
		}

		$json = static fn( int $code, array $body ): array => [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];

		switch ( "{$method} {$path}" ) {
			case 'POST /api/auth/login':
				$body = json_decode( (string) $args['body'], true );
				if ( 'admin' !== $body['username'] || 'secret' !== $body['password'] ) {
					return $json( 401, [ 'error' => 'Invalid credentials' ] );
				}
				return $json(
					200,
					$this->mfa ? [
						'requiresMfa' => true,
						'mfaToken'    => 'm',
					] : [ 'token' => 'session-token' ]
				);
			case 'POST /api/auth/logout':
				return $json( 200, [] );
			case 'POST /api/v1/api-keys':
				return 'Bearer session-token' === $auth ? $json(
					201,
					[
						'key'  => 'si_' . str_repeat( 'ab', 48 ),
						'id'   => 'key-1',
						'name' => 'SlashPhoto',
					]
				) : $json( 401, [ 'error' => 'no' ] );
			case 'DELETE /api/v1/api-keys/key-1':
				return $json( 200, [ 'deleted' => true ] );
			case 'GET /api/v1/health':
				return $json(
					200,
					[
						'status'  => 'healthy',
						'version' => '2.3.0',
					]
				);
			case 'GET /api/v1/pipeline/tools':
				return str_starts_with( $auth, 'Bearer si_' ) ? $json( 200, [ 'toolIds' => [ 'optimize-for-web' ] ] ) : $json( 401, [ 'error' => 'Unauthorized' ] );
			case 'POST /api/v1/pipeline/execute':
				return $json(
					200,
					[
						'jobId'       => 'job-1',
						'downloadUrl' => $this->download_url,
					]
				);
			case 'GET /api/v1/download/job-1/out.jpg':
				copy( TestImages::jpeg( 2560, 1707 ), $args['filename'] );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => $args['filename'],
				];
		}
		return $json( 404, [ 'error' => 'Not found' ] );
	}

	private function paths(): array {
		return array_map( static fn( array $call ): string => $call['method'] . ' ' . $call['path'], $this->calls );
	}

	public function test_login_creates_an_api_key_and_stores_no_password(): void {
		$this->assertTrue( Connection::connect_with_login( 'http://snap.local:1349/', 'admin', 'secret' ) );

		$this->assertContains( 'POST /api/v1/api-keys', $this->paths() );
		$this->assertContains( 'POST /api/auth/logout', $this->paths() );
		$this->assertSame( 'http://snap.local:1349', Connection::url() );
		$this->assertSame( 'si_' . str_repeat( 'ab', 48 ), Connection::key() );

		$stored = (string) wp_json_encode( get_option( Connection::OPTION ) );
		$this->assertStringNotContainsString( 'secret', $stored, 'The password is never stored.' );
		$this->assertStringNotContainsString( str_repeat( 'ab', 48 ), $stored, 'The key is stored encrypted.' );
		$this->assertFalse( wp_load_alloptions()[ Connection::OPTION ] ?? false, 'Not autoloaded.' );

		Connection::disconnect();
		$this->assertContains( 'DELETE /api/v1/api-keys/key-1', $this->paths() );
		$this->assertFalse( Connection::is_configured() );
	}

	public function test_login_errors_are_explained(): void {
		$this->assertSame( 'slashphoto_snapotter_login', Connection::connect_with_login( 'http://snap.local', 'admin', 'wrong' )->get_error_code() );
		$this->mfa = true;
		$this->assertSame( 'slashphoto_snapotter_mfa', Connection::connect_with_login( 'http://snap.local', 'admin', 'secret' )->get_error_code() );
		$this->assertSame( 'slashphoto_snapotter_url', Connection::connect_with_login( 'ftp://snap.local', 'admin', 'secret' )->get_error_code() );
		$this->assertFalse( Connection::is_configured() );
	}

	public function test_api_key_is_checked_before_it_is_saved(): void {
		$this->assertSame( 'slashphoto_snapotter_key', Connection::connect_with_key( 'http://snap.local', 'not a key!' )->get_error_code() );
		$this->assertTrue( Connection::connect_with_key( 'http://snap.local', 'si_' . str_repeat( 'cd', 48 ) ) );
		$this->assertSame( '', Connection::data()['key_id'], 'A pasted key is not deleted on disconnect.' );
	}

	public function test_raw_files_are_converted_and_the_original_kept(): void {
		Connection::connect_with_key( 'http://snap.local', 'si_' . str_repeat( 'cd', 48 ) );
		$gallery = $this->gallery();
		$this->assertContains( 'arw', Importer::allowed_extensions() );

		$raw = wp_tempnam( 'raw' );
		file_put_contents( $raw, random_bytes( 5000 ) );
		$id = Importer::import( $raw, 'DSC_0001.ARW', $gallery );

		$this->assertIsInt( $id );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 2560, $meta['width'], 'No second copy of the SnapOtter display copy.' );
		$this->assertMatchesRegularExpression( '#^o[a-f0-9]{24}/DSC_0001-[a-f0-9]{12}\.arw$#', $meta['original_image'] );
		$this->assertSame( 5000, filesize( wp_get_original_image_path( $id ) ) );
		$this->assertStringEndsWith( '.jpg', get_attached_file( $id ) );
		$this->assertSame( 'DSC_0001', get_the_title( $id ) );
		$this->assertSame( 'snapotter', get_post_meta( $id, Importer::META_MADE_BY, true ) );
		$this->assertFileDoesNotExist( $raw );

		$execute  = array_values( array_filter( $this->calls, static fn( array $call ): bool => '/api/v1/pipeline/execute' === $call['path'] ) )[0];
		$pipeline = json_decode( $execute['args']['body']['pipeline'], true );
		$this->assertSame( 'optimize-for-web', $pipeline['steps'][0]['toolId'] );
		$this->assertFalse( $pipeline['steps'][0]['settings']['stripMetadata'], 'Colour profile and EXIF are kept.' );
		$this->assertSame( 2560, $pipeline['steps'][0]['settings']['maxWidth'] );
	}

	public function test_web_formats_fall_back_to_wordpress_when_snapotter_is_down(): void {
		Connection::connect_with_key( 'http://snap.local', 'si_' . str_repeat( 'cd', 48 ) );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'snapotter_mode' => 'all' ] ) );
		$gallery    = $this->gallery();
		$this->down = true;

		$jpeg = wp_tempnam( 'jpg' );
		copy( TestImages::jpeg( 1200, 800 ), $jpeg );
		$id = Importer::import( $jpeg, 'photo.jpg', $gallery );
		$this->assertIsInt( $id );
		$this->assertSame( 1198, wp_get_attachment_metadata( $id )['width'] );
		$this->assertSame( '', get_post_meta( $id, Importer::META_MADE_BY, true ) );

		$raw = wp_tempnam( 'raw' );
		file_put_contents( $raw, 'raw' );
		$this->assertSame( 'slashphoto_snapotter_unreachable', Importer::import( $raw, 'x.nef', $gallery )->get_error_code() );
	}

	public function test_the_key_is_never_sent_to_another_server(): void {
		Connection::connect_with_key( 'http://snap.local', 'si_' . str_repeat( 'cd', 48 ) );
		$this->download_url = 'http://evil.example/api/v1/download/job-1/out.jpg';
		$raw                = wp_tempnam( 'raw' );
		file_put_contents( $raw, 'raw' );

		$this->assertSame( 'slashphoto_snapotter_response', Importer::import( $raw, 'x.nef', $this->gallery() )->get_error_code() );
		$hosts = array_map( static fn( array $call ): string => (string) wp_parse_url( $call['args']['_url'] ?? '', PHP_URL_HOST ), $this->calls );
		$this->assertNotContains( 'evil.example', $hosts );
		$this->assertNotContains( 'GET /api/v1/download/job-1/out.jpg', array_slice( $this->paths(), -1 ) );
	}

	public function test_heavy_mode_only_sends_large_images(): void {
		Connection::connect_with_key( 'http://snap.local', 'si_' . str_repeat( 'cd', 48 ) );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'snapotter_min_mp' => 2 ] ) );

		$this->assertFalse( Importer::use_snapotter( TestImages::jpeg( 1200, 800 ), 'jpg' ) );
		$this->assertTrue( Importer::use_snapotter( TestImages::jpeg( 2000, 1000 ), 'jpg' ) );
		$this->assertTrue( Importer::use_snapotter( '/nonexistent', 'heic' ) );

		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'snapotter_mode' => 'off' ] ) );
		$this->assertFalse( Importer::use_snapotter( '/nonexistent', 'heic' ) );
		$this->assertNotContains( 'heic', Importer::allowed_extensions() );
	}
}
