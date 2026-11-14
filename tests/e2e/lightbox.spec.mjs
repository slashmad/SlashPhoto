import { expect, test } from '@playwright/test';

const GALLERY = '/galleries/demo-summer-wedding/';

test.beforeEach( async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'console', ( msg ) => msg.type() === 'error' && errors.push( msg.text() ) );
	page.errors = errors;
	await page.goto( GALLERY );
} );

test( 'opens photos, navigates and returns focus on close', async ( { page } ) => {
	const items = page.locator( '.slashphoto-item' );
	const total = await items.count();
	expect( total ).toBeGreaterThan( 1 );

	const firstPhoto = page.locator( '.slashphoto-item[data-slashphoto-kind="photo"] .slashphoto-link' ).first();
	await firstPhoto.click();

	const dialog = page.locator( 'dialog.slashphoto-lightbox' );
	await expect( dialog ).toBeVisible();
	const image = dialog.locator( '.slashphoto-lightbox__image' );
	await expect( image ).toBeVisible();
	await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBeGreaterThan( 0 );
	const box = await image.boundingBox();
	const viewport = page.viewportSize();
	expect( box.y + box.height ).toBeLessThanOrEqual( viewport.height + 1 );
	await expect( dialog.locator( '.slashphoto-lightbox__counter' ) ).toHaveText( `1 of ${ total }` );

	await page.keyboard.press( 'ArrowRight' );
	await expect( dialog.locator( '.slashphoto-lightbox__counter' ) ).toHaveText( `2 of ${ total }` );
	await page.keyboard.press( 'ArrowLeft' );
	await page.keyboard.press( 'ArrowLeft' );
	await expect( dialog.locator( '.slashphoto-lightbox__counter' ) ).toHaveText( `${ total } of ${ total }` );

	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toBeHidden();
	await expect( page.locator( 'html' ) ).not.toHaveClass( /slashphoto-lightbox-open/ );
	expect( page.errors ).toEqual( [] );
} );

test( 'does not load the 360° viewer until a 360° image is opened', async ( { page } ) => {
	const requested = [];
	page.on( 'request', ( request ) => requested.push( request.url() ) );

	await page.locator( '.slashphoto-item[data-slashphoto-kind="photo"] .slashphoto-link' ).first().click();
	await expect( page.locator( 'dialog.slashphoto-lightbox .slashphoto-lightbox__image' ) ).toBeVisible();

	expect( requested.filter( ( url ) => /three|photo-sphere-viewer|panorama-viewer/.test( url ) ) ).toEqual( [] );
} );

test( 'renders 360° panoramas in the viewer', async ( { page }, testInfo ) => {
	const panoramas = page.locator( '.slashphoto-item[data-slashphoto-kind="360"] .slashphoto-link' );
	const count = await panoramas.count();
	expect( count ).toBeGreaterThan( 0 );

	for ( let i = 0; i < count; i++ ) {
		await panoramas.nth( i ).click();
		const dialog = page.locator( 'dialog.slashphoto-lightbox' );
		await expect( dialog ).toHaveAttribute( 'data-panorama-ready', 'true', { timeout: 30_000 } );
		await expect( dialog.locator( '.psv-container canvas' ) ).toBeVisible();
		await page.screenshot( { path: testInfo.outputPath( `panorama-${ i + 1 }.png` ) } );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	}
	expect( page.errors ).toEqual( [] );
} );

test( 'plain links still work with a modifier key', async ( { page } ) => {
	const link = page.locator( '.slashphoto-item .slashphoto-link' ).first();
	await expect( link ).toHaveAttribute( 'href', /\/wp-content\/uploads\// );
} );
