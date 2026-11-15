import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// The lightbox lives outside the theme layout, but global theme rules for
// button, img and p still reach it. Check it in each test theme.
const THEMES = [ 'twentytwentyfive', 'twentytwentyone', 'astra' ];

function activate( theme ) {
	execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'theme', 'activate', theme ], { stdio: 'ignore' } );
}

test.describe.configure( { mode: 'serial' } );

test.afterAll( () => activate( THEMES[ 0 ] ) );

for ( const theme of THEMES ) {
	test( `lightbox controls are intact in ${ theme }`, async ( { page }, testInfo ) => {
		activate( theme );
		await page.goto( '/galleries/demo-summer-wedding/' );
		await page.locator( '.slashphoto-item[data-slashphoto-kind="photo"] .slashphoto-link' ).first().click();

		const dialog = page.locator( 'dialog.slashphoto-lightbox' );
		const image = dialog.locator( '.slashphoto-lightbox__image' );
		await expect( image ).toBeVisible();
		await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBeGreaterThan( 0 );

		// Buttons keep their 44 px round shape despite theme button styles.
		for ( const selector of [ '.slashphoto-lightbox__close', '.slashphoto-lightbox__next' ] ) {
			const box = await dialog.locator( selector ).boundingBox();
			expect( Math.round( box.width ) ).toBe( 44 );
			expect( Math.round( box.height ) ).toBe( 44 );
		}

		// The photo fits inside the viewport.
		const viewport = page.viewportSize();
		const imageBox = await image.boundingBox();
		expect( imageBox.width ).toBeLessThanOrEqual( viewport.width + 1 );
		expect( imageBox.y + imageBox.height ).toBeLessThanOrEqual( viewport.height + 1 );

		await page.screenshot( { path: testInfo.outputPath( `${ theme }.png` ) } );
	} );
}

for ( const theme of THEMES ) {
	test( `proofing controls are intact in ${ theme }`, async ( { page }, testInfo ) => {
		activate( theme );
		await page.goto( '/galleries/demo-proofing/' );
		const hearts = page.locator( '.slashphoto-gallery[data-slashphoto-proofing-ready] .slashphoto-pick' );
		await hearts.first().click();
		await expect( hearts.first() ).toHaveAttribute( 'aria-pressed', 'true' );

		// Round 40 px buttons despite theme button styles, inside the image.
		const box = await hearts.first().boundingBox();
		expect( Math.round( box.width ) ).toBe( 40 );
		expect( Math.round( box.height ) ).toBe( 40 );
		const image = await page.locator( '.slashphoto-item' ).first().locator( 'img' ).boundingBox();
		expect( box.x ).toBeGreaterThanOrEqual( image.x );
		expect( box.y ).toBeGreaterThanOrEqual( image.y );

		await expect( page.locator( '.slashphoto-proofing' ) ).toBeVisible();
		await page.screenshot( { path: testInfo.outputPath( `${ theme }-proofing.png` ), fullPage: true } );
	} );
}
