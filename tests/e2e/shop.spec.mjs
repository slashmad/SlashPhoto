import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Uses the "demo-shop" gallery, the demo price list and the "demo-cart" page
// created by `npm run seed`. Orders are paid manually (Swish/invoice) so the
// test needs no payment provider.

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

const GALLERY = '/galleries/demo-shop/';

async function addFromLightbox( page, index, product, qty = 1 ) {
	await page.locator( '.slashphoto-gallery[data-slashphoto-shop-ready] .slashphoto-link' ).nth( index ).click();
	const dialog = page.locator( 'dialog.slashphoto-lightbox' );
	await dialog.getByRole( 'button', { name: 'Buy this photo' } ).click();
	const panel = dialog.locator( '.slashphoto-lightbox__buy' );
	await expect( panel ).toBeVisible();
	if ( qty > 1 ) {
		await panel.getByLabel( `Quantity, ${ product }` ).fill( String( qty ) );
	}
	await panel.getByRole( 'button', { name: `Add ${ product } to cart` } ).click();
	await expect( panel.locator( '.slashphoto-lightbox__buy-status' ) ).toContainText( `Added: ${ product }.` );
	return { dialog, panel };
}

test( 'orders prints and a download, pays manually, then downloads the original', async ( { page, baseURL } ) => {
	const sent = [];
	page.on( 'request', ( request ) => {
		// blob: URLs (decoded images) report the site's own origin.
		if ( ! request.url().startsWith( 'data:' ) && new URL( request.url() ).origin !== new URL( baseURL ).origin ) {
			sent.push( request.url() );
		}
	} );

	await page.goto( GALLERY );
	await page.evaluate( () => window.localStorage.removeItem( 'slashphoto-cart' ) );
	await page.reload();
	await expect( page.locator( '.slashphoto-shopbar__status' ) ).toHaveText( 'Open a photo to order prints or downloads.' );

	// Two prints and a download of the first photo.
	const { dialog, panel } = await addFromLightbox( page, 0, 'Print 20 × 30 cm', 2 );
	await panel.getByRole( 'button', { name: 'Add Digital download, full resolution to cart' } ).click();
	// Esc closes the panel first, then the lightbox.
	await page.keyboard.press( 'Escape' );
	await expect( panel ).toHaveCount( 0 );
	await expect( dialog ).toBeVisible();
	await expect( dialog.getByRole( 'button', { name: 'Buy this photo' } ) ).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.slashphoto-shopbar__status' ) ).toHaveText( 'Cart: 3 items' );

	// A download is bought once, even if added again.
	await addFromLightbox( page, 0, 'Digital download, full resolution' );
	await page.keyboard.press( 'Escape' );
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.slashphoto-shopbar__status' ) ).toHaveText( 'Cart: 3 items' );

	await page.locator( '.slashphoto-shopbar__link' ).click();
	await expect( page ).toHaveURL( /\/demo-cart\/$/ );
	const cart = page.locator( '[data-slashphoto-cart]' );
	await expect( cart.locator( '.slashphoto-cart__line' ) ).toHaveCount( 2 );
	// 2 × 199 + 300 = 698 kr, shipping 49 kr.
	await expect( cart.locator( '.slashphoto-cart__totals' ) ).toContainText( /698/ );
	await expect( cart.locator( 'dd.slashphoto-cart__total' ) ).toHaveText( /747/ );
	await expect( page.locator( '[data-slashphoto-address]' ) ).toBeVisible();

	// Changing the quantity re-prices from the server.
	await cart.getByLabel( /Quantity, Print 20 × 30 cm/ ).fill( '1' );
	await cart.getByLabel( /Quantity, Print 20 × 30 cm/ ).press( 'Tab' );
	await expect( cart.locator( 'dd.slashphoto-cart__total' ) ).toHaveText( /548/ );

	// Missing fields are reported.
	const form = page.locator( '[data-slashphoto-checkout-form]' );
	await form.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( form.locator( '[data-slashphoto-checkout-error]' ) ).toHaveText( 'Fill in the marked fields.' );

	await form.getByLabel( 'Email', { exact: true } ).fill( 'e2e-buyer@example.org' );
	await form.getByLabel( 'Name', { exact: true } ).fill( 'E2E Buyer' );
	await form.getByLabel( 'Street address' ).fill( 'Storgatan 1' );
	await form.getByLabel( 'Postcode' ).fill( '111 22' );
	await form.getByLabel( 'City', { exact: true } ).fill( 'Stockholm' );
	await form.getByLabel( 'Pay later (Swish or invoice)' ).check();
	await form.getByRole( 'button', { name: 'Place order' } ).click();

	await expect( page ).toHaveURL( /[?&]order=\d+&key=[a-f0-9]{32}/ );
	const order = page.locator( '.slashphoto-order' );
	await expect( order.locator( '.slashphoto-order__status' ) ).toContainText( 'Awaiting manual payment' );
	await expect( order ).toContainText( 'Swish 123 456 78 90' );
	await expect( order ).toContainText( 'Downloads appear here when the payment has arrived.' );
	await expect( order.locator( '.slashphoto-order__download' ) ).toHaveCount( 0 );
	expect( await page.evaluate( () => window.localStorage.getItem( 'slashphoto-cart' ) ) ).toBeNull();

	// The photographer marks it paid; the download opens.
	const id = new URL( page.url() ).searchParams.get( 'order' );
	wpEval( `SlashPhoto\\Shop\\Orders::mark_paid( ${ id } );` );
	await page.reload();
	await expect( order.locator( '.slashphoto-order__status' ) ).toContainText( 'Paid' );
	const link = order.locator( '.slashphoto-order__download' );
	await expect( link ).toHaveCount( 1 );

	const response = await page.request.get( await link.getAttribute( 'href' ) );
	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'content-disposition' ] ).toMatch( /^attachment; filename="shop-1\.jpg"/ );
	const expected = Number( wpEval( `$i = SlashPhoto\\Shop\\Orders::items( ${ id } ); foreach ( $i as $l ) { if ( "download" === $l["kind"] ) { echo filesize( wp_get_original_image_path( $l["image_id"] ) ); } }` ) );
	expect( ( await response.body() ).length ).toBe( expected );

	// A wrong key gets nothing.
	const forged = ( await link.getAttribute( 'href' ) ).replace( /key=[a-f0-9]{32}/, 'key=' + '0'.repeat( 32 ) );
	expect( ( await page.request.get( forged ) ).status() ).toBe( 404 );

	// No requests to other sites, from the gallery, the cart or the order page.
	expect( sent ).toEqual( [] );
} );

test( 'the photographer sees the order and can fetch the original for printing', async ( { page, baseURL, isMobile } ) => {
	test.skip( isMobile, 'Admin screens are checked on desktop.' );
	const id = wpEval( 'global $wpdb; echo $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}slashphoto_orders WHERE email = \'e2e-buyer@example.org\'" );' );
	expect( Number( id ) ).toBeGreaterThan( 0 );

	await page.context().addCookies( [ { name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', url: baseURL } ] );
	const login = await page.request.post( '/wp-login.php', {
		form: { log: 'admin', pwd: 'password', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
	} );
	expect( login.ok() ).toBe( true );

	await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-orders' );
	await page.getByRole( 'link', { name: `Order ${ id }`, exact: true } ).click();
	await expect( page.getByRole( 'heading', { name: `Order ${ id }` } ) ).toBeVisible();
	await expect( page.locator( '.wrap' ) ).toContainText( 'Storgatan 1' );
	const original = page.locator( '.wrap table' ).getByRole( 'link', { name: 'Download' } ).first();
	expect( ( await page.request.get( await original.getAttribute( 'href' ) ) ).status() ).toBe( 200 );

	await page.getByLabel( 'Status', { exact: true } ).selectOption( 'completed' );
	await page.getByRole( 'button', { name: 'Update order' } ).click();
	await expect( page.getByText( 'Order updated.' ) ).toBeVisible();
	await expect( page.getByLabel( 'Status', { exact: true } ) ).toHaveValue( 'completed' );
} );

test( 'proofing and shop tools share the lightbox', async ( { page } ) => {
	await page.goto( '/galleries/demo-proofing/' );
	await expect( page.locator( '.slashphoto-gallery[data-slashphoto-proofing-ready]' ) ).toBeVisible();
	await page.locator( '.slashphoto-link' ).first().click();
	const dialog = page.locator( 'dialog.slashphoto-lightbox' );
	await expect( dialog.locator( '.slashphoto-lightbox__pick' ) ).toBeVisible();
	await expect( dialog.getByRole( 'button', { name: 'Buy this photo' } ) ).toBeVisible();
	await dialog.locator( '.slashphoto-lightbox__pick' ).click();
	await expect( dialog.getByRole( 'button', { name: 'Buy this photo' } ) ).toBeVisible();
	await page.keyboard.press( 'ArrowRight' );
	await expect( dialog.locator( '.slashphoto-lightbox__group' ) ).toHaveCount( 2 );
} );
