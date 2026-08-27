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

/*
 * Loaded, and WP_CLI defined, before the plugin itself ever bootstraps below: the
 * plugin's own bootstrap checks defined( 'WP_CLI' ) && WP_CLI to decide whether to
 * load cli/, exactly as it will under real WP-CLI, so this has to be in place before
 * that check runs -- not after, as tests/includes files are otherwise loaded.
 */
require_once __DIR__ . '/includes/class-mock-wp-cli.php';

defined( 'WP_CLI' ) || define( 'WP_CLI', true );

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
require_once __DIR__ . '/includes/class-legacy-fixture-writer.php';

/*
 * The migrator loads under WP-CLI only, which the mock above satisfies. The
 * fallback store and legacy reader are loaded by the plugin on every request, so
 * nothing extra is needed for either.
 */
