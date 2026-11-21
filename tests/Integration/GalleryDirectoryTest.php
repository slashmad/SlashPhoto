<?php
/**
 * Public gallery directory visibility and cover rules.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\EmailGate;
use SlashPhoto\Access\Terms;
use SlashPhoto\Frontend\GalleryDirectory;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;

final class GalleryDirectoryTest extends AccessTestCase {

	public function test_directory_never_discovers_restricted_galleries_or_uses_their_covers(): void {
		$editor   = $this->as_user( 'administrator' );
		$public   = $this->gallery( [ 'post_title' => 'Public island photos' ] );
		$private  = $this->clients_gallery( [ $editor ], [ 'post_title' => 'Private wedding' ] );
		$password = $this->gallery(
			[
				'post_title'    => 'Password wedding',
				'post_password' => 'fixture-password',
			]
		);
		$this->gallery(
			[
				'post_title'  => 'Password child',
				'post_parent' => $password,
			]
		);
		$this->gallery( [ 'post_title' => 'Secret link wedding' ], [ Access::META_MODE => Access::MODE_LINK ] );
		$this->gallery( [ 'post_title' => 'Login wedding' ], [ Access::META_MODE => Access::MODE_MEMBERS ] );
		$this->gallery( [ 'post_title' => 'Email wedding' ], [ EmailGate::META => 'on' ] );
		$this->gallery( [ 'post_title' => 'Terms wedding' ], [ Terms::META_TERMS => 'Fixture terms' ] );
		$this->gallery(
			[
				'post_title'  => 'Draft wedding',
				'post_status' => 'draft',
			]
		);
		$this->gallery( [ 'post_title' => 'Expiring wedding' ], [ Access::META_EXPIRES => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) ] );
		$cover   = $this->image( $public );
		$foreign = $this->image( $private );
		update_post_meta( $public, '_thumbnail_id', $foreign );
		$shortcode = sprintf( '[slashphoto_galleries layout="home" featured="%d"]', $private );
		$admin     = do_shortcode( $shortcode );

		$this->as_anonymous();
		$visitor = do_shortcode( $shortcode );
		foreach ( [ $admin, $visitor ] as $html ) {
			$this->assertStringContainsString( 'Public island photos', $html );
			$this->assertStringContainsString( esc_url( wp_get_attachment_url( $cover ) ), $html );
			$this->assertStringNotContainsString( 'wedding', $html );
			$this->assertStringNotContainsString( esc_url( wp_get_attachment_url( $foreign ) ), $html );
		}
	}

	public function test_unpublished_directory_is_never_linked_from_home(): void {
		$id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
			]
		);
		$this->as_anonymous();
		$html = do_shortcode( sprintf( '[slashphoto_galleries layout="home" directory="%d"]', $id ) );
		$this->assertStringContainsString( 'href="#slashphoto-collections-', $html );
		$this->assertStringContainsString( 'No public galleries are available yet.', $html );
		$this->assertStringNotContainsString( esc_url( get_permalink( $id ) ), $html );
	}

	public function test_archive_uses_only_an_enabled_public_directory_page(): void {
		$this->gallery();
		$id      = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '[slashphoto_galleries]',
			]
		);
		$archive = get_post_type_archive_link( PostType::POST_TYPE );
		$this->go_to( $archive );
		$this->assertSame( '', GalleryDirectory::archive_target() );

		update_option( Settings::OPTION, [ 'gallery_directory_page' => $id ] );
		$this->assertSame( get_permalink( $id ), GalleryDirectory::archive_target() );
		$this->assertStringContainsString( esc_url( get_permalink( $id ) ), do_shortcode( '[slashphoto_galleries layout="home"]' ) );

		$this->go_to( add_query_arg( 'feed', 'rss2', $archive ) );
		$this->assertSame( '', GalleryDirectory::archive_target() );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '', GalleryDirectory::archive_target() );

		$this->go_to( $archive );
		wp_update_post(
			[
				'ID'            => $id,
				'post_password' => 'test-only',
			]
		);
		$this->assertSame( '', GalleryDirectory::archive_target() );
		wp_update_post(
			[
				'ID'            => $id,
				'post_password' => '',
				'post_content'  => 'Directory removed',
			]
		);
		$this->assertSame( '', GalleryDirectory::archive_target() );
		delete_option( Settings::OPTION );
	}
}
