<?php
/**
 * PHPUnit bootstrap for the platform examples suite (`make test-examples`).
 *
 * Bootstraps WordPress and the plugin exactly as tests/bootstrap.php does, then
 * requires every examples/*\/secrets.php found on disk so their classes are
 * available to construct directly in a test. The install block at the bottom of
 * each example file is guarded on wp-config.php constants (WP_SECRETS_AWS_REGION
 * and friends) that are never defined in this process, so requiring the file
 * installs nothing as a provider or keyring global -- it only makes the class
 * declarations available.
 *
 * Not part of `make ci`. Needs emulators running locally; see examples/README.md.
 *
 * @package SecretsAPI
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( glob( dirname( __DIR__ ) . '/examples/*/secrets.php' ) as $example ) {
	require_once $example;
}
