<?php
/**
 * SnapOtter connection settings.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Settings;
use SlashPhoto\SnapOtter\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Connect, test and disconnect a SnapOtter server from SlashPhoto →
 * Settings. Passwords are used once to create an API key and never stored.
 */
final class SnapOtterSettings {

	public const ACTION = 'slashphoto_snapotter';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'slashphoto_settings_after_form', [ $this, 'render' ] );
	}

	/**
	 * Handles the connection forms.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$url       = isset( $_POST['snapotter_url'] ) ? esc_url_raw( wp_unslash( $_POST['snapotter_url'] ) ) : '';
		$result    = true;

		switch ( $operation ) {
			case 'login':
				$username = isset( $_POST['snapotter_username'] ) ? sanitize_text_field( wp_unslash( $_POST['snapotter_username'] ) ) : '';
				// Passwords are not sanitised: any character may be part of them. Used once, never stored.
				$password = isset( $_POST['snapotter_password'] ) ? (string) wp_unslash( $_POST['snapotter_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$result   = Connection::connect_with_login( $url, $username, $password );
				unset( $password );
				break;
			case 'key':
				$key    = isset( $_POST['snapotter_key'] ) ? sanitize_text_field( wp_unslash( $_POST['snapotter_key'] ) ) : '';
				$result = Connection::connect_with_key( $url, $key );
				break;
			case 'test':
				$result = Connection::test();
				break;
			case 'disconnect':
				Connection::disconnect();
				break;
		}

		$message = is_wp_error( $result ) ? $result->get_error_message() : '';
		set_transient(
			self::notice_key(),
			[
				'operation' => $operation,
				'error'     => $message,
			],
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'tab', 'snapotter', Settings::admin_url() ) . '#slashphoto-snapotter' );
		exit;
	}

	/**
	 * Per-admin key for the result notice.
	 */
	private static function notice_key(): string {
		return 'slashphoto_snapotter_notice_' . get_current_user_id();
	}

	/**
	 * Renders the connection section below the processing settings.
	 *
	 * @param string $tab Active settings section.
	 */
	public function render( string $tab ): void {
		if ( 'snapotter' !== $tab ) {
			return;
		}
		$notice = get_transient( self::notice_key() );
		delete_transient( self::notice_key() );
		$data       = Connection::data();
		$connected  = Connection::is_configured();
		$action_url = admin_url( 'admin-post.php' );
		?>
		<h2 id="slashphoto-snapotter"><?php esc_html_e( 'SnapOtter connection', 'slashphoto' ); ?></h2>
		<p>
			<?php
			echo wp_kses(
				__( '<a href="https://github.com/snapotter-hq/SnapOtter">SnapOtter</a> is a free image toolkit you run on your own server or computer. When it is connected, SlashPhoto sends originals there to make display copies, so RAW, HEIC, TIFF and PSD files work and very large images do not strain your web host. Images are sent only to the address you enter here.', 'slashphoto' ),
				[ 'a' => [ 'href' => [] ] ]
			);
			?>
		</p>
		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice <?php echo esc_attr( '' === $notice['error'] ? 'notice-success' : 'notice-error' ); ?> inline"><p>
				<?php
				if ( '' !== $notice['error'] ) {
					echo esc_html( $notice['error'] );
				} elseif ( 'disconnect' === $notice['operation'] ) {
					esc_html_e( 'SnapOtter was disconnected.', 'slashphoto' );
				} else {
					esc_html_e( 'SnapOtter is connected and working.', 'slashphoto' );
				}
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( $connected ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server', 'slashphoto' ); ?></th>
					<td><code><?php echo esc_html( Connection::url() ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'slashphoto' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: SnapOtter version, 2: date and time */
								__( 'Connected (SnapOtter %1$s), last checked %2$s', 'slashphoto' ),
								(string) ( $data['version'] ?? '?' ),
								! empty( $data['checked'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $data['checked'] ) : '–'
							)
						);
						?>
						<p class="description"><?php echo esc_html( empty( $data['key_id'] ) ? __( 'Uses the API key you entered.', 'slashphoto' ) : __( 'Uses an API key SlashPhoto created; it is deleted from SnapOtter when you disconnect.', 'slashphoto' ) ); ?></p>
					</td>
				</tr>
			</table>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline-block;margin-right:8px">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="operation" value="test">
				<?php submit_button( __( 'Test connection', 'slashphoto' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline-block">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="operation" value="disconnect">
				<?php submit_button( __( 'Disconnect', 'slashphoto' ), 'delete', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-snapotter-url"><?php esc_html_e( 'Server address', 'slashphoto' ); ?></label></th>
						<td>
							<input type="url" id="slashphoto-snapotter-url" name="snapotter_url" class="regular-text" placeholder="http://192.168.1.10:1349" value="<?php echo esc_attr( (string) ( $data['url'] ?? '' ) ); ?>" required>
							<p class="description"><?php esc_html_e( 'The address your web server uses to reach SnapOtter. Use https:// when the traffic crosses the internet.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sign in', 'slashphoto' ); ?></th>
						<td>
							<fieldset>
								<p>
									<label for="slashphoto-snapotter-username"><?php esc_html_e( 'User name', 'slashphoto' ); ?></label><br>
									<input type="text" id="slashphoto-snapotter-username" name="snapotter_username" class="regular-text" autocomplete="off">
								</p>
								<p>
									<label for="slashphoto-snapotter-password"><?php esc_html_e( 'Password', 'slashphoto' ); ?></label><br>
									<input type="password" id="slashphoto-snapotter-password" name="snapotter_password" class="regular-text" autocomplete="new-password">
								</p>
								<p class="description"><?php esc_html_e( 'Used once to create an API key named “SlashPhoto”. The password is not stored.', 'slashphoto' ); ?></p>
								<p><button type="submit" name="operation" value="login" class="button button-primary"><?php esc_html_e( 'Connect with user name and password', 'slashphoto' ); ?></button></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-snapotter-key"><?php esc_html_e( 'Or an API key', 'slashphoto' ); ?></label></th>
						<td>
							<input type="password" id="slashphoto-snapotter-key" name="snapotter_key" class="regular-text" autocomplete="off" placeholder="si_…">
							<p class="description"><?php esc_html_e( 'Create one in SnapOtter under Settings → API Keys. You can limit it to using tools.', 'slashphoto' ); ?></p>
							<p><button type="submit" name="operation" value="key" class="button"><?php esc_html_e( 'Connect with API key', 'slashphoto' ); ?></button></p>
						</td>
					</tr>
				</table>
			</form>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Privacy: SnapOtter sends anonymous usage statistics (which tools ran, never files or file names) to its developers unless you switch this off in SnapOtter under Settings → System Settings. Processed files stay on your SnapOtter server until its regular clean-up.', 'slashphoto' ); ?>
		</p>
		<?php
	}
}
