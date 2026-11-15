import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Uses the "demo-proofing" gallery and the demo-client account created by `npm run seed`.

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

const GALLERY = '/galleries/demo-proofing/';

test( 'visitors without an account keep favourites in their browser only', async ( { page } ) => {
	const sent = [];
	page.on( 'request', ( request ) => {
		if ( request.url().includes( '/slashphoto/v1/selections' ) ) {
			sent.push( request.url() );
		}
	} );

	await page.goto( GALLERY );
	const gallery = page.locator( '.slashphoto-gallery[data-slashphoto-proofing-ready="local"]' );
	await expect( gallery ).toBeVisible();
	const hearts = gallery.locator( '.slashphoto-pick' );
	await expect( hearts ).toHaveCount( 4 );

	await hearts.nth( 1 ).click();
	await expect( hearts.nth( 1 ) ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( page.locator( '.slashphoto-proofing__status' ) ).toHaveText( /Favourites: 1, saved in this browser only/ );
	await expect( page.locator( '.slashphoto-proofing__login' ) ).toHaveAttribute( 'href', /wp-login\.php\?redirect_to=/ );
	await expect( page.locator( '.slashphoto-proofing__send' ) ).toBeHidden();

	await page.reload();
	await expect( page.locator( '.slashphoto-pick' ).nth( 1 ) ).toHaveAttribute( 'aria-pressed', 'true' );
	expect( sent ).toEqual( [] );
} );

test.describe( 'logged-in client', () => {
	test.skip( ( { isMobile } ) => isMobile, 'Server selections are tested once (desktop) to avoid two projects sharing one account.' );

	test.beforeEach( async ( { page, baseURL } ) => {
		wpEval( 'foreach ( SlashPhoto\\Proofing\\Selections::for_user( get_user_by( "login", "demo-client" )->ID ) as $s ) { wp_delete_post( $s->ID, true ); }' );
		await page.context().addCookies( [ { name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', url: baseURL } ] );
		const response = await page.request.post( '/wp-login.php', {
			form: { log: 'demo-client', pwd: 'demo-client', testcookie: '1', 'wp-submit': 'Log In', redirect_to: GALLERY },
		} );
		expect( response.ok() ).toBe( true );
	} );

	test( 'chooses, notes and sends a selection that the photographer sees', async ( { page } ) => {
		// A favourite chosen before logging in is merged into the selection.
		await page.goto( GALLERY );
		await page.evaluate( () => {
			const root = document.querySelector( '[data-slashphoto-proofing]' ).dataset.slashphotoProofing;
			const first = document.querySelector( '.slashphoto-item' ).dataset.slashphotoId;
			window.localStorage.setItem( 'slashphoto-favourites-' + root, JSON.stringify( [ Number( first ) ] ) );
		} );
		await page.reload();

		const gallery = page.locator( '.slashphoto-gallery[data-slashphoto-proofing-ready="server"]' );
		await expect( gallery ).toBeVisible();
		const hearts = gallery.locator( '.slashphoto-pick' );
		await expect( hearts.nth( 0 ) ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( await page.evaluate( () => Object.keys( window.localStorage ).filter( ( key ) => key.startsWith( 'slashphoto-favourites-' ) ) ) ).toEqual( [] );

		await hearts.nth( 2 ).click();
		await expect( page.locator( '.slashphoto-proofing__status' ) ).toHaveText( '2 selected' );

		// Note from the lightbox.
		await gallery.locator( '.slashphoto-link' ).nth( 2 ).click();
		const dialog = page.locator( 'dialog.slashphoto-lightbox' );
		await expect( dialog.locator( '.slashphoto-lightbox__pick' ) ).toHaveAttribute( 'aria-pressed', 'true' );
		await dialog.locator( '.slashphoto-lightbox__note-button' ).click();
		await dialog.getByLabel( 'Note for the photographer about this image' ).fill( 'Black and white, please' );
		await dialog.getByRole( 'button', { name: 'Save' } ).click();
		await expect( dialog.locator( '.slashphoto-lightbox__note' ) ).toHaveCount( 0 );
		await expect( dialog ).toBeVisible();
		await dialog.getByRole( 'button', { name: 'Close' } ).click();
		await expect( gallery.locator( '.slashphoto-item' ).nth( 2 ) ).toHaveClass( /has-note/ );

		// Only selected, then send.
		await page.locator( '.slashphoto-proofing__filter' ).click();
		await expect( gallery.locator( '.slashphoto-item:visible' ) ).toHaveCount( 2 );
		await page.locator( '.slashphoto-proofing__send' ).click();
		await page.getByLabel( 'Message to the photographer (optional)' ).fill( 'Thank you!' );
		await page.getByRole( 'button', { name: 'Send to the photographer' } ).click();
		await expect( page.locator( '.slashphoto-proofing__status' ) ).toHaveText( /Selection sent on .+ \(2 selected\)/ );
		await expect( hearts.nth( 0 ) ).toBeDisabled();

		await page.reload();
		await expect( page.locator( '.slashphoto-proofing__status' ) ).toHaveText( /Selection sent on/ );
		await page.screenshot( { path: 'tests/e2e/.results/proofing-sent.png', fullPage: true } );

		const stored = JSON.parse(
			wpEval(
				'$u = get_user_by( "login", "demo-client" ); $s = SlashPhoto\\Proofing\\Selections::for_user( $u->ID )[0]; echo wp_json_encode( [ "notes" => array_values( SlashPhoto\\Proofing\\Selections::items( $s ) ), "message" => SlashPhoto\\Proofing\\Selections::message( $s ), "sent" => SlashPhoto\\Proofing\\Selections::submitted_at( $s ) > 0 ] );'
			)
		);
		expect( stored ).toEqual( { notes: [ '', 'Black and white, please' ], message: 'Thank you!', sent: true } );
	} );
} );
