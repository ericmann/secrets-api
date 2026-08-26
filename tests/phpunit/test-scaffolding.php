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
		$this->assertFileExists( WP_SECRETS_API_PLUGIN_DIR . 'secrets-api.php' );
	}

	/**
	 * The plugin declares nothing yet. This test inverts at commit 8, and its job until
	 * then is to catch a stray early declaration of the public surface.
	 */
	public function test_public_api_is_not_declared_yet() {
		$this->assertFalse( function_exists( 'wp_get_secret' ) );
	}

	public function test_running_wordpress_is_below_the_core_api_version() {
		global $wp_version;

		$this->assertTrue(
			version_compare( $wp_version, WP_SECRETS_API_CORE_VERSION, '<' ),
			'The test environment is running a WordPress that may ship the Secrets API natively, which would make the rest of this suite meaningless.'
		);
	}
}
