import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

// Uses the protected demo galleries created by `npm run seed`.

test( 'password gallery: no images until the password is entered', async ( { page, request } ) => {
	await page.goto( '/galleries/demo-password/' );
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 0 );

	// Nothing leaks through the REST API either.
	const id = await page.evaluate( () => document.body.className.match( /postid-(\d+)/ )?.[ 1 ] );
	const media = await ( await request.get( `/wp-json/wp/v2/media?parent=${ id }` ) ).json();
	expect( media ).toEqual( [] );

	await page.locator( 'input[name="post_password"]' ).fill( 'demo' );
	await page.locator( 'input[name="post_password"]' ).press( 'Enter' );
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 2 );
	await expect( page.locator( '.slashphoto-item img' ).first() ).toHaveAttribute( 'src', /\/uploads\/slashphoto\/[a-f0-9]{24}\/protected-\d-[a-f0-9]{12}/ );
} );

test( 'terms gallery: images after accepting, remembered in a signed cookie', async ( { page, context } ) => {
	await page.goto( '/galleries/demo-terms/' );
	await expect( page.getByText( 'Please do not share or edit these photos.' ) ).toBeVisible();
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 0 );

	const before = ( await context.cookies() ).map( ( cookie ) => cookie.name );
	// The acceptance is logged with an email address (asked by default, D-021).
	await page.getByLabel( 'Your email address' ).fill( 'terms-e2e@example.org' );
	await page.getByLabel( 'I have read and accept these terms' ).check();
	await page.getByRole( 'button', { name: 'Show the photos' } ).click();
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 2 );

	const added = ( await context.cookies() ).filter( ( cookie ) => ! before.includes( cookie.name ) );
	expect( added ).toHaveLength( 1 );
	expect( added[ 0 ].name ).toMatch( /^slashphoto_terms_\d+$/ );
	expect( added[ 0 ].value ).toMatch( /^[a-f0-9]{64}$/ );
	expect( added[ 0 ].httpOnly ).toBe( true );
	expect( added[ 0 ].sameSite ).toBe( 'Lax' );
} );

test( 'secret link: closed without it, opens with it and drops the key from the address', async ( { page, context } ) => {
	await page.goto( '/galleries/demo-secret-link/' );
	await expect( page.locator( '.slashphoto-notice--link' ) ).toBeVisible();
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 0 );

	const url = wpEval( 'echo SlashPhoto\\Access\\Access::share_url( get_page_by_path( "demo-secret-link", OBJECT, "slashphoto_gallery" ) );' );
	expect( url ).toMatch( /[?&]key=[a-f0-9]{32}$/ );

	await page.goto( url );
	expect( page.url() ).not.toContain( 'key=' );
	await expect( page.locator( '.slashphoto-item' ) ).toHaveCount( 2 );
	const cookie = ( await context.cookies() ).find( ( c ) => c.name.startsWith( 'slashphoto_link_' ) );
	expect( cookie.httpOnly ).toBe( true );
	expect( cookie.value ).toMatch( /^[a-f0-9]{64}$/ );
} );

test( 'watermarked gallery: previews are stamped, the original cannot be derived', async ( { page, request }, testInfo ) => {
	await page.goto( '/galleries/demo-watermark/' );
	const link = page.locator( '.slashphoto-item .slashphoto-link' ).first();
	const href = await link.getAttribute( 'href' );
	expect( href ).toMatch( /-scaled\.jpg$/ );
	expect( ( await request.get( href.replace( '-scaled.jpg', '.jpg' ) ) ).status() ).toBe( 404 );

	await link.click();
	const image = page.locator( 'dialog.slashphoto-lightbox .slashphoto-lightbox__image' );
	await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBeGreaterThan( 0 );
	await page.screenshot( { path: testInfo.outputPath( 'watermark.png' ) } );
} );

test( 'protected pages are not cached or indexed', async ( { request } ) => {
	for ( const path of [ '/galleries/demo-password/', '/galleries/demo-terms/', '/galleries/demo-secret-link/' ] ) {
		const response = await request.get( path );
		expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		expect( await response.text() ).toMatch( /<meta name='robots' content='[^']*noindex/ );
	}
} );

test.describe( 'editor', () => {
	test.skip( ( { browserName, isMobile } ) => isMobile, 'Editor tests run on desktop only.' );

	// Log in with a form post: wp-login.php moves focus to the user name
	// field after load, which makes typing into the form flaky.
	test.beforeEach( async ( { page, baseURL } ) => {
		await page.context().addCookies( [ { name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', url: baseURL } ] );
		const response = await page.request.post( '/wp-login.php', {
			form: { log: 'admin', pwd: 'password', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
		} );
		expect( response.ok() ).toBe( true );
		expect( ( await page.context().cookies() ).some( ( cookie ) => cookie.name.startsWith( 'wordpress_logged_in_' ) ) ).toBe( true );
	} );

	async function openEditor( page, url ) {
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.goto( url );
		await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'enableChoosePatternModal', false );
		} );
		// Close the starter-pattern chooser for new pages if it already opened.
		const patterns = page.getByRole( 'dialog', { name: 'Choose a pattern' } );
		if ( await patterns.isVisible() ) {
			await page.keyboard.press( 'Escape' );
		}
		return errors;
	}

	test( 'gallery editor has the Access panel and saves the settings', async ( { page }, testInfo ) => {
		const errors = await openEditor( page, '/wp-admin/post-new.php?post_type=slashphoto_gallery&slashphoto_content=1' );

		const panel = page.locator( '.slashphoto-access-panel' );
		await expect( panel ).toBeVisible();
		await panel.getByRole( 'button', { name: 'Access' } ).click();
		await panel.getByLabel( 'Who can view this gallery' ).selectOption( 'clients' );
		await expect( panel.getByText( 'Clients', { exact: true } ) ).toBeVisible();
		await page.locator( '.slashphoto-access-panel' ).screenshot( { path: testInfo.outputPath( 'access-panel.png' ) } );
		await page.evaluate( () => {
			const { dispatch } = window.wp.data;
			dispatch( 'core/editor' ).editPost( {
				title: 'E2E access settings',
				meta: { _slashphoto_access: 'clients', _slashphoto_expires: '2030-01-31', _slashphoto_terms: 'E2E terms' },
			} );
			return dispatch( 'core/editor' ).savePost();
		} );
		await page.waitForFunction( () => ! window.wp.data.select( 'core/editor' ).isSavingPost() );

		const meta = await page.evaluate( async () => {
			const id = window.wp.data.select( 'core/editor' ).getCurrentPostId();
			const post = await window.wp.apiFetch( { path: `/wp/v2/slashphoto_gallery/${ id }?context=edit` } );
			return post.meta;
		} );
		expect( meta._slashphoto_access ).toBe( 'clients' );
		expect( meta._slashphoto_expires ).toBe( '2030-01-31' );
		expect( meta._slashphoto_terms ).toBe( 'E2E terms' );
		expect( errors ).toEqual( [] );
	} );

	test( 'gallery block previews a gallery inside a page', async ( { page }, testInfo ) => {
		const errors = await openEditor( page, '/wp-admin/post-new.php?post_type=page' );
		const galleryId = await page.evaluate( async () => {
			const [ gallery ] = await window.wp.apiFetch( { path: '/wp/v2/slashphoto_gallery?slug=demo-summer-wedding' } );
			return gallery.id;
		} );

		await page.evaluate( ( id ) => {
			const { dispatch } = window.wp.data;
			dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'slashphoto/gallery', { galleryId: id } ) );
		}, galleryId );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect( canvas.locator( '.wp-block-slashphoto-gallery .slashphoto-item' ).first() ).toBeVisible( { timeout: 20_000 } );
		const patterns = page.getByRole( 'dialog', { name: 'Choose a pattern' } );
		if ( await patterns.isVisible() ) {
			await patterns.getByRole( 'button', { name: 'Close' } ).click();
			await expect( patterns ).toBeHidden();
		}
		await page.screenshot( { path: testInfo.outputPath( 'block-editor.png' ) } );
		expect( errors ).toEqual( [] );
	} );
} );
