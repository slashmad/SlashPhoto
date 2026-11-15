<?php
/**
 * Share links in the editor.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Gallery\PostType;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes a gallery's share link to editors over REST and lets them replace
 * it. The key itself is never writable as plain post meta.
 */
final class ShareLinks {

	public const FIELD = 'slashphoto_share_url';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	/**
	 * Registers the read-only field and the "new link" route.
	 */
	public function register(): void {
		register_rest_field(
			PostType::POST_TYPE,
			self::FIELD,
			[
				'get_callback' => static function ( array $post ): string {
					$gallery = get_post( (int) $post['id'] );
					return $gallery && current_user_can( 'edit_post', $gallery->ID ) ? Access::share_url( $gallery ) : '';
				},
				'schema'       => [
					'description' => __( 'Secret link for galleries in share-link mode.', 'slashphoto' ),
					'type'        => 'string',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);

		register_rest_route(
			'slashphoto/v1',
			'/galleries/(?P<id>\d+)/share-link',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rotate' ],
				'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Creates a new link; the old one and everyone who used it are locked out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function rotate( WP_REST_Request $request ) {
		$gallery = get_post( (int) $request['id'] );
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'slashphoto' ), [ 'status' => 404 ] );
		}
		Access::rotate_link_key( $gallery );
		return [ 'url' => Access::share_url( $gallery ) ];
	}
}
