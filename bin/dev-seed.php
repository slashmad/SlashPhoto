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
use SlashPhoto\Settings;
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

WP_CLI::success( 'Demo gallery: ' . get_permalink( $slashphoto_parent ) );
WP_CLI::success( 'Watermarked gallery: ' . get_permalink( $slashphoto_watermarked ) );
WP_CLI::success( 'Secret link: ' . Access::share_url( get_post( $slashphoto_linked ) ) );
WP_CLI::success( 'Password gallery (password "demo"): ' . get_permalink( $slashphoto_password ) );
WP_CLI::success( 'Terms gallery: ' . get_permalink( $slashphoto_terms ) );
