<?php
/**
 * Confirmation page for an emailed sign-in link: a standalone page outside
 * the theme. Signing in needs the button, so mail scanners that open links
 * in advance do not use the one-time link up.
 *
 * Override: copy this file to yourtheme/slashphoto/signin.php and keep the
 * form field (slashphoto_signin).
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type string $state  "confirm" or "invalid".
 *     @type string $site   Site name.
 *     @type string $login  Login page address.
 *     @type string $value  Link value to post back (confirm only).
 *     @type string $action Form address (confirm only).
 *     @type string $name   Account display name (confirm only).
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_confirm = 'confirm' === ( $args['state'] ?? '' );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( ( $slashphoto_confirm ? __( 'Sign in', 'slashphoto' ) : __( 'Link not valid', 'slashphoto' ) ) . ' – ' . $args['site'] ); ?></title>
<style>
	:root { color-scheme: light dark; --bg: #f3f3f3; --card: #fff; --text: #1d1d1d; --muted: #5a5a5a; --accent: #1d4ed8; --on-accent: #fff; }
	@media (prefers-color-scheme: dark) { :root { --bg: #141414; --card: #1f1f1f; --text: #ececec; --muted: #b0b0b0; --accent: #93b4ff; --on-accent: #0b1530; } }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	main { box-sizing: border-box; width: min(440px, calc(100% - 32px)); margin: 16px; padding: 32px; background: var(--card); border-radius: 8px; box-shadow: 0 1px 3px rgb(0 0 0 / 0.15); }
	h1 { margin: 0 0 8px; font-size: 22px; font-weight: 600; }
	p { margin: 0 0 16px; color: var(--muted); }
	button, .button { display: inline-block; box-sizing: border-box; width: 100%; padding: 12px 16px; border: 0; border-radius: 6px; background: var(--accent); color: var(--on-accent); font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
	button:focus-visible, .button:focus-visible { outline: 3px solid var(--text); outline-offset: 2px; }
</style>
</head>
<body>
<main>
	<p><?php echo esc_html( $args['site'] ); ?></p>
	<?php if ( $slashphoto_confirm ) : ?>
		<h1><?php esc_html_e( 'Sign in', 'slashphoto' ); ?></h1>
		<p>
			<?php
			/* translators: %s: account display name */
			echo esc_html( sprintf( __( 'Continue as %s. The link works once.', 'slashphoto' ), $args['name'] ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( $args['action'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( \SlashPhoto\Access\SignInLinks::QUERY ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>">
			<button type="submit"><?php esc_html_e( 'Sign in and open the gallery', 'slashphoto' ); ?></button>
		</form>
	<?php else : ?>
		<h1><?php esc_html_e( 'This sign-in link is not valid', 'slashphoto' ); ?></h1>
		<p><?php esc_html_e( 'It has expired or has already been used. Ask for a new link on the gallery page, or sign in with your password.', 'slashphoto' ); ?></p>
		<a class="button" href="<?php echo esc_url( $args['login'] ); ?>"><?php esc_html_e( 'Go to the login page', 'slashphoto' ); ?></a>
	<?php endif; ?>
</main>
</body>
</html>
