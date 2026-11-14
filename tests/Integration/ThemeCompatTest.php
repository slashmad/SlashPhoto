<?php
/**
 * Theme compatibility tests.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\PostType;
use WP_UnitTestCase;

final class ThemeCompatTest extends WP_UnitTestCase {

	/**
	 * Renders the main-query content of a URL like a theme's loop does.
	 *
	 * @param string $url Front-end URL.
	 */
	private function render_content( string $url ): string {
		$this->go_to( $url );
		$output = '';
		while ( have_posts() ) {
			the_post();
			$output .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		return $output;
	}

	private function gallery_with_image( array $postarr = [] ): array {
		$gallery_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'    => PostType::POST_TYPE,
					'post_title'   => 'Wedding',
					'post_content' => '<p>Intro text</p>',
				],
				$postarr
			)
		);
		$image_id   = self::factory()->attachment->create_upload_object( TestImages::jpeg( 600, 400 ), $gallery_id );
		return [ $gallery_id, $image_id ];
	}

	public function test_gallery_is_appended_after_the_theme_content(): void {
		[ $gallery_id, $image_id ] = $this->gallery_with_image();

		$html = $this->render_content( get_permalink( $gallery_id ) );

		$this->assertStringContainsString( 'Intro text', $html );
		$this->assertStringContainsString( 'class="slashphoto slashphoto-gallery', $html );
		$this->assertStringContainsString( 'data-slashphoto-id="' . $image_id . '"', $html );
		$this->assertLessThan( strpos( $html, 'slashphoto-gallery' ), strpos( $html, 'Intro text' ), 'Gallery must follow the author\'s content.' );
		$this->assertSame( 1, substr_count( $html, 'slashphoto-gallery' ) );
	}

	public function test_gallery_is_not_appended_to_password_protected_galleries(): void {
		[ $gallery_id ] = $this->gallery_with_image( [ 'post_password' => 'secret' ] );

		$html = $this->render_content( get_permalink( $gallery_id ) );

		$this->assertStringNotContainsString( 'slashphoto-gallery', $html );
	}

	public function test_gallery_is_not_appended_outside_the_main_loop(): void {
		[ $gallery_id ] = $this->gallery_with_image();
		$this->go_to( get_permalink( $gallery_id ) );

		// For example an SEO plugin building a description in wp_head.
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $gallery_id ) );

		$this->assertStringNotContainsString( 'slashphoto-gallery', $html );
	}

	public function test_appending_can_be_disabled_by_themes(): void {
		[ $gallery_id ] = $this->gallery_with_image();
		add_filter( 'slashphoto_append_gallery_to_content', '__return_false' );

		$html = $this->render_content( get_permalink( $gallery_id ) );

		$this->assertStringNotContainsString( 'slashphoto-gallery', $html );
	}

	public function test_sub_galleries_are_listed(): void {
		$parent_id = self::factory()->post->create( [ 'post_type' => PostType::POST_TYPE ] );
		self::factory()->post->create(
			[
				'post_type'   => PostType::POST_TYPE,
				'post_parent' => $parent_id,
				'post_title'  => 'Ceremony',
			]
		);

		$html = $this->render_content( get_permalink( $parent_id ) );

		$this->assertStringContainsString( 'slashphoto-albums', $html );
		$this->assertStringContainsString( 'Ceremony', $html );
	}

	public function test_template_falls_back_to_plugin_and_can_be_filtered(): void {
		$loader = new TemplateLoader();
		$this->assertSame( SLASHPHOTO_DIR . 'templates/gallery.php', $loader->locate( 'gallery.php' ) );
		$this->assertSame( '', $loader->locate( '../slashphoto.php' ), 'Path traversal must be refused.' );

		$custom = wp_tempnam( 'slashphoto-template' );
		file_put_contents( $custom, '<p class="custom-gallery"><?php echo count( $args["items"] ); ?></p>' );
		add_filter(
			'slashphoto_locate_template',
			static fn( string $path, string $name ): string => 'gallery.php' === $name ? $custom : $path,
			10,
			2
		);

		[ $gallery_id ] = $this->gallery_with_image();
		$html           = $this->render_content( get_permalink( $gallery_id ) );
		unlink( $custom );

		$this->assertStringContainsString( '<p class="custom-gallery">1</p>', $html );
	}

	public function test_gallery_uses_theme_wide_alignment_and_is_filterable(): void {
		[ $gallery_id ] = $this->gallery_with_image();
		add_theme_support( 'align-wide' );

		$this->assertStringContainsString( 'slashphoto-gallery alignwide"', $this->render_content( get_permalink( $gallery_id ) ) );

		add_filter( 'slashphoto_gallery_align', static fn(): string => 'full' );
		$this->assertStringContainsString( 'slashphoto-gallery alignfull"', $this->render_content( get_permalink( $gallery_id ) ) );

		add_filter( 'slashphoto_gallery_align', static fn(): string => 'bogus', 20 );
		$this->assertStringContainsString( 'class="slashphoto slashphoto-gallery">', $this->render_content( get_permalink( $gallery_id ) ) );
	}

	public function test_body_class_and_styles_on_gallery_pages(): void {
		[ $gallery_id ] = $this->gallery_with_image();
		$this->go_to( get_permalink( $gallery_id ) );

		$this->assertContains( 'slashphoto-page', get_body_class() );

		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'slashphoto-frontend', 'enqueued' ) );
	}

	public function test_styles_are_not_loaded_on_other_pages(): void {
		$this->go_to( get_permalink( self::factory()->post->create() ) );

		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_style_is( 'slashphoto-frontend', 'enqueued' ) );
	}

	public function tear_down(): void {
		wp_dequeue_style( 'slashphoto-frontend' );
		parent::tear_down();
	}
}
