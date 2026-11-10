<?php
/**
 * Tests that the plugin's copies of core's Secrets API Site Health methods match core.
 *
 * For WordPress before 7.2, Secrets_API_Site_Health carries copies of methods that core
 * has in WP_Site_Health and WP_Debug_Data. Each copy must stay
 * identical to core's, so these compare the source of each one with core's on a
 * WordPress that ships the API. A failure means core changed the method, and the copy
 * needs porting.
 *
 * Skipped on a WordPress without the API, unless SECRETS_API_TESTS_EXPECT_CORE is set,
 * as for the stand-down tests.
 *
 * @ticket 66187
 *
 * @group secrets
 * @group stand-down
 */
class Tests_Secrets_SiteHealthParity extends WP_UnitTestCase {

	/**
	 * Skips the case, or fails it, when this WordPress does not ship the API.
	 */
	public static function set_up_before_class(): void {
		if ( ! secrets_api_tests_core_ships_the_api() ) {
			if ( getenv( 'SECRETS_API_TESTS_EXPECT_CORE' ) ) {
				self::fail( 'SECRETS_API_TESTS_EXPECT_CORE is set, but this WordPress does not ship the Secrets API.' );
			}

			self::markTestSkipped( 'This WordPress does not ship the Secrets API, so there is nothing to compare with.' );
		}

		parent::set_up_before_class();
	}

	/**
	 * Returns the source of a method, from the start of its docblock to its closing brace.
	 *
	 * @param string $file   Path to the file that declares the method.
	 * @param string $method Method name.
	 * @return string The method's source.
	 */
	private function method_source( string $file, string $method ): string {
		$source = file_get_contents( $file );

		$this->assertIsString( $source, "{$file} should be readable." );
		$this->assertSame(
			1,
			preg_match( '/\n(\t\/\*\*\n(?:\t \*[^\n]*\n)*\t \*\/\n\t[a-z ]*function ' . preg_quote( $method, '/' ) . '\(.*?\n\t\}\n)/s', $source, $matches ),
			"{$method}() should be declared in {$file}."
		);

		return $matches[1];
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{string, string}> The core file, relative to ABSPATH, and the method name.
	 */
	public function data_copied_methods(): array {
		$site_health = 'wp-admin/includes/class-wp-site-health.php';
		$debug_data  = 'wp-admin/includes/class-wp-debug-data.php';

		return array(
			'get_test_secrets_api_key_source'     => array( $site_health, 'get_test_secrets_api_key_source' ),
			'get_test_secrets_api_undecryptable'  => array( $site_health, 'get_test_secrets_api_undecryptable' ),
			'get_test_secrets_api_needs_rotation' => array( $site_health, 'get_test_secrets_api_needs_rotation' ),
			'get_secrets_api_test_result'         => array( $site_health, 'get_secrets_api_test_result' ),
			'secrets_api_uses_keyring'            => array( $site_health, 'secrets_api_uses_keyring' ),
			'get_undecryptable_secrets'           => array( $site_health, 'get_undecryptable_secrets' ),
			'count_secrets_needing_rotation'      => array( $site_health, 'count_secrets_needing_rotation' ),
			'get_wp_secrets_api'                  => array( $debug_data, 'get_wp_secrets_api' ),
		);
	}

	/**
	 * Ensure that the plugin's copy of a method matches core's.
	 *
	 * @dataProvider data_copied_methods
	 *
	 * @param string $core_file Core file that declares the method, relative to ABSPATH.
	 * @param string $method    Method name.
	 */
	public function test_copy_matches_core( string $core_file, string $method ): void {
		$this->assertSame(
			$this->method_source( ABSPATH . $core_file, $method ),
			$this->method_source( WP_SECRETS_API_PLUGIN_DIR . 'plugin/class-secrets-api-site-health.php', $method ),
			"Secrets_API_Site_Health::{$method}() should match core's. Copy it again from {$core_file}."
		);
	}

	/**
	 * Ensure that the plugin copies every Secrets API method core has.
	 *
	 * A new method in core that the copies call would otherwise go unnoticed until the
	 * plugin failed on WordPress before 7.2.
	 */
	public function test_copies_every_core_method(): void {
		$copied = array_column( $this->data_copied_methods(), 1 );

		foreach ( array( 'WP_Site_Health', 'WP_Debug_Data' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
			}

			foreach ( ( new ReflectionClass( $class ) )->getMethods() as $method ) {
				if ( preg_match( '/secrets/', $method->getName() ) ) {
					$this->assertContains( $method->getName(), $copied, "{$class}::{$method->getName()}() is not copied in Secrets_API_Site_Health." );
				}
			}
		}
	}
}
