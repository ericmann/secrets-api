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
 * The Secrets API needs WP_SECRETS_KEY, which wp-tests-config.php does not define,
 * so give the suite a fixed test key, as core's test bootstrap does since r65385.
 * A test library from before 7.2 doesn't, so this defines it first.
 *
 * Not in a process-isolated test: PHPUnit sets $GLOBALS['__PHPUNIT_BOOTSTRAP']
 * while it loads this file in a child process, and the Secrets API tests that run
 * isolated do so precisely to define WP_SECRETS_KEY themselves, or to leave it
 * undefined.
 */
if ( ! defined( 'WP_SECRETS_KEY' ) && ! isset( $GLOBALS['__PHPUNIT_BOOTSTRAP'] ) ) {
	define( 'WP_SECRETS_KEY', base64_encode( str_repeat( 'k', 32 ) ) );
}

/*
 * Loaded, and WP_CLI defined, before the plugin itself ever bootstraps below: the
 * plugin's own bootstrap checks defined( 'WP_CLI' ) && WP_CLI to decide whether to
 * load cli/, exactly as it will under real WP-CLI, so this has to be in place before
 * that check runs – not after, as tests/includes files are otherwise loaded.
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

/*
 * A test library from a WordPress that ships the Secrets API (trunk since r64337)
 * already loaded core's copies of these from its own bootstrap. Loading the
 * plugin's as well would redeclare them.
 */
if ( ! file_exists( $_tests_dir . '/includes/secrets/trait-wp-secrets-assertions.php' ) ) {
	require_once __DIR__ . '/includes/trait-wp-secrets-assertions.php';
	require_once __DIR__ . '/includes/class-mock-store.php';
	require_once __DIR__ . '/includes/class-mock-keyring.php';
	require_once __DIR__ . '/includes/class-wp-secrets-provider-conformance.php';
	require_once __DIR__ . '/includes/class-wp-secrets-keyring-conformance.php';
}

require_once __DIR__ . '/includes/class-legacy-fixture-writer.php';
require_once __DIR__ . '/includes/trait-secrets-api-plugin-layer-tests.php';

/*
 * The migrator loads under WP-CLI only, which the mock above satisfies. The
 * fallback store and legacy reader are loaded by the plugin on every request, so
 * nothing extra is needed for either.
 */
