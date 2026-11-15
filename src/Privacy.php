<?php
/**
 * Privacy policy text.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests privacy policy text (Settings > Privacy) describing exactly what
 * SlashPhoto stores: no visitor logging, no tracking, functional cookies only.
 * Client selections are included in the WordPress personal data export and
 * erasure tools (Tools > Export / Erase Personal Data).
 */
final class Privacy {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Adds the suggested text.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text from SlashPhoto. It describes the plugin as shipped; review it if you add other plugins.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Our photo galleries do not log visits, IP addresses or browser details, do not use analytics or tracking, and do not load anything from other websites.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you open a password-protected gallery, WordPress stores a cookie (wp-postpass) containing a scrambled form of the password, for up to 10 days.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you accept a gallery’s terms, a cookie (slashphoto_terms_…) remembers this in your browser for 30 days. It contains only a signature of the gallery and the terms wording; nothing about you is stored on our server.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you open a gallery through a private link, a cookie (slashphoto_link_…) remembers this in your browser for 30 days. It contains only a signature of the gallery and the link; nothing about you is stored on our server.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you mark favourites without being logged in, they are kept only in your own browser (local storage) and never sent to us.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you choose images while logged in, we store your choice, any notes you write and the message you send with it, together with your account, so we can deliver your photos. It is deleted with your account or when we delete the gallery, and you can ask for a copy or for it to be erased.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If we give you a client account, your name, email address and the galleries you may see are stored in that account until you ask us to delete it.', 'slashphoto' ) . '</p>';
		wp_add_privacy_policy_content( 'SlashPhoto', wp_kses_post( $text ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['slashphoto-selections'] = [
			'exporter_friendly_name' => __( 'Photo selections', 'slashphoto' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['slashphoto-selections'] = [
			'eraser_friendly_name' => __( 'Photo selections', 'slashphoto' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Exports the selections of the account with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = [];
		foreach ( Selections::for_user( $user ? $user->ID : 0 ) as $selection ) {
			$images = [];
			foreach ( Selections::items( $selection ) as $id => $note ) {
				$images[] = Proofing::file_label( (int) $id ) . ( '' !== $note ? ' (' . $note . ')' : '' );
			}
			$sent   = Selections::submitted_at( $selection );
			$data[] = [
				'group_id'    => 'slashphoto-selections',
				'group_label' => __( 'Photo selections', 'slashphoto' ),
				'item_id'     => 'slashphoto-selection-' . $selection->ID,
				'data'        => [
					[
						'name'  => __( 'Gallery', 'slashphoto' ),
						'value' => get_the_title( $selection->post_parent ),
					],
					[
						'name'  => __( 'Images', 'slashphoto' ),
						'value' => implode( ', ', $images ),
					],
					[
						'name'  => __( 'Message', 'slashphoto' ),
						'value' => Selections::message( $selection ),
					],
					[
						'name'  => __( 'Sent', 'slashphoto' ),
						'value' => $sent ? wp_date( 'Y-m-d H:i', $sent ) : '',
					],
				],
			];
		}//end foreach
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erases the selections of the account with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		foreach ( Selections::for_user( $user ? $user->ID : 0 ) as $selection ) {
			$removed = (bool) wp_delete_post( $selection->ID, true ) || $removed;
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
