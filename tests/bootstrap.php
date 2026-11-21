<?php
/**
 * PHPUnit bootstrap file for the wp-env tests environment.
 *
 * @package SeekFix
 */

$seekfix_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $seekfix_tests_dir ) {
	$seekfix_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Composer autoload: SeekFix\ classes and the PHPUnit Polyfills required by the WordPress test suite.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( "{$seekfix_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$seekfix_tests_dir}/includes/functions.php. Is the wp-env tests environment running? (npm run env:start)" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Gives access to tests_add_filter().
require_once "{$seekfix_tests_dir}/includes/functions.php";

/**
 * Load the plugin before WordPress finishes booting.
 */
function seekfix_tests_load_plugin() {
	require dirname( __DIR__ ) . '/seekfix.php';
}

tests_add_filter( 'muplugins_loaded', 'seekfix_tests_load_plugin' );

// Start up the WordPress testing environment.
require "{$seekfix_tests_dir}/includes/bootstrap.php";
