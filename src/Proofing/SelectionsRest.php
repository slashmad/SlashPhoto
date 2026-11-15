<?php
/**
 * REST API for client selections.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Proofing;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /slashphoto/v1/selections/{gallery} for the logged-in
 * client's own selection. Every request re-checks that the client can see
 * the gallery and each image; any failure looks the same as a gallery that
 * does not exist, so the API cannot be used to probe protected galleries.
 */
final class SelectionsRest {

	public const NAMESPACE = 'slashphoto/v1';

	/**
	 * Proofing rules.
	 *
	 * @var Proofing
	 */
	private Proofing $proofing;

	/**
	 * Constructor.
	 *
	 * @param Proofing $proofing Proofing rules.
	 */
	public function __construct( Proofing $proofing ) {
		$this->proofing = $proofing;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$base = '/selections/(?P<gallery>\d+)';
		$auth = [ $this, 'permission' ];

		register_rest_route(
			self::NAMESPACE,
			$base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_selection' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			$base . '/items',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $auth,
				'args'                => [
					'id'       => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
					'selected' => [
						'type'     => 'boolean',
						'required' => true,
					],
					'note'     => [
						'type'      => 'string',
						'maxLength' => Selections::MAX_NOTE_CHARS,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			$base . '/merge',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'merge' ],
				'permission_callback' => $auth,
				'args'                => [
					'ids' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'maxItems' => Selections::MAX_ITEMS,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			$base . '/submit',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => $auth,
				'args'                => [
					'message' => [
						'type'      => 'string',
						'maxLength' => Selections::MAX_MESSAGE_CHARS,
					],
				],
			]
		);
	}

	/**
	 * Logged-in clients who can see the gallery, and only on proofing roots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'Log in to save your selection.', 'slashphoto' ), [ 'status' => 401 ] );
		}
		$root = get_post( (int) $request['gallery'] );
		if ( ! $root || ! $this->proofing->can_select( $root ) ) {
			return self::not_found();
		}
		return true;
	}

	/**
	 * GET: the current user's selection (empty if none yet).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_selection( WP_REST_Request $request ): WP_REST_Response {
		$root = get_post( (int) $request['gallery'] );
		return rest_ensure_response( $this->state( $root, Selections::find( $root->ID, get_current_user_id() ) ) );
	}

	/**
	 * POST items: select or deselect one image, optionally with a note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$root = get_post( (int) $request['gallery'] );
		$id   = (int) $request['id'];
		if ( ! $this->proofing->image_allowed( $root, $id ) ) {
			return self::not_found();
		}

		$selected  = (bool) $request['selected'];
		$selection = $selected ? Selections::find_or_create( $root, get_current_user_id() ) : Selections::find( $root->ID, get_current_user_id() );
		if ( ! $selection ) {
			return $selected ? new WP_Error( 'slashphoto_selection_error', __( 'Your selection could not be saved.', 'slashphoto' ), [ 'status' => 500 ] ) : rest_ensure_response( $this->state( $root, null ) );
		}
		if ( Selections::submitted_at( $selection ) ) {
			return self::locked();
		}

		$items = $this->valid_items( $root, $selection );
		if ( $selected ) {
			$max = Proofing::max( $root );
			if ( ! isset( $items[ $id ] ) && ( count( $items ) >= Selections::MAX_ITEMS || ( $max && count( $items ) >= $max ) ) ) {
				return new WP_Error(
					'slashphoto_selection_full',
					/* translators: %d: maximum number of images */
					sprintf( _n( 'You can choose at most %d image.', 'You can choose at most %d images.', $max ? $max : Selections::MAX_ITEMS, 'slashphoto' ), $max ? $max : Selections::MAX_ITEMS ),
					[ 'status' => 409 ]
				);
			}
			$note         = $request->has_param( 'note' ) ? Selections::clean_text( (string) $request['note'], Selections::MAX_NOTE_CHARS ) : ( $items[ $id ] ?? '' );
			$items[ $id ] = $note;
		} else {
			unset( $items[ $id ] );
		}
		Selections::save_items( $selection, $items );

		return rest_ensure_response( $this->state( $root, get_post( $selection->ID ) ) );
	}

	/**
	 * POST merge: adds favourites the visitor chose before logging in. Images
	 * that are not allowed, or do not fit under the limit, are skipped.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge( WP_REST_Request $request ) {
		$root      = get_post( (int) $request['gallery'] );
		$ids       = array_unique( array_map( 'intval', (array) $request['ids'] ) );
		$selection = Selections::find( $root->ID, get_current_user_id() );
		if ( $selection && Selections::submitted_at( $selection ) ) {
			return self::locked();
		}

		$allowed = array_values( array_filter( $ids, fn( int $id ): bool => $this->proofing->image_allowed( $root, $id ) ) );
		if ( ! $allowed ) {
			return rest_ensure_response( $this->state( $root, $selection ) );
		}

		$selection = $selection ? $selection : Selections::find_or_create( $root, get_current_user_id() );
		if ( ! $selection ) {
			return new WP_Error( 'slashphoto_selection_error', __( 'Your selection could not be saved.', 'slashphoto' ), [ 'status' => 500 ] );
		}
		$items = $this->valid_items( $root, $selection );
		$limit = Proofing::max( $root ) ? min( Proofing::max( $root ), Selections::MAX_ITEMS ) : Selections::MAX_ITEMS;
		foreach ( $allowed as $id ) {
			if ( count( $items ) >= $limit ) {
				break;
			}
			$items[ $id ] ??= '';
		}
		Selections::save_items( $selection, $items );

		return rest_ensure_response( $this->state( $root, get_post( $selection->ID ) ) );
	}

	/**
	 * POST submit: sends the selection to the photographer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$root      = get_post( (int) $request['gallery'] );
		$selection = Selections::find( $root->ID, get_current_user_id() );
		if ( ! $selection || ! $this->valid_items( $root, $selection ) ) {
			return new WP_Error( 'slashphoto_selection_empty', __( 'Choose at least one image first.', 'slashphoto' ), [ 'status' => 400 ] );
		}
		if ( Selections::submitted_at( $selection ) ) {
			return self::locked();
		}

		Selections::submit( $selection, (string) $request['message'] );
		$selection = get_post( $selection->ID );

		/**
		 * Fires when a client sends a selection. SlashPhoto uses it to
		 * email the photographer.
		 *
		 * @param WP_Post $selection The selection.
		 * @param WP_Post $root      The proofing root gallery.
		 */
		do_action( 'slashphoto_selection_submitted', $selection, $root );

		return rest_ensure_response( $this->state( $root, $selection ) );
	}

	/**
	 * Items that are still allowed (images can be deleted or moved, and
	 * sub-galleries can be locked after they were chosen).
	 *
	 * @param WP_Post $root      Proofing root.
	 * @param WP_Post $selection Selection.
	 * @return array<int, string>
	 */
	private function valid_items( WP_Post $root, WP_Post $selection ): array {
		return array_filter(
			Selections::items( $selection ),
			fn( $note, $id ): bool => $this->proofing->image_allowed( $root, (int) $id ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Response body.
	 *
	 * @param WP_Post      $root      Proofing root.
	 * @param WP_Post|null $selection Selection.
	 * @return array<string, mixed>
	 */
	private function state( WP_Post $root, ?WP_Post $selection ): array {
		$items = [];
		foreach ( $selection ? $this->valid_items( $root, $selection ) : [] as $id => $note ) {
			$items[] = [
				'id'   => (int) $id,
				'note' => $note,
			];
		}
		$sent = $selection ? Selections::submitted_at( $selection ) : 0;
		return [
			'gallery'   => $root->ID,
			'items'     => $items,
			'max'       => Proofing::max( $root ),
			'submitted' => $sent ? gmdate( 'c', $sent ) : null,
			'message'   => $selection ? Selections::message( $selection ) : '',
		];
	}

	/**
	 * Same answer for "no such gallery" and "not yours to see".
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'slashphoto_not_found', __( 'Gallery not found.', 'slashphoto' ), [ 'status' => 404 ] );
	}

	/**
	 * The selection was sent and is read-only until the photographer reopens it.
	 */
	private static function locked(): WP_Error {
		return new WP_Error( 'slashphoto_selection_submitted', __( 'Your selection has been sent. Ask the photographer if you want to change it.', 'slashphoto' ), [ 'status' => 409 ] );
	}
}
