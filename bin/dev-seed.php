<?php
/**
 * Creates demo galleries in the development environment.
 *
 * Usage: npm run seed
 * Runs with "wp eval-file" inside wp-env; never ships in release zips.
 *
 * Real photos: put JPEGs in .dev-media/ (git-ignored) and they are used
 * instead of generated images. Panoramas with GPano XMP show up as 360°.
 *
 * @package SlashPhoto
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once dirname( __DIR__ ) . '/tests/Integration/TestImages.php';

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\Watermark;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Coupons;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Tests\Integration\TestImages;

/**
 * Uploads a generated JPEG as a child of $parent_id.
 *
 * @param string      $name      File name without extension.
 * @param int         $width     Width.
 * @param int         $height    Height.
 * @param int         $parent_id Gallery ID.
 * @param string|null $xmp       Optional XMP packet.
 */
function slashphoto_seed_image( string $name, int $width, int $height, int $parent_id, ?string $xmp = null ): int {
	return slashphoto_seed_file( TestImages::jpeg( $width, $height, $xmp ), $name . '.jpg', $parent_id );
}

/**
 * Sideloads a copy of a file as a child of $parent_id.
 *
 * @param string $path      Source path; a temporary copy is consumed.
 * @param string $name      File name for the media library.
 * @param int    $parent_id Gallery ID.
 */
function slashphoto_seed_file( string $path, string $name, int $parent_id ): int {
	$tmp = wp_tempnam( $name );
	copy( $path, $tmp );
	$file = [
		'name'     => $name,
		'tmp_name' => $tmp,
	];
	// Store in the gallery's random folder, like uploads in the editor.
	$id = Uploads::for_gallery( $parent_id, static fn() => media_handle_sideload( $file, $parent_id, pathinfo( $name, PATHINFO_FILENAME ) ) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * Deletes a gallery, its sub-galleries and their images.
 *
 * @param int $gallery_id Gallery ID.
 */
function slashphoto_seed_delete_gallery( int $gallery_id ): void {
	$children = get_posts(
		[
			'post_type'   => [ PostType::POST_TYPE, 'attachment' ],
			'post_status' => 'any',
			'post_parent' => $gallery_id,
			'numberposts' => -1,
			'fields'      => 'ids',
		]
	);
	foreach ( $children as $child_id ) {
		if ( 'attachment' === get_post_type( $child_id ) ) {
			wp_delete_attachment( $child_id, true );
		} else {
			slashphoto_seed_delete_gallery( $child_id );
		}
	}
	wp_delete_post( $gallery_id, true );
}

// Re-running the seed replaces the demo content instead of duplicating it.
foreach ( get_posts(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'any',
		'post_parent' => 0,
		'numberposts' => -1,
	]
) as $slashphoto_old ) {
	if ( str_starts_with( $slashphoto_old->post_name, 'demo-' ) ) {
		slashphoto_seed_delete_gallery( $slashphoto_old->ID );
	}
}
foreach ( get_posts(
	[
		'post_type'   => [ PriceLists::POST_TYPE, Coupons::POST_TYPE, 'page' ],
		'post_status' => 'any',
		'numberposts' => -1,
	]
) as $slashphoto_old ) {
	if ( str_starts_with( $slashphoto_old->post_name, 'demo-' ) ) {
		wp_delete_post( $slashphoto_old->ID, true );
	}
}
foreach ( get_posts(
	[
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'title'       => 'SlashPhoto demo watermark',
		'numberposts' => -1,
		'fields'      => 'ids',
	]
) as $slashphoto_old ) {
	wp_delete_attachment( $slashphoto_old, true );
}

$slashphoto_parent = wp_insert_post(
	[
		'post_type'    => PostType::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Demo: Summer wedding',
		'post_content' => '<!-- wp:paragraph --><p>Intro text written by the photographer.</p><!-- /wp:paragraph -->',
	]
);
$slashphoto_child  = wp_insert_post(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Ceremony',
		'post_parent' => $slashphoto_parent,
	]
);

// No GLOB_BRACE: it is missing on musl (Alpine) PHP builds.
$slashphoto_photos = array_values(
	array_filter(
		(array) glob( dirname( __DIR__ ) . '/.dev-media/*' ),
		static fn( string $path ): bool => (bool) preg_match( '/\.jpe?g$/i', $path )
	)
);

$slashphoto_cover = $slashphoto_photos
	? slashphoto_seed_file( $slashphoto_photos[0], 'ceremony-' . basename( $slashphoto_photos[0] ), $slashphoto_child )
	: slashphoto_seed_image( 'ceremony-cover', 1200, 800, $slashphoto_child );
set_post_thumbnail( $slashphoto_child, $slashphoto_cover );

if ( $slashphoto_photos ) {
	foreach ( $slashphoto_photos as $slashphoto_photo ) {
		WP_CLI::log( 'Importing ' . basename( $slashphoto_photo ) );
		slashphoto_seed_file( $slashphoto_photo, basename( $slashphoto_photo ), $slashphoto_parent );
	}
} else {
	foreach ( range( 1, 5 ) as $slashphoto_n ) {
		slashphoto_seed_image( "photo-{$slashphoto_n}", 1200, 0 === $slashphoto_n % 2 ? 1600 : 800, $slashphoto_parent );
	}
	slashphoto_seed_image( 'wide-panorama', 3600, 1000, $slashphoto_parent );
	slashphoto_seed_image( 'sphere-360', 4000, 2000, $slashphoto_parent, TestImages::gpano_xmp( 4000, 2000 ) );
}

// Protected examples, also used by the browser tests (tests/e2e/access.spec.mjs).
$slashphoto_password = wp_insert_post(
	[
		'post_type'     => PostType::POST_TYPE,
		'post_status'   => 'publish',
		'post_title'    => 'Demo: Password',
		'post_name'     => 'demo-password',
		'post_password' => 'demo',
	]
);
$slashphoto_terms    = wp_insert_post(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Demo: Terms',
		'post_name'   => 'demo-terms',
	]
);
update_post_meta( $slashphoto_terms, Terms::META_TERMS, 'Please do not share or edit these photos.' );
foreach ( [ $slashphoto_password, $slashphoto_terms ] as $slashphoto_protected ) {
	slashphoto_seed_image( 'protected-1', 1200, 800, $slashphoto_protected );
	slashphoto_seed_image( 'protected-2', 1200, 800, $slashphoto_protected );
}

// Watermark: the demo PNG becomes the site watermark (not attached to a gallery).
$slashphoto_mark_tmp = wp_tempnam( 'demo-watermark.png' );
copy( __DIR__ . '/demo-watermark.png', $slashphoto_mark_tmp );
$slashphoto_mark = media_handle_sideload(
	[
		'name'     => 'slashphoto-demo-watermark.png',
		'tmp_name' => $slashphoto_mark_tmp,
	],
	0,
	'SlashPhoto demo watermark'
);
update_option(
	Settings::OPTION,
	array_merge(
		Settings::all(),
		[
			'watermark_id'      => (int) $slashphoto_mark,
			'watermark_scale'   => 35,
			'watermark_opacity' => 45,
		]
	)
);
$slashphoto_watermarked = wp_insert_post(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Demo: Watermark',
		'post_name'   => 'demo-watermark',
	]
);
update_post_meta( $slashphoto_watermarked, Watermark::META, 'on' );
foreach ( array_slice( $slashphoto_photos, 0, 3 ) as $slashphoto_photo ) {
	slashphoto_seed_file( $slashphoto_photo, basename( $slashphoto_photo ), $slashphoto_watermarked );
}
if ( ! $slashphoto_photos ) {
	slashphoto_seed_image( 'watermarked', 1600, 1067, $slashphoto_watermarked );
}

// Secret link.
$slashphoto_linked = wp_insert_post(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Demo: Secret link',
		'post_name'   => 'demo-secret-link',
	]
);
update_post_meta( $slashphoto_linked, Access::META_MODE, Access::MODE_LINK );
slashphoto_seed_image( 'linked-1', 1200, 800, $slashphoto_linked );
slashphoto_seed_image( 'linked-2', 1200, 800, $slashphoto_linked );

// Proofing, with a sub-gallery sharing one selection. Development-only
// client account: demo-client / demo-client.
$slashphoto_client   = get_user_by( 'login', 'demo-client' );
$slashphoto_client   = $slashphoto_client ? $slashphoto_client->ID : wp_insert_user(
	[
		'user_login'   => 'demo-client',
		'user_pass'    => 'demo-client',
		'user_email'   => 'demo-client@example.org',
		'display_name' => 'Demo Client',
		'role'         => 'slashphoto_client',
	]
);
$slashphoto_proofing = wp_insert_post(
	[
		'post_type'    => PostType::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Demo: Choose your favourites',
		'post_name'    => 'demo-proofing',
		'post_content' => '<!-- wp:paragraph --><p>Mark the photos you want and send your selection.</p><!-- /wp:paragraph -->',
	]
);
update_post_meta( $slashphoto_proofing, Proofing::META, 'on' );
$slashphoto_proofing_child = wp_insert_post(
	[
		'post_type'   => PostType::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Getting ready',
		'post_name'   => 'getting-ready',
		'post_parent' => $slashphoto_proofing,
	]
);
foreach ( range( 1, 4 ) as $slashphoto_n ) {
	slashphoto_seed_image( "proof-{$slashphoto_n}", 1200, 800, $slashphoto_proofing );
}
slashphoto_seed_image( 'ready-1', 1200, 800, $slashphoto_proofing_child );
slashphoto_seed_image( 'ready-2', 800, 1200, $slashphoto_proofing_child );

// Shop: a price list, a cart page and a gallery for sale. The proofing
// gallery sells too, so both lightbox tools appear together.
$slashphoto_list = wp_insert_post(
	[
		'post_type'   => PriceLists::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Demo: Prints and downloads',
		'post_name'   => 'demo-prices',
	]
);
PriceLists::save_products(
	$slashphoto_list,
	[
		[
			'id'    => 'd0000001',
			'kind'  => 'download',
			'name'  => 'Digital download, full resolution',
			'price' => 30000,
		],
		[
			'kind'  => 'print',
			'name'  => 'Print 13 × 18 cm',
			'price' => 9900,
		],
		[
			'kind'  => 'print',
			'name'  => 'Print 20 × 30 cm',
			'price' => 19900,
		],
		[
			'kind'  => 'print',
			'name'  => 'Print 30 × 45 cm',
			'price' => 34900,
		],
		[
			'kind'     => 'package',
			'name'     => 'Three downloads',
			'price'    => 70000,
			'count'    => 3,
			'includes' => 'd0000001',
		],
		[
			'kind'     => 'package',
			'name'     => 'The whole gallery as downloads',
			'price'    => 90000,
			'count'    => 0,
			'includes' => 'd0000001',
		],
	]
);
$slashphoto_coupon = wp_insert_post(
	[
		'post_type'   => Coupons::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'DEMO10',
		'post_name'   => 'demo-coupon',
	]
);
Coupons::save_rules( $slashphoto_coupon, [ 'amount' => 10 ] );
$slashphoto_cart = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Cart',
		'post_name'    => 'demo-cart',
		'post_content' => '<!-- wp:shortcode -->[slashphoto_checkout]<!-- /wp:shortcode -->',
	]
);
update_option(
	Settings::OPTION,
	array_merge(
		Settings::all(),
		[
			'currency'            => 'SEK',
			'prices_include_tax'  => true,
			'tax_rate'            => 25,
			'shipping_flat'       => 4900,
			'free_shipping_over'  => 100000,
			'shipping_countries'  => 'SE, NO, DK, FI',
			'shop_page'           => (int) $slashphoto_cart,
			'manual_enabled'      => true,
			'manual_title'        => 'Pay later (Swish or invoice)',
			'manual_instructions' => 'Swish 123 456 78 90 and mark the payment with your order number.',
			'business_name'       => 'Demo Studio',
			'business_details'    => "Storgatan 1\n111 22 Stockholm\nOrg.nr 556000-0000",
		]
	)
);
$slashphoto_shop = wp_insert_post(
	[
		'post_type'    => PostType::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Demo: Shop',
		'post_name'    => 'demo-shop',
		'post_content' => '<!-- wp:paragraph --><p>Order prints or download the originals.</p><!-- /wp:paragraph -->',
	]
);
update_post_meta( $slashphoto_shop, PriceLists::GALLERY_META, (string) $slashphoto_list );
update_post_meta( $slashphoto_proofing, PriceLists::GALLERY_META, (string) $slashphoto_list );
foreach ( range( 1, 3 ) as $slashphoto_n ) {
	slashphoto_seed_image( "shop-{$slashphoto_n}", 1800, 1200, $slashphoto_shop );
}
slashphoto_seed_image( 'shop-portrait', 1200, 1800, $slashphoto_shop );

WP_CLI::success( 'Demo gallery: ' . get_permalink( $slashphoto_parent ) );
WP_CLI::success( 'Watermarked gallery: ' . get_permalink( $slashphoto_watermarked ) );
WP_CLI::success( 'Secret link: ' . Access::share_url( get_post( $slashphoto_linked ) ) );
WP_CLI::success( 'Password gallery (password "demo"): ' . get_permalink( $slashphoto_password ) );
WP_CLI::success( 'Terms gallery: ' . get_permalink( $slashphoto_terms ) );
WP_CLI::success( 'Proofing gallery (log in as demo-client / demo-client): ' . get_permalink( $slashphoto_proofing ) );
WP_CLI::success( 'Shop gallery: ' . get_permalink( $slashphoto_shop ) . ' (cart: ' . get_permalink( $slashphoto_cart ) . ')' );
