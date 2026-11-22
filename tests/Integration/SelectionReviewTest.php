<?php
/**
 * Admin selection review permissions and filtering.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Admin\SelectionsPage;
use SlashPhoto\Plugin;
use SlashPhoto\Proofing\Selections;

final class SelectionReviewTest extends AccessTestCase {

	private function gallery_author(): int {
		$id   = $this->as_user( 'author' );
		$user = wp_get_current_user();
		$user->add_cap( 'edit_pages' );
		$user->add_cap( 'edit_published_pages' );
		return $id;
	}

	public function test_overview_filters_by_editable_gallery_and_selection_status(): void {
		$editor     = $this->gallery_author();
		$admin      = $this->as_user( 'administrator' );
		$client     = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$other      = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$owned      = $this->gallery( [ 'post_author' => $editor ] );
		$foreign    = $this->gallery( [ 'post_author' => $admin ] );
		$empty      = $this->gallery( [ 'post_author' => $editor ] );
		$draft      = Selections::find_or_create( get_post( $owned ), $client );
		$submitted  = Selections::find_or_create( get_post( $owned ), $other );
		$restricted = Selections::find_or_create( get_post( $foreign ), $client );
		Selections::submit( $submitted, 'For the accessible gallery' );
		Selections::submit( $restricted, 'For another gallery' );

		wp_set_current_user( $editor );
		$overview = new SelectionsPage( Plugin::instance()->proofing() );
		$ids      = array_keys( $overview->galleries() );
		$this->assertSame( [ $owned ], $ids );
		$this->assertNotContains( $empty, $ids );
		$this->assertSame( [ $submitted->ID ], array_column( Selections::review_page( $ids, 'submitted', 1, 12 )->posts, 'ID' ) );
		$this->assertSame( [ $draft->ID ], array_column( Selections::review_page( $ids, 'choosing', 1, 12 )->posts, 'ID' ) );
		$page = Selections::review_page( $ids, 'all', 2, 1 );
		$this->assertSame( 2, (int) $page->found_posts );
		$this->assertCount( 1, $page->posts );

		wp_set_current_user( $client );
		$this->assertSame( [], $overview->galleries() );
	}

	public function test_saved_selection_excludes_foreign_and_locked_images(): void {
		$editor    = $this->gallery_author();
		$admin     = $this->as_user( 'administrator' );
		$client    = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$root      = $this->gallery( [ 'post_author' => $editor ] );
		$child     = $this->gallery(
			[
				'post_parent' => $root,
				'post_author' => $editor,
			]
		);
		$locked    = $this->gallery(
			[
				'post_parent'   => $root,
				'post_author'   => $admin,
				'post_password' => 'fixture-password',
			]
		);
		$elsewhere = $this->gallery( [ 'post_author' => $editor ] );
		$first     = $this->image( $root );
		$second    = $this->image( $child );
		$hidden    = $this->image( $locked );
		$moved     = $this->image( $elsewhere );
		$selection = Selections::find_or_create( get_post( $root ), $client );
		Selections::save_items(
			$selection,
			[
				$second => 'Second by ID, first chosen',
				$hidden => 'Private',
				$moved  => 'Moved',
				$first  => 'Original gallery',
			]
		);

		wp_set_current_user( $editor );
		$proofing = Plugin::instance()->proofing();
		$this->assertSame(
			[
				$second => 'Second by ID, first chosen',
				$first  => 'Original gallery',
			],
			$proofing->review_items( get_post( $root ), $selection )
		);
		$this->assertSame( [], $proofing->review_items( get_post( $elsewhere ), $selection ) );

		wp_set_current_user( $client );
		$this->assertSame( [], $proofing->review_items( get_post( $root ), $selection ) );
	}
}
