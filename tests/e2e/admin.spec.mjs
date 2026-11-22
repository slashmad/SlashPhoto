import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const GALLERIES = '/wp-admin/edit.php?post_type=slashphoto_gallery';

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

test.beforeEach( async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page ).toHaveURL( /\/wp-admin\// );
} );

test( 'gallery overview separates visibility groups and links to the native list', async ( { page, isMobile }, testInfo ) => {
	const ids = JSON.parse( wpEval( String.raw`
		$prefix = 'Admin visibility fixture ' . wp_generate_password( 5, false );
		$add = static fn( $title, $fields = [] ) => wp_insert_post( $fields + [
			'post_type' => 'slashphoto_gallery', 'post_status' => 'publish', 'post_title' => $prefix . ' ' . $title,
		] );
		$public = $add( 'public' );
		$private = $add( 'private', [ 'post_status' => 'draft' ] );
		$parent = $add( 'password parent', [ 'post_password' => 'fixture-password' ] );
		$child = $add( 'password child', [ 'post_parent' => $parent ] );
		$restricted = $add( 'restricted', [ 'meta_input' => [ \SlashPhoto\Access\EmailGate::META => 'on' ] ] );
		echo wp_json_encode( compact( 'public', 'private', 'parent', 'child', 'restricted', 'prefix' ) );
	` ) );
	try {
		await page.goto( GALLERIES );
		await expect( page ).toHaveURL( /page=slashphoto-galleries/ );
		const overview = page.locator( '.slashphoto-gallery-overview' );
		await expect( overview.getByRole( 'heading', { level: 1, name: 'Galleries' } ) ).toBeVisible();
		const cases = [
			[ 'Public', 'public', [ ids.public ] ],
			[ 'Private / unpublished', 'private', [ ids.private ] ],
			[ 'Password protected', 'password', [ ids.parent, ids.child ] ],
			[ 'Restricted', 'restricted', [ ids.restricted ] ],
		];
		for ( const [ label, view, expected ] of cases ) {
			const section = overview.getByRole( 'region', { name: label, exact: true } );
			await expect( section ).toHaveAttribute( 'data-visibility', view );
			for ( const id of [ ids.public, ids.private, ids.parent, ids.child, ids.restricted ] ) {
				await expect( section.locator( `[data-gallery-id="${ id }"]` ) ).toHaveCount( Number( expected.includes( id ) ) );
			}
		}
		await expect( overview.locator( `[data-gallery-id="${ ids.child }"] small` ) ).toContainText( 'password parent' );
		const groups = overview.locator( '.slashphoto-gallery-overview__groups' );
		const columns = await groups.evaluate( ( element ) => getComputedStyle( element ).gridTemplateColumns.split( ' ' ).length );
		expect( columns ).toBe( isMobile ? 1 : 2 );
		await overview.screenshot( { path: testInfo.outputPath( 'gallery-overview.png' ) } );

		const password = overview.getByRole( 'region', { name: 'Password protected', exact: true } );
		await password.getByRole( 'link', { name: 'View list' } ).click();
		await expect( page ).toHaveURL( /slashphoto_visibility=password/ );
		await expect( page.locator( '#post-' + ids.child ) ).toBeVisible();
		await page.locator( '.subsubsub' ).getByRole( 'link', { name: 'Overview', exact: true } ).click();
		await overview.getByRole( 'link', { name: 'List view', exact: true } ).click();
		await expect( page ).toHaveURL( /slashphoto_visibility=all/ );
		await expect( page.locator( '#posts-filter #the-list' ) ).toBeVisible();
		if ( ! isMobile ) {
			await expect( page.locator( '#bulk-action-selector-top' ) ).toBeVisible();
		}

		for ( const [ label, view, expected ] of cases ) {
			await page.getByRole( 'link', { name: label, exact: true } ).click();
			await expect( page ).toHaveURL( new RegExp( `slashphoto_visibility=${ view }` ) );
			for ( const id of [ ids.public, ids.private, ids.parent, ids.child, ids.restricted ] ) {
				const row = page.locator( '#post-' + id );
				if ( expected.includes( id ) ) {
					await expect( row.locator( '.column-slashphoto_visibility' ) ).toHaveText( label );
				} else {
					await expect( row ).toHaveCount( 0 );
				}
			}
		}
		await page.getByRole( 'link', { name: 'Password protected', exact: true } ).click();
		await page.locator( '#post-search-input' ).fill( ids.prefix + ' password child' );
		await page.locator( '#search-submit' ).click();
		await expect( page ).toHaveURL( /slashphoto_visibility=password/ );
		await expect( page.locator( '#post-' + ids.child ) ).toBeVisible();
		await expect( page.locator( '#post-' + ids.parent ) ).toHaveCount( 0 );
	} finally {
		wpEval( `foreach ([${ [ ids.child, ids.parent, ids.public, ids.private, ids.restricted ].join( ',' ) }] as $id) { wp_delete_post( $id, true ); }` );
	}
} );

test( 'shipping countries are selected by name and donations are last in the menu', async ( { page }, testInfo ) => {
	await page.goto( GALLERIES + '&page=slashphoto-shop&tab=shipping' );
	const menu = page.locator( '#menu-posts-slashphoto_gallery .wp-submenu li' ).last();
	await expect( menu ).toHaveText( 'Donations' );
	const donationPath = await menu.getByRole( 'link', { includeHidden: true } ).getAttribute( 'href' );
	const picker = page.getByRole( 'group', { name: 'Ship to countries' } );
	await picker.getByText( 'Choose countries' ).click();
	const search = picker.getByRole( 'searchbox', { name: 'Search countries' } );
	await search.fill( 'Country does not exist' );
	await expect( picker.getByText( 'No matching countries.' ) ).toBeVisible();
	await search.fill( 'Sweden' );
	const sweden = picker.getByRole( 'checkbox', { name: 'Sweden', exact: true } );
	const checked = await sweden.isChecked();
	await sweden.setChecked( ! checked );
	await expect( picker.getByRole( 'checkbox' ) ).toHaveCount( 1 );
	await search.fill( '' );
	expect( await picker.getByRole( 'checkbox' ).count() ).toBeGreaterThan( 240 );
	await picker.screenshot( { path: testInfo.outputPath( 'shipping-country-picker.png' ) } );

	// Inspect the real form encoding without editing the running site's preferences.
	await page.route( '**/wp-admin/options.php', ( route ) => route.fulfill( { contentType: 'text/html', body: '<p>Test captured the form</p>' } ) );
	const saved = page.waitForRequest( ( request ) => request.url().endsWith( '/wp-admin/options.php' ) && request.method() === 'POST' );
	await page.getByRole( 'button', { name: 'Save Changes', exact: true } ).click();
	const codes = new URLSearchParams( ( await saved ).postData() ).getAll( 'slashphoto_settings[shipping_countries][]' );
	expect( codes[ 0 ] ).toBe( '' );
	expect( codes.includes( 'SE' ) ).toBe( ! checked );

	await page.goto( new URL( donationPath, page.url() ).href );
	await expect( page.getByRole( 'heading', { name: 'Donations' } ) ).toBeVisible();
	await expect( page.getByRole( 'link', { name: 'GitHub Sponsors' } ) ).toHaveAttribute( 'href', 'https://github.com/sponsors/slashmad' );
	await expect( page.getByRole( 'link', { name: 'Ko-fi' } ) ).toHaveAttribute( 'href', 'https://ko-fi.com/slashmad' );
	await expect( page.getByRole( 'link', { name: 'Liberapay' } ) ).toHaveAttribute( 'href', 'https://liberapay.com/slashmad/' );
} );

test( 'gallery workspace creates a draft, uploads photos, saves access and opens the content editor on demand', async ( { page, browser, baseURL }, testInfo ) => {
	const fixture = JSON.parse( wpEval( String.raw`
		require_once WP_PLUGIN_DIR . '/slashphoto/tests/Integration/TestImages.php';
		$file = \SlashPhoto\Tests\Integration\TestImages::jpeg( 320, 180 );
		$label = 'Gallery workspace fixture ' . wp_generate_password( 7, false );
		$login = sanitize_title( $label );
		$user = wp_create_user( $login, 'local-test-account-password' );
		echo wp_json_encode( [ 'label' => $label, 'image' => base64_encode( file_get_contents( $file ) ), 'user' => (int) $user, 'login' => $login ] );
	` ) );
	let id;
	try {
		await page.goto( GALLERIES );
		await page.locator( '.slashphoto-gallery-overview' ).getByRole( 'link', { name: 'Add new gallery' } ).click();
		await expect( page ).toHaveURL( /page=slashphoto-gallery$/ );
		await page.getByLabel( 'Gallery name' ).fill( fixture.label );
		await page.getByRole( 'button', { name: 'Create draft and add photos' } ).click();
		await expect( page ).toHaveURL( /gallery_id=\d+/ );
		id = Number( new URL( page.url() ).searchParams.get( 'gallery_id' ) );
		await expect( page.getByRole( 'heading', { level: 1, name: fixture.label } ) ).toBeVisible();
		await expect( page.getByText( 'Draft', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'This gallery has no photos yet.' ) ).toBeVisible();
		await page.getByLabel( 'Choose photos to upload' ).setInputFiles( {
			name: 'workspace-photo.jpg',
			mimeType: 'image/jpeg',
			buffer: Buffer.from( fixture.image, 'base64' ),
		} );
		const photo = page.locator( '[data-photo-grid] li' );
		await expect( photo ).toContainText( 'workspace-photo', { timeout: 30_000 } );
		await expect.poll( () => photo.locator( 'img' ).evaluate( ( image ) => image.naturalWidth ) ).toBeGreaterThan( 0 );
		await expect( page.locator( '[data-photo-count]' ) ).toHaveText( '1' );
		await page.locator( '.slashphoto-workspace' ).screenshot( { path: testInfo.outputPath( 'gallery-workspace.png' ) } );

		await page.locator( '.slashphoto-admin-tabs' ).getByRole( 'link', { name: 'Settings' } ).click();
		await page.getByLabel( 'Who can view this gallery' ).selectOption( 'members' );
		await page.getByLabel( 'Price list', { exact: true } ).selectOption( 'none' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Gallery settings saved.' ) ).toBeVisible();
		await expect( page.getByLabel( 'Who can view this gallery' ) ).toHaveValue( 'members' );
		await expect( page.getByLabel( 'Price list', { exact: true } ) ).toHaveValue( 'none' );
		await page.getByRole( 'button', { name: 'Publish gallery' } ).click();
		await expect( page.getByText( 'Published', { exact: true } ) ).toBeVisible();
		await expect( photo ).toContainText( 'workspace-photo' );

		await page.getByRole( 'link', { name: 'All galleries' } ).filter( { hasText: '←' } ).click();
		await page.locator( `[data-gallery-id="${ id }"]` ).getByRole( 'link' ).click();
		await expect( photo ).toContainText( 'workspace-photo' );

		const context = await browser.newContext( { baseURL } );
		try {
			await context.request.get( '/wp-login.php' );
			await context.request.post( '/wp-login.php', { form: { log: fixture.login, pwd: 'local-test-account-password', testcookie: '1' } } );
			const response = await context.request.get( GALLERIES + '&page=slashphoto-gallery&gallery_id=' + id );
			expect( response.status() ).toBe( 403 );
			expect( await response.text() ).not.toContain( 'workspace-photo' );
		} finally {
			await context.close();
		}

		await page.getByRole( 'link', { name: 'Edit page content' } ).click();
		await expect( page ).toHaveURL( /post\.php.*slashphoto_content=1/ );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page ).toHaveURL( /page=slashphoto-gallery/ );
		await page.goto( '/wp-admin/post-new.php?post_type=slashphoto_gallery' );
		await expect( page.getByLabel( 'Gallery name' ) ).toBeVisible();
	} finally {
		wpEval( `if ( ${ id || 0 } ) { foreach ( get_children( [ 'post_parent' => ${ id || 0 }, 'post_type' => 'attachment' ] ) as $a ) { wp_delete_attachment( $a->ID, true ); } wp_delete_post( ${ id || 0 }, true ); } require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ fixture.user } );` );
	}
} );

test( 'settings tabs show only their own fields and preview the full watermark', async ( { page }, testInfo ) => {
	const id = Number( wpEval( String.raw`
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$image = imagecreatetruecolor( 800, 200 );
		ob_start(); imagepng( $image ); $bits = ob_get_clean();
		$file = wp_upload_bits( 'slashphoto-workspace-watermark-fixture-' . wp_generate_password( 5, false ) . '.png', null, $bits );
		$id = wp_insert_attachment( [ 'post_title' => 'Workspace watermark fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], $file['file'] );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
		echo $id;
	` ) );
	try {
		await page.goto( GALLERIES + '&page=slashphoto-settings' );
		const tabs = page.getByRole( 'navigation', { name: 'Settings sections' } );
		await expect( tabs.getByRole( 'link', { name: 'Watermark' } ) ).toHaveAttribute( 'aria-current', 'page' );
		await page.getByLabel( 'Watermark image' ).selectOption( String( id ) );
		const preview = page.getByRole( 'img', { name: 'Watermark preview' } );
		await expect.poll( () => preview.evaluate( ( image ) => [ image.naturalWidth, image.naturalHeight ] ) ).toEqual( [ 800, 200 ] );
		const bounds = await preview.boundingBox();
		expect( bounds.width / bounds.height ).toBeCloseTo( 4, 1 );
		await page.locator( '.wrap' ).screenshot( { path: testInfo.outputPath( 'watermark-settings.png' ) } );
		await tabs.getByRole( 'link', { name: 'Display copies' } ).click();
		await expect( page.getByLabel( 'Quality', { exact: true } ) ).toBeVisible();
		await expect( page.getByLabel( 'Watermark image' ) ).toHaveCount( 0 );
		await tabs.getByRole( 'link', { name: 'SnapOtter' } ).click();
		await expect( page.getByRole( 'heading', { name: 'SnapOtter connection' } ) ).toBeVisible();
		await tabs.getByRole( 'link', { name: 'Visitors' } ).click();
		await expect( page.getByLabel( 'Keep the visitor log for (days)' ) ).toBeVisible();

		await page.goto( GALLERIES + '&page=slashphoto-shop' );
		await expect( tabs.getByRole( 'link', { name: 'Pages' } ) ).toHaveAttribute( 'aria-current', 'page' );
		await tabs.getByRole( 'link', { name: 'Payments' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Card payments with Stripe' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'PayPal' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Mollie' } ) ).toBeVisible();
		await expect( page.getByRole( 'group', { name: 'Ship to countries' } ) ).toHaveCount( 0 );
	} finally {
		wpEval( `wp_delete_attachment( ${ id }, true );` );
	}
} );

test( 'orders overview and visitor reports use the grouped admin layout', async ( { page }, testInfo ) => {
	await page.goto( GALLERIES + '&page=slashphoto-orders' );
	const overview = page.locator( '.slashphoto-gallery-overview' );
	await expect( overview.getByRole( 'region', { name: 'Awaiting manual payment' } ) ).toBeVisible();
	await expect( overview.getByRole( 'region', { name: 'Paid, being prepared' } ) ).toBeVisible();
	await overview.screenshot( { path: testInfo.outputPath( 'order-overview.png' ) } );
	await overview.getByRole( 'link', { name: 'List view', exact: true } ).click();
	await expect( page.getByRole( 'table' ) ).toBeVisible();

	const importLink = await page.locator( '#menu-posts-slashphoto_gallery [href*="page=slashphoto-import"]' ).all();
	if ( importLink.length ) {
		await page.goto( new URL( await importLink[ 0 ].getAttribute( 'href' ), page.url() ).href );
		await expect( page.getByRole( 'heading', { name: 'Sunshine data in the WordPress database' } ) ).toBeVisible();
		if ( 'inactive' === wpEval( 'echo post_type_exists( "sunshine-gallery" ) ? "active" : "inactive";' ) ) {
			await expect( page.getByText( /Sunshine Photo Cart is inactive/ ) ).toBeVisible();
		}
	}

	await page.goto( GALLERIES + '&page=slashphoto-visitors' );
	await expect( overview.getByRole( 'heading', { name: 'Activity by account' } ) ).toBeVisible();
	await expect( overview.getByRole( 'heading', { name: 'Gallery views without visitor identifiers' } ) ).toBeVisible();
	await expect( overview.getByRole( 'heading', { name: 'Visitor log', exact: true } ) ).toBeVisible();

	await page.goto( GALLERIES + '&page=slashphoto-stats' );
	await expect( overview.locator( '.slashphoto-gallery-section' ) ).toHaveCount( 4 );
	await expect( overview.getByRole( 'heading', { name: 'Photo views per day' } ) ).toBeVisible();
	await overview.getByRole( 'link', { name: 'Last 7 days' } ).click();
	await expect( page ).toHaveURL( /days=7/ );
	await overview.screenshot( { path: testInfo.outputPath( 'statistics-cards.png' ) } );
} );
