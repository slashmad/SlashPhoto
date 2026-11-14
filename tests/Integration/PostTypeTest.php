<?php
/**
 * Gallery post type tests.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\PostType;
use WP_UnitTestCase;

final class PostTypeTest extends WP_UnitTestCase {

	public function test_gallery_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( PostType::POST_TYPE ) );

		$type = get_post_type_object( PostType::POST_TYPE );
		$this->assertTrue( $type->hierarchical, 'Sub-galleries need a hierarchical post type.' );
		$this->assertTrue( $type->show_in_rest, 'The block editor and REST API need show_in_rest.' );
		$this->assertTrue( $type->public );
	}

	public function test_plugin_boot_is_idempotent(): void {
		$before = has_filter( 'the_content' );
		\SlashPhoto\Plugin::instance()->boot();
		$this->assertSame( $before, has_filter( 'the_content' ) );
		$this->assertSame( 1, did_action( 'slashphoto_loaded' ) );
	}
}
