<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * Run through wp-env: npm run test:php
 *
 * @package SlashPhoto
 */

$slashphoto_root  = dirname( __DIR__ );
$slashphoto_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $slashphoto_tests ) {
	$slashphoto_tests = '/wordpress-phpunit';
}

if ( ! file_exists( $slashphoto_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$slashphoto_tests}. Run the tests with: npm run test:php\n" );
	exit( 1 );
}

require_once $slashphoto_root . '/vendor/autoload.php';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $slashphoto_root . '/vendor/yoast/phpunit-polyfills' );

require_once $slashphoto_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $slashphoto_root ): void {
		require $slashphoto_root . '/slashphoto.php';
	}
);

require $slashphoto_tests . '/includes/bootstrap.php';
