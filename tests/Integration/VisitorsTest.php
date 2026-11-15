<?php
/**
 * Visitor features: logged-in mode, email gate, terms log, view counts.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\EmailGate;
use SlashPhoto\Access\Terms;
use SlashPhoto\Admin\VisitorsPage;
use SlashPhoto\Plugin;
use SlashPhoto\Privacy;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Schema;
use SlashPhoto\Visitors\Stats;
use SlashPhoto\Visitors\VisitorLog;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reading counts back.

final class VisitorsTest extends AccessTestCase {

	public function tear_down(): void {
		$_POST = [];
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( $name, EmailGate::COOKIE_PREFIX ) ) {
				unset( $_COOKIE[ $name ] );
			}
		}
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	private function gate(): EmailGate {
		return new EmailGate( Plugin::instance()->access() );
	}

	private function submit_gate( int $gallery, array $post ): void {
		$_POST = array_merge(
			[
				'gallery'  => $gallery,
				'_wpnonce' => wp_create_nonce( EmailGate::ACTION . '_' . $gallery ),
			],
			$post
		);
		add_action(
			'slashphoto_terms_handled',
			static function (): void {
				throw new \RuntimeException( 'handled' );
			}
		);
		try {
			$this->gate()->handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		remove_all_actions( 'slashphoto_terms_handled' );
	}

	private function views( int $gallery, int $image = 0 ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(views) FROM ' . Schema::stats() . ' WHERE gallery_id = %d AND image_id = %d', $gallery, $image ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function beacon( int $gallery, int $image = 0, string $agent = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0' ) {
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/stats' );
		$request->set_param( 'gallery', $gallery );
		$request->set_param( 'image', $image );
		$request->set_header( 'User-Agent', $agent );
		return rest_get_server()->dispatch( $request );
	}

	public function test_logged_in_mode_needs_any_account(): void {
		$gallery = get_post( $this->gallery( [], [ Access::META_MODE => Access::MODE_MEMBERS ] ) );
		$child   = get_post( $this->gallery( [ 'post_parent' => $gallery->ID ] ) );
		$access  = Plugin::instance()->access();

		$this->as_anonymous();
		$this->assertFalse( $access->can_view( $gallery ) );
		$this->assertFalse( $access->can_view( $child ), 'Sub-galleries inherit it.' );
		$this->assertTrue( $access->is_protected( $gallery ) );

		$this->as_user( 'subscriber' );
		$access->flush();
		$this->assertTrue( $access->can_view( $child ) );
	}

	public function test_email_gate_asks_once_and_logs_the_address(): void {
		$gallery = $this->gallery( [], [ EmailGate::META => 'on' ] );
		$child   = $this->gallery( [ 'post_parent' => $gallery ] );
		$off     = $this->gallery( [ 'post_parent' => $gallery ], [ EmailGate::META => 'off' ] );
		$this->image( $child );
		$this->as_anonymous();

		$this->assertTrue( $this->gate()->needs_email( get_post( $child ) ) );
		$this->assertFalse( $this->gate()->needs_email( get_post( $off ) ) );
		$html = Plugin::instance()->renderer()->render( get_post( $child ) );
		$this->assertStringContainsString( 'name="consent"', $html );
		$this->assertStringNotContainsString( 'slashphoto-grid', $html, 'No photos before the address.' );
		$this->assertSame( [], Plugin::instance()->shop()->offer( get_post( $child ) ), 'Nothing for sale behind the gate.' );

		// Without consent nothing is stored.
		$this->submit_gate( $child, [ 'email' => 'kim@example.org' ] );
		$this->assertSame( 0, VisitorLog::query( [] )['total'] );

		$this->submit_gate(
			$child,
			[
				'email'   => 'Kim@Example.org',
				'consent' => '1',
			]
		);
		$rows = VisitorLog::query( [ 'type' => 'email' ] )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $gallery, (int) $rows[0]['gallery_id'], 'Logged for the gallery that asks.' );
		$this->assertSame( 'kim@example.org', $rows[0]['email'] );
		$this->assertFalse( $this->gate()->needs_email( get_post( $child ) ) );
		$this->assertSame( 'kim@example.org', $this->gate()->email( get_post( $child ) ) );

		$_COOKIE[ EmailGate::COOKIE_PREFIX . $gallery ] = $rows[0]['id'] . '.' . str_repeat( 'a', 64 );
		$this->assertTrue( $this->gate()->needs_email( get_post( $child ) ), 'Forged cookies do not pass.' );
	}

	public function test_logged_in_visitors_pass_and_are_logged_once(): void {
		$gallery = get_post( $this->gallery( [], [ EmailGate::META => 'on' ] ) );
		$user    = self::factory()->user->create( [ 'user_email' => 'member@example.org' ] );
		wp_set_current_user( $user );
		$this->assertFalse( $this->gate()->needs_email( $gallery ) );
		$this->assertFalse( $this->gate()->needs_email( $gallery ) );
		$rows = VisitorLog::query( [] )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $user, (int) $rows[0]['user_id'] );
	}

	public function test_gate_comes_before_terms_and_terms_use_its_address(): void {
		$gallery = $this->gallery(
			[],
			[
				EmailGate::META   => 'on',
				Terms::META_TERMS => 'No commercial use.',
			]
		);
		$terms   = Plugin::instance()->renderer();
		$this->as_anonymous();
		$this->assertStringContainsString( 'name="consent"', $terms->render( get_post( $gallery ) ) );

		$this->submit_gate(
			$gallery,
			[
				'email'   => 'kim@example.org',
				'consent' => '1',
			]
		);
		$html = $terms->render( get_post( $gallery ) );
		$this->assertStringContainsString( 'No commercial use.', $html );
		$this->assertStringNotContainsString( 'name="email"', $html, 'The address is known already.' );
	}

	public function test_view_counts_are_anonymous_daily_totals(): void {
		$gallery = $this->gallery();
		$image   = $this->image( $gallery );
		$other   = $this->image( $this->gallery() );
		$this->as_anonymous();

		$this->assertSame( 204, $this->beacon( $gallery )->get_status() );
		$this->beacon( $gallery );
		$this->beacon( $gallery, $image );
		$this->beacon( $gallery, $other );
		$this->beacon( $gallery, 0, 'Googlebot/2.1' );
		$this->assertSame( 2, $this->views( $gallery ) );
		$this->assertSame( 1, $this->views( $gallery, $image ) );
		$this->assertSame( 0, $this->views( $gallery, $other ), 'Only photos of that gallery.' );

		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::stats() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( [ 'day', 'gallery_id', 'image_id', 'views' ], $columns, 'Nothing about the visitor.' );

		$this->as_user( 'administrator' );
		$this->beacon( $gallery );
		$this->assertSame( 2, $this->views( $gallery ), 'Editors are not counted.' );

		$this->as_anonymous();
		$protected = $this->gallery( [ 'post_password' => 'x' ] );
		$this->beacon( $protected );
		$this->assertSame( 0, $this->views( $protected ) );
		$this->assertSame( 204, $this->beacon( 999999 )->get_status(), 'Same answer for galleries that do not exist.' );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'stats_enabled' => false ] ) );
		$this->beacon( $gallery );
		$this->assertSame( 2, $this->views( $gallery ) );
		$this->assertSame( [ $gallery ], array_column( Stats::galleries( wp_date( 'Y-m-d' ) ), 'gallery_id' ) );
	}

	public function test_retention_privacy_tools_and_csv(): void {
		$gallery = $this->gallery();
		$old     = VisitorLog::add( 'email', $gallery, 'old@example.org' );
		VisitorLog::add( 'terms', $gallery, 'kim@example.org', 'abc' );
		VisitorLog::add( 'email', $gallery, 'kim@example.org' );
		global $wpdb;
		$wpdb->update( Schema::visitors(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ], [ 'id' => $old ] );

		VisitorLog::cleanup();
		$this->assertNull( VisitorLog::get( $old ), 'Older than 365 days.' );

		$export = Privacy::export_visitors( 'kim@example.org' );
		$this->assertCount( 2, $export['data'] );
		$this->assertTrue( Privacy::erase_visitors( 'kim@example.org' )['items_removed'] );
		$this->assertSame( 0, VisitorLog::query( [] )['total'] );

		$this->assertSame( "'=HYPERLINK(1)", VisitorsPage::csv_safe( '=HYPERLINK(1)' ) );
		$this->assertSame( 'kim@example.org', VisitorsPage::csv_safe( 'kim@example.org' ) );
	}
}
