import { execFileSync } from 'node:child_process';
import { statSync } from 'node:fs';
import { expect, test } from '@playwright/test';

// Full-size originals through the chunked uploader in the gallery editor.
// With SNAPOTTER_E2E_URL set (an address the WordPress container can reach,
// e.g. http://snapotter:1349), SnapOtter is connected through the settings
// page and a TIFF is converted too.

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

// A valid 1600x1000 JPEG padded with comment segments to about 12 MB, so it
// needs two chunks (8 MB each at most).
function bigJpeg() {
	return wpEval( `
		require_once WP_PLUGIN_DIR . '/slashphoto/tests/Integration/TestImages.php';
		$jpeg = file_get_contents( SlashPhoto\\Tests\\Integration\\TestImages::jpeg( 1600, 1000 ) );
		$pad  = '';
		for ( $i = 0; $i < 190; $i++ ) { $pad .= "\\xFF\\xFE" . pack( 'n', 65535 ) . str_repeat( 'x', 65533 ); }
		$dir = WP_PLUGIN_DIR . '/slashphoto/tests/e2e/.results';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/DSC_7777.jpg', substr( $jpeg, 0, 2 ) . $pad . substr( $jpeg, 2 ) );
		echo 'tests/e2e/.results/DSC_7777.jpg';
	` );
}

test.describe( 'uploads', () => {
	test.skip( ( { isMobile } ) => isMobile, 'Editor tests run on desktop only.' );

	let galleryId;

	test.beforeAll( () => {
		galleryId = wpEval( 'echo wp_insert_post( [ "post_type" => "slashphoto_gallery", "post_status" => "publish", "post_title" => "E2E upload" ] );' );
	} );

	test.afterAll( () => {
		wpEval( `foreach ( get_children( [ "post_parent" => ${ galleryId }, "post_type" => "attachment" ] ) as $a ) { wp_delete_attachment( $a->ID, true ); } wp_delete_post( ${ galleryId }, true );` );
	} );

	test.beforeEach( async ( { page, baseURL } ) => {
		await page.context().addCookies( [ { name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', url: baseURL } ] );
		const response = await page.request.post( '/wp-login.php', {
			form: { log: 'admin', pwd: 'password', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
		} );
		expect( response.ok() ).toBe( true );
	} );

	async function openPanel( page ) {
		await page.goto( `/wp-admin/post.php?post=${ galleryId }&action=edit` );
		await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
		} );
		const panel = page.locator( '.slashphoto-upload-panel' );
		const toggle = panel.getByRole( 'button', { name: 'Upload photos' } );
		if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
			await toggle.click();
		}
		return panel;
	}

	test( 'a large original arrives in chunks and is kept byte for byte', async ( { page } ) => {
		const file = bigJpeg();
		const size = statSync( file ).size;
		expect( size ).toBeGreaterThan( 8 * 1024 * 1024 );

		const chunks = [];
		page.on( 'request', ( request ) => {
			if ( /\/slashphoto\/v1\/uploads\/[a-f0-9]{32}(\?|%3F|&)/.test( request.url() ) || /rest_route=%2Fslashphoto%2Fv1%2Fuploads%2F[a-f0-9]{32}&/.test( request.url() ) ) {
				chunks.push( request.url() );
			}
		} );

		const panel = await openPanel( page );
		await panel.locator( 'input[type="file"]' ).setInputFiles( file );
		await expect( panel.getByText( 'Added', { exact: true } ) ).toBeVisible( { timeout: 120_000 } );
		await expect( panel.getByText( /1 image added/ ) ).toBeVisible();
		expect( chunks.length ).toBeGreaterThanOrEqual( 2 );

		const stored = JSON.parse(
			wpEval( `$a = get_children( [ "post_parent" => ${ galleryId }, "post_type" => "attachment" ] ); $a = reset( $a ); $m = wp_get_attachment_metadata( $a->ID ); echo wp_json_encode( [ "title" => $a->post_title, "original" => filesize( wp_get_original_image_path( $a->ID ) ), "width" => $m["width"], "folder" => dirname( $m["original_image"] ) ] );` )
		);
		expect( stored.title ).toBe( 'DSC_7777' );
		expect( stored.original ).toBe( size );
		expect( stored.width ).toBe( 1598 );
		expect( stored.folder ).toMatch( /^o[a-f0-9]{24}$/ );
	} );

	test( 'RAW files are refused without SnapOtter, with an explanation', async ( { page } ) => {
		test.skip( !! process.env.SNAPOTTER_E2E_URL, 'SnapOtter is connected in this run.' );
		await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-settings' );
		await expect( page.getByRole( 'heading', { name: 'SnapOtter connection' } ) ).toBeVisible();
		await expect( page.getByLabel( 'Server address' ) ).toBeVisible();

		const panel = await openPanel( page );
		await panel.locator( 'input[type="file"]' ).setInputFiles( { name: 'DSC_0001.ARW', mimeType: 'image/x-sony-arw', buffer: Buffer.from( 'raw' ) } );
		await expect( panel.getByText( /this file type cannot be added/ ) ).toBeVisible();
	} );

	test( 'SnapOtter: connect with login, convert a TIFF, disconnect', async ( { page } ) => {
		test.skip( ! process.env.SNAPOTTER_E2E_URL, 'Set SNAPOTTER_E2E_URL to test with a SnapOtter server.' );
		await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-settings' );
		await page.getByLabel( 'Server address' ).fill( process.env.SNAPOTTER_E2E_URL );
		await page.getByLabel( 'User name' ).fill( process.env.SNAPOTTER_E2E_USER || 'admin' );
		await page.getByLabel( 'Password', { exact: true } ).fill( process.env.SNAPOTTER_E2E_PASSWORD || 'admin' );
		await page.getByRole( 'button', { name: 'Connect with user name and password' } ).click();
		await expect( page.getByText( 'SnapOtter is connected and working.' ) ).toBeVisible();
		await expect( page.getByText( /Connected \(SnapOtter \d/ ) ).toBeVisible();
		expect( wpEval( 'echo wp_json_encode( get_option( "slashphoto_snapotter" ) );' ) ).not.toContain( process.env.SNAPOTTER_E2E_PASSWORD || 'admin"' );

		// A real baseline TIFF (uncompressed RGB, 2000x1500, about 9 MB), written by hand.
		const tiff = wpEval( `
			$w = 2000; $h = 1500;
			$e = static fn( $tag, $type, $count, $value ) => pack( 'vvV', $tag, $type, $count ) . ( 3 === $type && 1 === $count ? pack( 'vv', $value, 0 ) : pack( 'V', $value ) );
			$ifd = pack( 'v', 10 )
				. $e( 256, 4, 1, $w ) . $e( 257, 4, 1, $h ) . $e( 258, 3, 3, 134 ) . $e( 259, 3, 1, 1 ) . $e( 262, 3, 1, 2 )
				. $e( 273, 4, 1, 140 ) . $e( 277, 3, 1, 3 ) . $e( 278, 4, 1, $h ) . $e( 279, 4, 1, $w * $h * 3 ) . $e( 284, 3, 1, 1 )
				. pack( 'V', 0 );
			$dir = WP_PLUGIN_DIR . '/slashphoto/tests/e2e/.results'; wp_mkdir_p( $dir );
			file_put_contents( $dir . '/scan.tif', 'II' . pack( 'vV', 42, 8 ) . $ifd . pack( 'vvv', 8, 8, 8 ) . str_repeat( chr( 140 ) . chr( 90 ) . chr( 40 ), $w * $h ) );
			echo 'tests/e2e/.results/scan.tif';
		` );
		const panel = await openPanel( page );
		await panel.locator( 'input[type="file"]' ).setInputFiles( tiff );
		await expect( panel.getByText( 'Added', { exact: true } ) ).toBeVisible( { timeout: 120_000 } );
		const madeBy = wpEval( `$a = get_children( [ "post_parent" => ${ galleryId }, "post_type" => "attachment", "orderby" => "ID", "order" => "DESC" ] ); $a = reset( $a ); echo get_post_meta( $a->ID, "_slashphoto_display_copy", true ), " ", basename( wp_get_original_image_path( $a->ID ) );` );
		expect( madeBy ).toMatch( /^snapotter scan-[a-f0-9]{12}\.tif$/ );

		await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-settings' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await expect( page.getByText( 'SnapOtter was disconnected.' ) ).toBeVisible();
	} );
} );
