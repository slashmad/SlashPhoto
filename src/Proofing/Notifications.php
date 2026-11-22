<?php
/**
 * Emails sent when a client sends a selection.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use SlashPhoto\Access\Access;
use SlashPhoto\Admin\GalleryWorkspace;
use SlashPhoto\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a short plain-text email to the gallery's author when a client
 * sends a selection. It names the client and the gallery and links to the
 * gallery's edit screen; the images and notes stay on the site. Unless
 * turned off, the client gets a copy of what they sent.
 */
final class Notifications {

	/**
	 * Uses the access rules to decide whether a gallery link works by itself.
	 *
	 * @param Access $access Gallery access rules.
	 */
	public function __construct( private Access $access ) {}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'slashphoto_selection_submitted', [ $this, 'selection_submitted' ], 10, 2 );
		add_action( 'slashphoto_selection_submitted', [ $this, 'client_confirmation' ], 20, 2 );
	}

	/**
	 * Emails the photographer.
	 *
	 * @param WP_Post $selection Selection.
	 * @param WP_Post $root      Proofing root gallery.
	 */
	public function selection_submitted( WP_Post $selection, WP_Post $root ): void {
		$author     = get_userdata( (int) $root->post_author );
		$recipients = $author ? [ $author->user_email ] : [ (string) get_option( 'admin_email' ) ];

		/**
		 * Filters who is emailed when a client sends a selection. Return an
		 * empty array to send no email.
		 *
		 * @param string[] $recipients Email addresses.
		 * @param WP_Post  $selection  The selection.
		 * @param WP_Post  $root       The gallery.
		 */
		$recipients = array_filter( (array) apply_filters( 'slashphoto_selection_recipients', $recipients, $selection, $root ), 'is_email' );
		if ( ! $recipients ) {
			return;
		}

		$client  = Selections::client( $selection );
		$name    = $client ? $client->display_name : __( 'A client', 'slashphoto' );
		$count   = count( Selections::items( $selection ) );
		$title   = wp_specialchars_decode( get_the_title( $root ), ENT_QUOTES );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$message = Selections::message( $selection );

		/* translators: 1: site name, 2: gallery title */
		$subject = sprintf( __( '[%1$s] New selection for %2$s', 'slashphoto' ), $site, $title );

		$body = sprintf(
			/* translators: 1: client name, 2: number of images, 3: gallery title */
			_n( '%1$s has sent a selection of %2$d image from "%3$s".', '%1$s has sent a selection of %2$d images from "%3$s".', $count, 'slashphoto' ),
			$name,
			$count,
			$title
		) . "\n\n";
		if ( '' !== $message ) {
			$body .= __( 'Message:', 'slashphoto' ) . "\n" . $message . "\n\n";
		}
		$body .= __( 'See the selection and the file names:', 'slashphoto' ) . "\n" . GalleryWorkspace::url( $root->ID, 'selections' ) . "\n";

		$headers = [];
		if ( $client && is_email( $client->user_email ) ) {
			$headers[] = 'Reply-To: ' . $client->user_email;
		}

		wp_mail( $recipients, $subject, $body, $headers );
	}

	/**
	 * Emails the client a copy of the selection they sent: the file names in
	 * their order, their notes and their message. Only the client's own
	 * account address is used; nothing new is stored.
	 *
	 * @param WP_Post $selection Selection.
	 * @param WP_Post $root      Proofing root gallery.
	 */
	public function client_confirmation( WP_Post $selection, WP_Post $root ): void {
		$client = Selections::client( $selection );
		if ( ! $client || ! is_email( $client->user_email ) ) {
			return;
		}

		/**
		 * Filters whether the client is emailed a copy of their selection.
		 *
		 * @param bool    $send      Whether to send the confirmation (the setting).
		 * @param WP_Post $selection The selection.
		 * @param WP_Post $root      The gallery.
		 */
		if ( ! apply_filters( 'slashphoto_selection_client_email', (bool) Settings::get( 'selection_client_email' ), $selection, $root ) ) {
			return;
		}

		$items   = Selections::items( $selection );
		$count   = count( $items );
		$title   = wp_specialchars_decode( get_the_title( $root ), ENT_QUOTES );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$message = Selections::message( $selection );

		/* translators: 1: site name, 2: gallery title */
		$subject = sprintf( __( '[%1$s] Your selection from %2$s', 'slashphoto' ), $site, $title );

		$body = sprintf(
			/* translators: 1: number of images, 2: gallery title */
			_n( 'Thank you. Your selection of %1$d image from "%2$s" has been sent to the photographer.', 'Thank you. Your selection of %1$d images from "%2$s" has been sent to the photographer.', $count, 'slashphoto' ),
			$count,
			$title
		) . "\n\n";
		foreach ( $items as $id => $note ) {
			$body .= '- ' . Proofing::file_label( $id ) . ( '' !== $note ? ': ' . $note : '' ) . "\n";
		}
		if ( $items ) {
			$body .= "\n";
		}
		if ( '' !== $message ) {
			$body .= __( 'Your message:', 'slashphoto' ) . "\n" . $message . "\n\n";
		}
		$body .= __( 'The photographer can reopen the selection if you want to change it.', 'slashphoto' ) . "\n";
		// A secret-link gallery's plain address does not open it; the client already has the link.
		if ( ! $this->access->link_only( $root ) ) {
			$body .= (string) get_permalink( $root ) . "\n";
		}

		$headers = [];
		$author  = get_userdata( (int) $root->post_author );
		if ( $author && is_email( $author->user_email ) && $author->ID !== $client->ID ) {
			$headers[] = 'Reply-To: ' . $author->user_email;
		}

		wp_mail( $client->user_email, $subject, $body, $headers );
	}
}
