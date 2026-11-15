<?php
/**
 * Import screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Import\SunshineImporter;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Import from Sunshine (shown when the site has Sunshine
 * Photo Cart data). The import runs in short steps through the REST API,
 * so large sites do not hit PHP's time limit; the page keeps calling until
 * it is done. `wp slashphoto import-sunshine` does the same from the shell.
 */
final class ImportPage {

	public const SLUG = 'slashphoto-import';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'slashphoto import-sunshine', [ self::class, 'cli' ] );
		}
	}

	/**
	 * Adds the page when there is something to import.
	 */
	public function menu(): void {
		if ( ! SunshineImporter::has_data() ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Import from Sunshine', 'slashphoto' ),
			__( 'Import from Sunshine', 'slashphoto' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * POST /slashphoto/v1/import/sunshine { restart? } → progress.
	 */
	public function register_routes(): void {
		register_rest_route(
			'slashphoto/v1',
			'/import/sunshine',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'step' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => [
					'restart' => [ 'type' => 'boolean' ],
				],
			]
		);
	}

	/**
	 * Runs one step of the import.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function step( WP_REST_Request $request ): WP_REST_Response {
		if ( $request['restart'] ) {
			SunshineImporter::restart();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed on every host.
		}
		return new WP_REST_Response( SunshineImporter::run( 15 ) );
	}

	/**
	 * Imports everything from Sunshine Photo Cart (copies; Sunshine is not changed).
	 *
	 * ## OPTIONS
	 *
	 * [--restart]
	 * : Start from the first step again. Things already imported are skipped.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public static function cli( $args, $assoc_args ): void {
		unset( $args );
		if ( ! empty( $assoc_args['restart'] ) ) {
			SunshineImporter::restart();
		}
		do {
			$state = SunshineImporter::run( 30 );
			\WP_CLI::log( sprintf( '%s: %s', $state['step'], wp_json_encode( $state['done'] ) ) );
		} while ( 'done' !== $state['step'] );
		foreach ( $state['notes'] as $note ) {
			\WP_CLI::warning( $note );
		}
		\WP_CLI::success( 'Import finished.' );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$summary = SunshineImporter::summary();
		$state   = $summary['state'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import from Sunshine Photo Cart', 'slashphoto' ); ?></h1>
			<p><?php esc_html_e( 'Copies your Sunshine galleries, images, prices and orders into SlashPhoto. Sunshine’s own data is only read and never changed, so you can compare both before you switch Sunshine off. You can run the import again: what is already imported is skipped.', 'slashphoto' ); ?></p>

			<h2><?php esc_html_e( 'Found on this site', 'slashphoto' ); ?></h2>
			<ul class="ul-disc">
				<?php /* translators: %d: number */ ?>
				<li><?php echo esc_html( sprintf( _n( '%d gallery', '%d galleries', $summary['galleries'], 'slashphoto' ), $summary['galleries'] ) ); ?></li>
				<?php /* translators: %d: number */ ?>
				<li><?php echo esc_html( sprintf( _n( '%d image', '%d images', $summary['images'], 'slashphoto' ), $summary['images'] ) ); ?></li>
				<?php /* translators: %d: number */ ?>
				<li><?php echo esc_html( sprintf( _n( '%d product', '%d products', $summary['products'], 'slashphoto' ), $summary['products'] ) ); ?></li>
				<?php /* translators: %d: number */ ?>
				<li><?php echo esc_html( sprintf( _n( '%d price level', '%d price levels', $summary['levels'], 'slashphoto' ), $summary['levels'] ) ); ?></li>
				<?php /* translators: %d: number */ ?>
				<li><?php echo esc_html( sprintf( _n( '%d order', '%d orders', $summary['orders'], 'slashphoto' ), $summary['orders'] ) ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'How things are carried over', 'slashphoto' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Each price level becomes a price list with the products that have a price at that level; galleries keep their price level. Downloads stay downloads, everything else becomes a print.', 'slashphoto' ); ?></li>
				<li><?php esc_html_e( 'Galleries keep their title, text, order, sub-galleries, password, private clients, expiry date and terms. Galleries opened with a direct URL get a secret link; “Provide email address” becomes an email request and “Registered and logged in” the logged-in mode. Email addresses visitors gave and terms agreements Sunshine recorded go to SlashPhoto → Visitors.', 'slashphoto' ); ?></li>
				<li><?php esc_html_e( 'Images are copied: each gets a display copy and its original is stored privately, exactly like new uploads. This needs disk space for a second copy until you remove Sunshine.', 'slashphoto' ); ?></li>
				<li><?php esc_html_e( 'Orders are copied with their lines and amounts for your records. Customers do not get emails, and their old Sunshine order links do not carry over.', 'slashphoto' ); ?></li>
			</ul>

			<div id="slashphoto-import" data-rest="<?php echo esc_url( rest_url( 'slashphoto/v1/import/sunshine' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<p>
					<?php if ( 'done' === $state['step'] ) : ?>
						<button type="button" class="button" data-restart><?php esc_html_e( 'Look for new Sunshine content again', 'slashphoto' ); ?></button>
					<?php else : ?>
						<button type="button" class="button button-primary" data-start><?php echo esc_html( 'prices' === $state['step'] && ! array_sum( $state['done'] ) ? __( 'Start the import', 'slashphoto' ) : __( 'Continue the import', 'slashphoto' ) ); ?></button>
					<?php endif; ?>
				</p>
				<p data-status aria-live="polite">
					<?php
					if ( 'done' === $state['step'] ) {
						esc_html_e( 'The import is complete.', 'slashphoto' );
					}
					?>
				</p>
				<?php /* translators: 1: price lists, 2: galleries, 3: images, 4: orders, 5: visitor log entries */ ?>
				<p data-counts data-template="<?php echo esc_attr( __( 'Imported so far: %1$d price lists, %2$d galleries, %3$d images, %4$d orders, %5$d visitor log entries.', 'slashphoto' ) ); ?>"><?php echo esc_html( sprintf( __( 'Imported so far: %1$d price lists, %2$d galleries, %3$d images, %4$d orders, %5$d visitor log entries.', 'slashphoto' ), $state['done']['price_lists'], $state['done']['galleries'], $state['done']['images'], $state['done']['orders'], $state['done']['visitors'] ) ); ?></p>
				<div data-notes>
					<?php if ( $state['notes'] ) : ?>
						<h2><?php esc_html_e( 'To check', 'slashphoto' ); ?></h2>
						<ul class="ul-disc">
							<?php foreach ( $state['notes'] as $note ) : ?>
								<li><?php echo esc_html( $note ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<script>
		( function () {
			const root = document.getElementById( 'slashphoto-import' );
			const status = root.querySelector( '[data-status]' );
			const counts = root.querySelector( '[data-counts]' );
			const notes = root.querySelector( '[data-notes]' );
			const steps = 
			<?php
			echo wp_json_encode(
				[
					'prices'    => __( 'Copying price lists…', 'slashphoto' ),
					'galleries' => __( 'Creating galleries…', 'slashphoto' ),
					'images'    => __( 'Copying images… This can take a while; keep this page open.', 'slashphoto' ),
					'orders'    => __( 'Copying orders…', 'slashphoto' ),
					'done'      => __( 'The import is complete.', 'slashphoto' ),
					'error'     => __( 'The import stopped because of an error. Click the button to continue.', 'slashphoto' ),
				]
			);
			?>
							;
			const format = ( template, values ) => { let i = 0; return template.replace( /%(\d+\$)?d/g, ( m, p ) => String( values[ p ? parseInt( p, 10 ) - 1 : i++ ] ) ); };
			let running = false;
			async function go( restart ) {
				if ( running ) {
					return;
				}
				running = true;
				root.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = true ) );
				let state = { step: '' };
				let first = true;
				while ( 'done' !== state.step ) {
					try {
						const response = await fetch( root.dataset.rest, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': root.dataset.nonce },
							body: JSON.stringify( { restart: restart && first } ),
						} );
						if ( ! response.ok ) {
							throw new Error( String( response.status ) );
						}
						state = await response.json();
						first = false;
					} catch ( error ) {
						status.textContent = steps.error;
						break;
					}
					status.textContent = steps[ state.step ] || '';
					counts.textContent = format( counts.dataset.template, [ state.done.price_lists, state.done.galleries, state.done.images, state.done.orders, state.done.visitors ] );
					if ( state.notes.length ) {
						notes.innerHTML = '<h2></h2><ul class="ul-disc"></ul>';
						notes.querySelector( 'h2' ).textContent = <?php echo wp_json_encode( __( 'To check', 'slashphoto' ) ); ?>;
						state.notes.forEach( ( note ) => {
							const li = document.createElement( 'li' );
							li.textContent = note;
							notes.querySelector( 'ul' ).append( li );
						} );
					}
				}
				running = false;
				root.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = false ) );
			}
			root.querySelector( '[data-start]' )?.addEventListener( 'click', () => go( false ) );
			root.querySelector( '[data-restart]' )?.addEventListener( 'click', () => go( true ) );
		} )();
		</script>
		<?php
	}
}
