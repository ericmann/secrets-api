<?php
/**
 * PHPUnit bootstrap for the examples suite (platform-binding drop-ins).
 *
 * Loads the same WordPress test environment as tests/bootstrap.php, then every
 * example's test helpers and its secrets.php. Each example's install block at the
 * bottom of secrets.php guards on its own WP_SECRETS_*_* constants, which are never
 * defined under test, so loading secrets.php here never installs a provider --
 * it only declares the class and lets each test construct one directly.
 *
 * @package SecretsAPI\Examples
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( glob( dirname( __DIR__ ) . '/examples/*/tests/includes/*.php' ) as $helper ) {
	require_once $helper;
}

foreach ( glob( dirname( __DIR__ ) . '/examples/*/secrets.php' ) as $secrets_php ) {
	require_once $secrets_php;
}
