import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Uses "demo-email" (email request + terms), "demo-members" (any logged-in
// account) and "demo-shop" from `npm run seed`.

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

async function logIn( page, baseURL, user, password ) {
	await page.context().addCookies( [ { name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', url: baseURL } ] );
	const response = await page.request.post( '/wp-login.php', { form: { log: user, pwd: password, testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' } } );
	expect( response.ok() ).toBe( true );
}

test( 'email request, then terms, then the photos; both are logged', async ( { page, baseURL }, testInfo ) => {
	const address = `e2e-${ testInfo.project.name }-${ Date.now() }@example.org`;
	await page.goto( '/galleries/demo-email/' );
	await expect( page.locator( '.slashphoto-grid' ) ).toHaveCount( 0 );
	const gate = page.locator( 'form.slashphoto-email-gate' );
	await expect( gate ).toBeVisible();
	await gate.getByLabel( 'Email', { exact: true } ).fill( address );
	await gate.getByLabel( /I agree that the photographer keeps my email address/ ).check();
	await gate.getByRole( 'button', { name: 'Show the photos' } ).click();

	const terms = page.locator( 'form.slashphoto-terms' );
	await expect( terms ).toContainText( 'The photos are for personal use only.' );
	await expect( terms.getByLabel( 'Your email address' ) ).toHaveCount( 0 );
	await terms.getByLabel( 'I have read and accept these terms' ).check();
	await terms.getByRole( 'button', { name: 'Show the photos' } ).click();
	await expect( page.locator( '.slashphoto-grid .slashphoto-item' ) ).toHaveCount( 2 );

	const log = JSON.parse( wpEval( `echo wp_json_encode( array_column( SlashPhoto\\Visitors\\VisitorLog::query( [ "search" => "${ address }" ] )["rows"], "type" ) );` ) );
	expect( log.sort() ).toEqual( [ 'email', 'terms' ] );

	if ( 'desktop' === testInfo.project.name ) {
		await logIn( page, baseURL, 'admin', 'password' );
		await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-visitors' );
		await expect( page.locator( '#slashphoto-visitor-log' ) ).toContainText( address );
		await expect( page.locator( '#slashphoto-visitor-log' ) ).toContainText( 'Accepted terms' );
		const csv = await page.request.get( await page.getByRole( 'link', { name: 'Download CSV' } ).getAttribute( 'href' ) );
		expect( csv.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		expect( await csv.text() ).toContain( address );
	}
} );

test( 'logged-in mode: visitors log in, any account sees the photos', async ( { page, baseURL } ) => {
	await page.goto( '/galleries/demo-members/' );
	await expect( page.locator( '.slashphoto-grid' ) ).toHaveCount( 0 );
	await logIn( page, baseURL, 'demo-client', 'demo-client' );
	await page.goto( '/galleries/demo-members/' );
	await expect( page.locator( '.slashphoto-grid .slashphoto-item' ) ).toHaveCount( 1 );
} );

test( 'views are counted anonymously and shown to the photographer', async ( { page, baseURL, isMobile } ) => {
	test.skip( isMobile, 'Counted once on desktop.' );
	const gallery = Number( wpEval( 'echo get_page_by_path( "demo-shop", OBJECT, "slashphoto_gallery" )->ID;' ) );
	const before = Number( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT SUM(views) FROM {$wpdb->prefix}slashphoto_stats WHERE gallery_id = ${ gallery } AND image_id = 0" );` ) );

	const beacons = [];
	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'slashphoto/v1/stats' ) ) {
			beacons.push( request.postData() );
		}
	} );
	await page.goto( '/galleries/demo-shop/' );
	await page.locator( '.slashphoto-link' ).first().click();
	await expect( page.locator( 'dialog.slashphoto-lightbox' ) ).toBeVisible();
	await expect.poll( () => beacons.length ).toBe( 2 );
	expect( beacons.join( '&' ) ).not.toMatch( /mail|user|ip=/i );
	await expect.poll( () => Number( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT SUM(views) FROM {$wpdb->prefix}slashphoto_stats WHERE gallery_id = ${ gallery } AND image_id = 0" );` ) ) ).toBe( before + 1 );

	await logIn( page, baseURL, 'admin', 'password' );
	await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-stats' );
	await expect( page.locator( '#slashphoto-viewed-galleries' ) ).toContainText( 'Demo: Shop' );
	await page.goto( '/galleries/demo-shop/' );
	await page.waitForLoadState( 'networkidle' );
	expect( Number( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT SUM(views) FROM {$wpdb->prefix}slashphoto_stats WHERE gallery_id = ${ gallery } AND image_id = 0" );` ) ) ).toBe( before + 1 );
} );
