<?php
/**
 * Scaffolding smoke tests.
 *
 * These exist so `make ci` is meaningfully green at commit 1 rather than vacuously
 * green: they prove the harness boots, the plugin loads, and the no-op gate behaves.
 *
 * @package SecretsAPI
 */

/**
 * Proves the harness boots, the plugin loads, and the no-op gate behaves.
 *
 * @group secrets
 */
class Tests_Secrets_Scaffolding extends WP_UnitTestCase {

	public function test_plugin_bootstrap_loaded() {
		$this->assertTrue( defined( 'WP_SECRETS_API_PLUGIN_VERSION' ) );
		$this->assertTrue( defined( 'WP_SECRETS_API_CORE_VERSION' ) );
	}

	public function test_plugin_dir_points_at_the_plugin_root() {
		$this->assertFileExists( WP_SECRETS_API_PLUGIN_DIR . 'secrets-manager.php' );
	}

	/**
	 * The public API landed at commit 8. This test inverted from its original form
	 * (asserting the symbol did NOT exist yet) at that point.
	 */
	public function test_public_api_is_declared() {
		$this->assertTrue( function_exists( 'wp_get_secret' ) );
		$this->assertTrue( function_exists( 'wp_set_secret' ) );
		$this->assertTrue( function_exists( 'wp_delete_secret' ) );
	}

	public function test_running_wordpress_is_below_the_core_api_version() {
		global $wp_version;

		$this->assertTrue(
			version_compare( $wp_version, WP_SECRETS_API_CORE_VERSION, '<' ),
			'The test environment is running a WordPress that may ship the Secrets API natively, which would make the rest of this suite meaningless.'
		);
	}
}
