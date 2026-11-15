<?php
/**
 * Gallery settings stored as post meta.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Media\Watermark;

defined( 'ABSPATH' ) || exit;

/**
 * Registers gallery meta for the block editor (REST) with sanitising and
 * capability checks, and the "Photo client" role.
 *
 * The client list is exposed in the REST "edit" context only, so visitors
 * cannot read which accounts have access to a gallery.
 */
final class GalleryMeta {

	public const CLIENT_ROLE = 'slashphoto_client';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ self::class, 'ensure_role' ] );
	}

	/**
	 * Registers the meta fields.
	 */
	public function register(): void {
		$auth = static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', $post_id );

		register_post_meta(
			PostType::POST_TYPE,
			Access::META_MODE,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => Access::MODE_PUBLIC,
				'sanitize_callback' => static fn( $value ): string => in_array( $value, [ Access::MODE_CLIENTS, Access::MODE_LINK ], true ) ? $value : Access::MODE_PUBLIC,
				'auth_callback'     => $auth,
				'show_in_rest'      => [
					'schema' => [
						'type' => 'string',
						'enum' => [ Access::MODE_PUBLIC, Access::MODE_CLIENTS, Access::MODE_LINK ],
					],
				],
			]
		);

		register_post_meta(
			PostType::POST_TYPE,
			Watermark::META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => static fn( $value ): string => in_array( $value, [ 'on', 'off' ], true ) ? $value : '',
				'auth_callback'     => $auth,
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'string',
						'enum'    => [ '', 'on', 'off' ],
						'context' => [ 'edit' ],
					],
				],
			]
		);

		register_post_meta(
			PostType::POST_TYPE,
			Access::META_CLIENTS,
			[
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'sanitize_callback' => [ self::class, 'sanitize_clients' ],
				'auth_callback'     => $auth,
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'array',
						'items'   => [ 'type' => 'integer' ],
						'context' => [ 'edit' ],
					],
				],
			]
		);

		register_post_meta(
			PostType::POST_TYPE,
			Access::META_EXPIRES,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ self::class, 'sanitize_date' ],
				'auth_callback'     => $auth,
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'string',
						'context' => [ 'edit' ],
					],
				],
			]
		);

		register_post_meta(
			PostType::POST_TYPE,
			Terms::META_TERMS,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => static fn( $value ): string => wp_kses_post( (string) $value ),
				'auth_callback'     => $auth,
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'string',
						'context' => [ 'edit' ],
					],
				],
			]
		);
	}

	/**
	 * Keeps only IDs of existing users.
	 *
	 * @param mixed $value Submitted value.
	 * @return int[]
	 */
	public static function sanitize_clients( $value ): array {
		$ids = array_unique( array_filter( array_map( 'absint', (array) $value ) ) );
		return array_values( array_filter( $ids, static fn( int $id ): bool => (bool) get_userdata( $id ) ) );
	}

	/**
	 * Accepts YYYY-MM-DD or an empty string.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_date( $value ): string {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Adds the "Photo client" role: can log in and read, nothing else.
	 */
	public static function ensure_role(): void {
		if ( ! get_role( self::CLIENT_ROLE ) ) {
			add_role( self::CLIENT_ROLE, __( 'Photo client', 'slashphoto' ), [ 'read' => true ] );
		}
	}
}
