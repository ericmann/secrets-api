<?php
/**
 * PHPUnit bootstrap for the Secrets API feature plugin.
 *
 * @package SecretsAPI
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tmp_dir = getenv( 'TMPDIR' ) ? rtrim( getenv( 'TMPDIR' ), '/' ) : '/tmp';

	$_tests_dir = $_tmp_dir . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$_tests_dir}.\n";
	echo "Run `make install` (or bin/install-wp-tests.sh) first, or set WP_TESTS_DIR.\n";

	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test.
 *
 * @return void
 */
function _secrets_api_manually_load_plugin() {
	require dirname( __DIR__ ) . '/secrets-api.php';
}

tests_add_filter( 'muplugins_loaded', '_secrets_api_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/trait-wp-secrets-assertions.php';
require_once __DIR__ . '/includes/class-mock-store.php';
require_once __DIR__ . '/includes/class-mock-keyring.php';
