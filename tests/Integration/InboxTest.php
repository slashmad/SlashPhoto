<?php
/**
 * Importing photos dropped into the import folder.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\Inbox;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;

final class InboxTest extends AccessTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->clear( Inbox::dir() );
	}

	public function tear_down(): void {
		$this->clear( Inbox::dir() );
		delete_option( Settings::OPTION );
		delete_option( Inbox::STATUS );
		parent::tear_down();
	}

	private function clear( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	private function drop( string $folder, string $name, ?string $content = null, int $age = 120 ): string {
		$dir = Inbox::dir() . '/' . $folder;
		wp_mkdir_p( $dir );
		$path = $dir . '/' . $name;
		if ( null === $content ) {
			copy( TestImages::jpeg( 800, 600 ), $path );
		} else {
			file_put_contents( $path, $content );
		}
		touch( $path, time() - $age );
		return $path;
	}

	public function test_photos_go_into_the_named_gallery_or_a_new_draft(): void {
		$existing = $this->gallery( [ 'post_name' => 'smith-wedding' ] );
		$first    = $this->drop( 'smith-wedding', 'DSC_0002.jpg' );
		$second   = $this->drop( 'smith-wedding', 'DSC_0010.jpg' );
		$busy     = $this->drop( 'smith-wedding', 'DSC_0011.jpg', null, 5 );
		$sidecar  = $this->drop( 'smith-wedding', 'DSC_0002.xmp', '<x:xmpmeta/>' );
		$this->drop( 'Jansson portraits', 'portrait.jpg' );
		$this->drop( (string) $existing, 'by-id.jpg' );

		$runner = $this->as_user( 'administrator' );
		$report = Inbox::run( 60.0 );

		$this->assertSame( '', $report['error'] );
		$this->assertSame( 4, $report['imported'] );
		$this->assertSame( 1, $report['waiting'] );
		$this->assertSame( 1, $report['ignored'] );
		$this->assertFileDoesNotExist( $first );
		$this->assertFileDoesNotExist( $second );
		$this->assertFileExists( $busy, 'Still being copied.' );
		$this->assertFileExists( $sidecar, 'Other files stay.' );
		$titles = array_map( static fn( $image ): string => $image->post_title, ( new GalleryRepository() )->images( $existing ) );
		$this->assertEqualsCanonicalizing( [ 'DSC_0002', 'DSC_0010', 'by-id' ], $titles );

		$new = get_posts(
			[
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'any',
				'title'       => 'Jansson portraits',
			]
		);
		$this->assertCount( 1, $new );
		$this->assertSame( 'draft', $new[0]->post_status, 'Never public by itself.' );
		$this->assertSame( $runner, (int) $new[0]->post_author, 'Owned by whoever ran the import.' );
		$this->assertCount( 1, ( new GalleryRepository() )->images( $new[0]->ID ) );
		$this->assertFileExists( Inbox::dir() . '/.htaccess' );
		$this->assertSame( $report, get_option( Inbox::STATUS ) );
	}

	public function test_files_that_fail_are_kept_aside_not_lost(): void {
		$gallery = $this->gallery( [ 'post_name' => 'raw-test' ] );
		$this->drop( 'raw-test', 'broken.jpg', 'not an image' );
		$this->drop( 'raw-test', 'DSC_0001.ARW', 'raw bytes' );

		$report = Inbox::run( 60.0 );

		$this->assertSame( 0, $report['imported'] );
		$this->assertCount( 2, $report['failed'] );
		$this->assertFileExists( Inbox::dir() . '/_failed/raw-test/broken.jpg' );
		$this->assertSame( 'raw bytes', file_get_contents( Inbox::dir() . '/_failed/raw-test/DSC_0001.ARW' ), 'A RAW file without SnapOtter is kept, not deleted.' );
		$this->assertSame( [], ( new GalleryRepository() )->images( $gallery ) );

		// The _failed folder is not imported again.
		$this->assertSame( 0, Inbox::run( 60.0 )['imported'] );
	}

	public function test_dry_run_and_lock(): void {
		$this->gallery( [ 'post_name' => 'dry' ] );
		$file = $this->drop( 'dry', 'a.jpg' );
		$this->drop( 'not-yet-a-gallery', 'b.jpg' );

		$report = Inbox::run( 0.0, true );
		$this->assertSame( 2, $report['imported'] );
		$this->assertFileExists( $file );
		$this->assertEmpty(
			get_posts(
				[
					'post_type'   => PostType::POST_TYPE,
					'post_status' => 'any',
					'name'        => 'not-yet-a-gallery',
				]
			),
			'A dry run creates nothing.'
		);
		$this->assertFalse( get_option( Inbox::STATUS ) );

		set_transient( 'slashphoto_inbox_running', 1, 60 );
		$this->assertSame( 'An import is already running.', Inbox::run( 60.0 )['error'] );
		$this->assertFileExists( $file );
		delete_transient( 'slashphoto_inbox_running' );
	}

	public function test_enabling_records_the_owner_and_schedules(): void {
		$admin = $this->as_user( 'administrator' );
		update_option(
			Settings::OPTION,
			Settings::sanitize(
				[
					'_section'      => 'general_inbox',
					'inbox_enabled' => '1',
				]
			)
		);
		$this->assertTrue( Settings::get( 'inbox_enabled' ) );
		$this->assertSame( $admin, Settings::get( 'inbox_user' ) );
		Inbox::schedule();
		$this->assertNotFalse( wp_next_scheduled( Inbox::CRON ) );

		update_option( Settings::OPTION, Settings::sanitize( [ '_section' => 'general_inbox' ] ) );
		Inbox::schedule();
		$this->assertFalse( wp_next_scheduled( Inbox::CRON ) );
	}
}
