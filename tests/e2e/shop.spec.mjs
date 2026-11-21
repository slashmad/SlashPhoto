import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Uses the "demo-shop" gallery, the demo price list and the "demo-cart" page
// created by `npm run seed`. Orders are paid manually (Swish/invoice) so the
// test needs no payment provider.

function wpEval( code ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
}

const GALLERY = '/galleries/demo-shop/';

test( 'paper and framing options stay separate in the cart across themes', async ( { page, isMobile }, testInfo ) => {
	const fixture = JSON.parse( wpEval( String.raw`
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once WP_PLUGIN_DIR . '/slashphoto/tests/Integration/TestImages.php';
		$list = wp_insert_post( [ 'post_type' => 'slashphoto_pricelist', 'post_status' => 'publish', 'post_title' => 'Print options browser test' ] );
		\SlashPhoto\Shop\PriceLists::save_products( $list, [ [
			'id' => 'dddd0001', 'kind' => 'print', 'name' => 'Poster 30 × 40 cm', 'price' => 22900,
			'variants' => [
				[ 'paper' => 'Matte photo paper', 'frame' => '', 'price' => 22900 ],
				[ 'paper' => 'Fine art paper', 'frame' => 'Natural oak', 'price' => 54900 ],
			],
		] ] );
		$gallery = wp_insert_post( [ 'post_type' => 'slashphoto_gallery', 'post_status' => 'publish', 'post_title' => 'Print options browser test' ] );
		update_post_meta( $gallery, '_slashphoto_price_list', (string) $list );
		$file = \SlashPhoto\Tests\Integration\TestImages::jpeg( 400, 300 );
		$image = media_handle_sideload( [ 'name' => 'print-option-test.jpg', 'tmp_name' => $file ], $gallery );
		if ( is_wp_error( $image ) ) {
			wp_delete_post( $gallery, true );
			wp_delete_post( $list, true );
			WP_CLI::error( $image->get_error_message() );
		}
		echo wp_json_encode( [ 'list' => $list, 'gallery' => $gallery, 'image' => $image, 'url' => get_permalink( $gallery ), 'theme' => get_stylesheet() ] );
	` ) );
	try {
		for ( const theme of [ 'twentytwentyfive', 'twentytwentyone', 'astra' ] ) {
			wpEval( `switch_theme( ${ JSON.stringify( theme ) } );` );
			await page.goto( fixture.url );
			await page.evaluate( () => window.localStorage.removeItem( 'slashphoto-cart' ) );
			await page.reload();
			await page.locator( '.slashphoto-gallery[data-slashphoto-shop-ready] .slashphoto-link' ).first().click();
			const dialog = page.locator( 'dialog.slashphoto-lightbox' );
			await dialog.getByRole( 'button', { name: 'Buy this photo' } ).click();
			const panel = dialog.locator( '.slashphoto-lightbox__buy' );
			const options = panel.getByRole( 'combobox', { name: 'Paper and framing, Poster 30 × 40 cm' } );
			await expect( options ).toBeVisible();
			await expect( options.locator( 'option' ) ).toHaveCount( 2 );
			const panelColor = await panel.evaluate( ( element ) => getComputedStyle( element ).color );
			await expect( panel.getByText( 'Paper and framing', { exact: true } ) ).toHaveCSS( 'color', panelColor );
			await expect( options ).toHaveCSS( 'color', panelColor );
			await expect( options ).toHaveCSS( 'appearance', 'auto' );
			await panel.getByRole( 'button', { name: /Add Poster .*Matte photo paper \/ Unframed to cart/ } ).click();
			await options.selectOption( { index: 1 } );
			await expect( panel.locator( '.slashphoto-lightbox__product-price' ) ).toHaveText( /549/ );
			await panel.getByRole( 'button', { name: /Add Poster .*Fine art paper \/ Natural oak to cart/ } ).click();
			expect( await panel.evaluate( ( element ) => element.scrollWidth <= element.clientWidth ) ).toBe( true );
			await page.screenshot( { path: testInfo.outputPath( `${ theme }-print-options.png` ) } );
			await panel.getByRole( 'link', { name: 'Go to cart' } ).click();
			const cart = page.locator( '[data-slashphoto-cart]' );
			await expect( cart.locator( '.slashphoto-cart__line' ) ).toHaveCount( 2 );
			const framedQuantity = cart.getByLabel( /Quantity, Poster .*Fine art paper \/ Natural oak/ );
			await framedQuantity.fill( '2' );
			await framedQuantity.press( 'Tab' );
			await expect( framedQuantity ).toHaveValue( '2' );
			await expect( cart.getByLabel( /Quantity, Poster .*Matte photo paper \/ Unframed/ ) ).toHaveValue( '1' );
			await cart.getByRole( 'button', { name: /Remove Poster .*Matte photo paper \/ Unframed/ } ).click();
			await expect( cart.locator( '.slashphoto-cart__line' ) ).toHaveCount( 1 );
			await expect( framedQuantity ).toHaveValue( '2' );
		}
		if ( ! isMobile ) {
			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( 'admin' );
			await page.locator( '#user_pass' ).fill( 'password' );
			await page.locator( '#wp-submit' ).click();
			await expect( page ).toHaveURL( /\/wp-admin\// );
			await page.goto( `/wp-admin/post.php?post=${ fixture.list }&action=edit` );
			await page.getByText( 'Paper and framing options', { exact: true } ).click();
			const editor = page.getByRole( 'textbox', { name: 'Print options', exact: true } );
			await expect( editor ).toHaveValue( 'Matte photo paper |  | 229.00\nFine art paper | Natural oak | 549.00' );
			await page.screenshot( { path: testInfo.outputPath( 'price-list-print-options.png' ) } );
			await editor.fill( 'Matte photo paper | | 239,50\nFine art paper | Natural oak | 549' );
			await page.locator( '#publish' ).click();
			await page.getByText( 'Paper and framing options', { exact: true } ).click();
			await expect( editor ).toHaveValue( 'Matte photo paper |  | 239.50\nFine art paper | Natural oak | 549.00' );
		}
	} finally {
		wpEval( `switch_theme( ${ JSON.stringify( fixture.theme ) } ); wp_delete_attachment( ${ fixture.image }, true ); wp_delete_post( ${ fixture.gallery }, true ); wp_delete_post( ${ fixture.list }, true );` );
	}
} );

test( 'print templates append unpriced choices and can be disabled independently', async ( { page }, testInfo ) => {
	const fixture = JSON.parse( wpEval( String.raw`
		$settings = get_option( \SlashPhoto\Settings::OPTION, [] );
		$currency = \SlashPhoto\Settings::get( 'currency' );
		$price = \SlashPhoto\Shop\Money::parse( '229', $currency );
		$list = wp_insert_post( [ 'post_type' => 'slashphoto_pricelist', 'post_status' => 'publish', 'post_title' => 'Print templates browser test' ] );
		\SlashPhoto\Shop\PriceLists::save_products( $list, [ [
			'id' => 'dddd0002', 'kind' => 'print', 'name' => 'Template poster', 'price' => $price,
			'variants' => [ [ 'paper' => 'Matte photo paper', 'frame' => '', 'price' => $price ] ],
		] ] );
		echo wp_json_encode( [ 'list' => $list, 'original' => $settings['print_presets'] ?? null ] );
	` ) );
	const settingsURL = '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-shop&tab=prints';
	const listURL = `/wp-admin/post.php?post=${ fixture.list }&action=edit`;
	const savedTemplates = () => JSON.parse( wpEval( String.raw`echo wp_json_encode( \SlashPhoto\Settings::get( 'print_presets' ) );` ) );
	const savedPapers = () => JSON.parse( wpEval( String.raw`$product = \SlashPhoto\Shop\PriceLists::product( ${ fixture.list }, 'dddd0002' ); echo wp_json_encode( array_column( $product['variants'], 'paper' ) );` ) );
	try {
		await page.request.get( '/wp-login.php' );
		await page.request.post( '/wp-login.php', {
			form: { log: 'admin', pwd: 'password', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
		} );
		await page.goto( settingsURL );
		await expect( page ).toHaveURL( /tab=prints/ );
		const crimson = page.getByRole( 'checkbox', { name: 'Crimson' } );
		const whitewall = page.getByRole( 'checkbox', { name: 'WhiteWall' } );
		const printspace = page.getByRole( 'checkbox', { name: 'theprintspace' } );
		await crimson.check();
		await whitewall.uncheck();
		await printspace.check();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect.poll( savedTemplates ).toEqual( [ 'crimson', 'theprintspace' ] );
		await expect( whitewall ).not.toBeChecked();
		await expect( printspace ).toBeChecked();

		await page.goto( listURL );
		await page.getByText( 'Paper and framing options', { exact: true } ).click();
		const editor = page.getByRole( 'textbox', { name: 'Print options', exact: true } );
		const selector = page.getByRole( 'combobox', { name: 'Print template' } );
		await expect( selector.locator( 'option' ) ).toHaveText( [ 'Choose a template', 'Crimson', 'theprintspace' ] );
		const initial = await editor.inputValue();
		await selector.selectOption( 'crimson' );
		const apply = page.getByRole( 'button', { name: 'Add combinations' } );
		await apply.click();
		const added = await editor.inputValue();
		expect( added ).toBe( initial + '\nGlossy photo paper |  | \nMatte photo paper | Black wood frame | \nFine art paper | Oak frame | ' );
		await expect( editor ).toBeFocused();
		await apply.click();
		await expect( editor ).toHaveValue( added );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'These combinations are already in this print.' } ) ).toBeVisible();
		await page.locator( '#publish' ).click();
		await expect( editor ).toBeFocused();
		await expect.poll( () => editor.evaluate( ( field ) => field.validationMessage ) ).toMatch( /Enter a price/ );
		await expect( editor ).toHaveValue( added );

		await editor.fill( initial + '\nGlossy photo paper | | 249\nMatte photo paper | Black wood frame | 399\nFine art paper | Oak frame | 549' );
		await page.locator( '#publish' ).click();
		await expect.poll( savedPapers ).toEqual( [ 'Matte photo paper', 'Glossy photo paper', 'Matte photo paper', 'Fine art paper' ] );
		await page.goto( listURL );
		await page.getByText( 'Paper and framing options', { exact: true } ).click();
		if ( 'phone' === testInfo.project.name ) {
			const products = page.locator( '[data-slashphoto-products]' );
			await expect( products ).toHaveCSS( 'display', 'block' );
			const price = products.getByRole( 'textbox', { name: 'Price', exact: true } );
			const bounds = await price.boundingBox();
			expect( bounds.x + bounds.width ).toBeLessThanOrEqual( page.viewportSize().width );
		}
		const priced = await editor.inputValue();
		expect( priced ).toMatch( /Black wood frame \| 399/ );
		await page.screenshot( { path: testInfo.outputPath( 'price-list-templates.png' ) } );

		await page.goto( settingsURL );
		await crimson.uncheck();
		await printspace.uncheck();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect.poll( savedTemplates ).toEqual( [] );
		await expect( crimson ).not.toBeChecked();
		await expect( printspace ).not.toBeChecked();
		await page.goto( listURL );
		await page.getByText( 'Paper and framing options', { exact: true } ).click();
		await expect( selector ).toHaveCount( 0 );
		await expect( editor ).toHaveValue( priced );
		await editor.fill( priced + '\nCotton rag | | 450' );
		await page.locator( '#publish' ).click();
		await expect.poll( savedPapers ).toContain( 'Cotton rag' );
		await page.goto( listURL );
		await page.getByText( 'Paper and framing options', { exact: true } ).click();
		await expect( editor ).toHaveValue( /Cotton rag \| +\| 450/ );
	} finally {
		wpEval( `$key = \\SlashPhoto\\Settings::OPTION; $settings = get_option( $key, [] ); $original = json_decode( '${ JSON.stringify( fixture.original ) }', true ); if ( null === $original ) { unset( $settings['print_presets'] ); } else { $settings['print_presets'] = $original; } update_option( $key, $settings ); wp_delete_post( ${ fixture.list }, true );` );
	}
} );

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

	await page.goto( '/wp-admin/edit.php?post_type=slashphoto_gallery&page=slashphoto-orders&view=list' );
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

test( 'a package, a discount code, an invoice and then a receipt', async ( { page } ) => {
	await page.goto( GALLERY );
	await page.evaluate( () => {
		window.localStorage.removeItem( 'slashphoto-cart' );
		window.localStorage.removeItem( 'slashphoto-coupon' );
	} );
	await page.reload();

	// Two photos into "Three downloads" from the lightbox.
	await page.locator( '.slashphoto-gallery[data-slashphoto-shop-ready] .slashphoto-link' ).first().click();
	const dialog = page.locator( 'dialog.slashphoto-lightbox' );
	for ( const step of [ 1, 2 ] ) {
		await dialog.getByRole( 'button', { name: 'Buy this photo' } ).click();
		const panel = dialog.locator( '.slashphoto-lightbox__buy' );
		await expect( panel ).toContainText( '3 photos you choose, each: Digital download, full resolution' );
		await panel.getByRole( 'button', { name: 'Add this photo to Three downloads' } ).click();
		await expect( panel.locator( '.slashphoto-lightbox__buy-status' ) ).toContainText( `Added to Three downloads: ${ step } of 3 photos.` );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.press( 'ArrowRight' );
	}
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.slashphoto-shopbar__status' ) ).toHaveText( 'Cart: 1 item' );

	await page.goto( '/demo-cart/' );
	const line = page.locator( '.slashphoto-cart__line--package' );
	await expect( line ).toContainText( '2 of 3 photos chosen' );
	await expect( line.locator( '.slashphoto-cart__package img' ) ).toHaveCount( 2 );
	await line.locator( '.slashphoto-cart__unpick' ).first().click();
	await expect( line ).toContainText( '1 of 3 photos chosen' );

	// Codes: a wrong one is explained, the right one applied.
	const coupon = page.locator( '[data-slashphoto-coupon]' );
	await coupon.getByLabel( 'Discount code' ).fill( 'nope' );
	await coupon.getByRole( 'button', { name: 'Apply' } ).click();
	await expect( coupon.locator( '.slashphoto-coupon__message' ) ).toHaveText( 'This discount code is not valid.' );
	await coupon.getByLabel( 'Discount code' ).fill( 'demo10' );
	await coupon.getByLabel( 'Discount code' ).press( 'Enter' );
	await expect( coupon.locator( '.slashphoto-coupon__message' ) ).toHaveText( 'Code DEMO10 applied.' );
	await expect( page.locator( '.slashphoto-cart__totals' ) ).toContainText( 'Discount (DEMO10)' );
	await expect( page.locator( 'dd.slashphoto-cart__total' ) ).toHaveText( /630/ );

	const form = page.locator( '[data-slashphoto-checkout-form]' );
	await expect( page.locator( '[data-slashphoto-address]' ) ).toBeHidden();
	await form.getByLabel( 'Email', { exact: true } ).fill( 'e2e-package@example.org' );
	await form.getByLabel( 'Name', { exact: true } ).fill( 'E2E Package' );
	await form.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page ).toHaveURL( /[?&]order=\d+&key=/ );
	await expect( page.locator( '.slashphoto-order__totals' ) ).toContainText( 'Discount (DEMO10)' );
	expect( await page.evaluate( () => window.localStorage.getItem( 'slashphoto-coupon' ) ) ).toBeNull();

	// Invoice while unpaid.
	const invoiceLink = page.getByRole( 'link', { name: 'Invoice (print or save as PDF)' } );
	const invoice = await page.context().newPage();
	await invoice.goto( await invoiceLink.getAttribute( 'href' ) );
	await expect( invoice.getByRole( 'heading', { level: 1 } ) ).toHaveText( /^Invoice \d+$/ );
	await expect( invoice.locator( 'body' ) ).toContainText( 'Not paid' );
	await expect( invoice.locator( 'body' ) ).toContainText( 'Swish 123 456 78 90' );
	await expect( invoice.locator( 'body' ) ).toContainText( 'Demo Studio' );
	await invoice.close();

	// Paid: a receipt, and the package's photo can be downloaded.
	const id = new URL( page.url() ).searchParams.get( 'order' );
	wpEval( `SlashPhoto\\Shop\\Orders::mark_paid( ${ id } );` );
	await page.reload();
	await expect( page.locator( '.slashphoto-order__download' ) ).toHaveCount( 1 );
	const receipt = await page.context().newPage();
	await receipt.goto( await page.getByRole( 'link', { name: 'Receipt (print or save as PDF)' } ).getAttribute( 'href' ) );
	await expect( receipt.getByRole( 'heading', { level: 1 } ) ).toHaveText( /^Receipt \d+$/ );
	await expect( receipt.locator( '.status' ) ).toHaveText( 'Paid' );
	await expect( receipt.locator( 'body' ) ).not.toContainText( 'Swish 123 456 78 90' );
	await receipt.close();
} );
