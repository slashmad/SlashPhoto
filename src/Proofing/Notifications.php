<?php
/**
 * Email to the photographer when a selection arrives.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a short plain-text email to the gallery's author when a client
 * sends a selection. It names the client and the gallery and links to the
 * gallery's edit screen; the images and notes stay on the site.
 */
final class Notifications {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'slashphoto_selection_submitted', [ $this, 'selection_submitted' ], 10, 2 );
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
		$body .= __( 'See the selection and the file names:', 'slashphoto' ) . "\n" . admin_url( 'post.php?post=' . $root->ID . '&action=edit' ) . "#slashphoto-selections\n";

		$headers = [];
		if ( $client && is_email( $client->user_email ) ) {
			$headers[] = 'Reply-To: ' . $client->user_email;
		}

		wp_mail( $recipients, $subject, $body, $headers );
	}
}
