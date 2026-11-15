<?php
/**
 * Chunked uploads of full-size originals.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\ChunkedUploads;

final class ChunkedUploadTest extends AccessTestCase {

	private function call( string $method, string $route, $body = null, array $query = [] ) {
		$request = new \WP_REST_Request( $method, '/slashphoto/v1/' . $route );
		foreach ( $query as $key => $value ) {
			$request->set_query_params( array_merge( $request->get_query_params(), [ $key => $value ] ) );
		}
		if ( is_array( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		} elseif ( is_string( $body ) ) {
			$request->set_header( 'Content-Type', 'application/octet-stream' );
			$request->set_body( $body );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function send( int $gallery, string $name, string $bytes, int $chunk = 4000 ) {
		$start = $this->call(
			'POST',
			"galleries/{$gallery}/uploads",
			[
				'name' => $name,
				'size' => strlen( $bytes ),
			]
		);
		$this->assertSame( 201, $start->get_status(), wp_json_encode( $start->get_data() ) );
		$upload = $start->get_data()['upload'];
		$total  = strlen( $bytes );
		for ( $offset = 0; $offset < $total; $offset += $chunk ) {
			$response = $this->call( 'POST', "uploads/{$upload}", substr( $bytes, $offset, $chunk ), [ 'offset' => $offset ] );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		return [ $upload, $this->call( 'POST', "uploads/{$upload}/complete" ) ];
	}

	public function test_file_arrives_in_pieces_and_becomes_a_gallery_image(): void {
		$gallery = $this->gallery();
		$this->as_user( 'editor' );
		$bytes = (string) file_get_contents( TestImages::jpeg( 1600, 1000 ) );

		[ $upload, $done ] = $this->send( $gallery, 'DSC_0042.JPG', $bytes );
		$this->assertSame( 201, $done->get_status(), wp_json_encode( $done->get_data() ) );

		$id = $done->get_data()['id'];
		$this->assertSame( $gallery, wp_get_post_parent_id( $id ) );
		$this->assertSame( 'DSC_0042', get_the_title( $id ), 'Title without the random suffix.' );
		$this->assertStringContainsString( '/slashphoto/', get_attached_file( $id ) );
		$this->assertSame( strlen( $bytes ), filesize( wp_get_original_image_path( $id ) ), 'The original is kept byte for byte.' );
		$this->assertFileDoesNotExist( ChunkedUploads::dir() . '/' . $upload . '.part' );

		// Completing twice does not import twice.
		$this->assertSame( 404, $this->call( 'POST', "uploads/{$upload}/complete" )->get_status() );
	}

	public function test_out_of_order_chunks_are_refused_with_the_received_size(): void {
		$gallery = $this->gallery();
		$this->as_user( 'editor' );
		$start  = $this->call(
			'POST',
			"galleries/{$gallery}/uploads",
			[
				'name' => 'a.jpg',
				'size' => 100,
			]
		);
		$upload = $start->get_data()['upload'];
		$this->call( 'POST', "uploads/{$upload}", str_repeat( 'x', 40 ), [ 'offset' => 0 ] );

		$wrong = $this->call( 'POST', "uploads/{$upload}", str_repeat( 'x', 40 ), [ 'offset' => 0 ] );
		$this->assertSame( 409, $wrong->get_status() );
		$this->assertSame( 40, $wrong->get_data()['data']['received'] );

		$too_much = $this->call( 'POST', "uploads/{$upload}", str_repeat( 'x', 80 ), [ 'offset' => 40 ] );
		$this->assertSame( 400, $too_much->get_status() );
		$this->assertSame( 409, $this->call( 'POST', "uploads/{$upload}/complete" )->get_status() );

		$this->assertSame( 200, $this->call( 'DELETE', "uploads/{$upload}" )->get_status() );
		$this->assertFileDoesNotExist( ChunkedUploads::dir() . '/' . $upload . '.part' );
	}

	public function test_only_the_uploader_with_edit_rights_can_continue(): void {
		$gallery = $this->gallery();
		$this->as_user( 'editor' );
		$upload = $this->call(
			'POST',
			"galleries/{$gallery}/uploads",
			[
				'name' => 'a.jpg',
				'size' => 10,
			]
		)->get_data()['upload'];

		$this->as_user( 'editor' );
		$this->assertSame( 404, $this->call( 'POST', "uploads/{$upload}", 'xxxxx', [ 'offset' => 0 ] )->get_status() );

		$this->as_user( 'slashphoto_client' );
		$this->assertSame(
			403,
			$this->call(
				'POST',
				"galleries/{$gallery}/uploads",
				[
					'name' => 'a.jpg',
					'size' => 10,
				]
			)->get_status()
		);

		$this->as_anonymous();
		$this->assertSame(
			401,
			$this->call(
				'POST',
				"galleries/{$gallery}/uploads",
				[
					'name' => 'a.jpg',
					'size' => 10,
				]
			)->get_status()
		);
	}

	public function test_file_types_are_checked(): void {
		$gallery = $this->gallery();
		$this->as_user( 'editor' );

		$php = $this->call(
			'POST',
			"galleries/{$gallery}/uploads",
			[
				'name' => 'shell.php',
				'size' => 10,
			]
		);
		$this->assertSame( 415, $php->get_status() );

		$raw = $this->call(
			'POST',
			"galleries/{$gallery}/uploads",
			[
				'name' => 'DSC_1.ARW',
				'size' => 10,
			]
		);
		$this->assertSame( 415, $raw->get_status() );
		$this->assertSame( 'slashphoto_needs_snapotter', $raw->get_data()['code'] );

		// A non-image renamed to .jpg is rejected when it is imported.
		[ , $done ] = $this->send( $gallery, 'fake.jpg', str_repeat( 'not an image ', 50 ) );
		$this->assertSame( 422, $done->get_status() );
		$this->assertSame( [], get_children( [ 'post_parent' => $gallery ] ) );
	}

	public function test_stale_parts_are_cleaned_up(): void {
		$dir   = ChunkedUploads::dir();
		$stale = $dir . '/' . str_repeat( 'a', 32 ) . '.part';
		$fresh = $dir . '/' . str_repeat( 'b', 32 ) . '.part';
		file_put_contents( $stale, 'x' );
		file_put_contents( $fresh, 'x' );
		touch( $stale, time() - 2 * DAY_IN_SECONDS );

		ChunkedUploads::cleanup();
		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $fresh );
		unlink( $fresh );
		$this->assertFileExists( $dir . '/.htaccess' );
	}

	public function test_chunk_size_fits_the_request_limit(): void {
		$this->assertGreaterThanOrEqual( ChunkedUploads::MIN_CHUNK, ChunkedUploads::chunk_size() );
		$this->assertLessThanOrEqual( ChunkedUploads::MAX_CHUNK, ChunkedUploads::chunk_size() );
	}
}
