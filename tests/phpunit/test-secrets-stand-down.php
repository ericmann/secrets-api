<?php
/**
 * Tests that the plugin stands down on a WordPress that ships the Secrets API.
 *
 * These run against the real bootstrap on a real core, not a simulated version:
 * the no-op gate tests cover the decision itself. On a WordPress without the API,
 * the case is skipped, unless SECRETS_API_TESTS_EXPECT_CORE is set, as it is for
 * `make test-stand-down`. Then it fails, so a trunk job can't pass by skipping.
 *
 * @ticket 66187
 *
 * @group secrets
 * @group stand-down
 */
class Tests_Secrets_StandDown extends WP_UnitTestCase {

	/**
	 * Skips the case, or fails it, when this WordPress does not ship the API.
	 */
	public static function set_up_before_class(): void {
		if ( ! secrets_api_tests_core_ships_the_api() ) {
			if ( getenv( 'SECRETS_API_TESTS_EXPECT_CORE' ) ) {
				self::fail( 'SECRETS_API_TESTS_EXPECT_CORE is set, but this WordPress does not ship the Secrets API.' );
			}

			self::markTestSkipped( 'This WordPress does not ship the Secrets API, so the plugin does not stand down.' );
		}

		parent::set_up_before_class();
	}

	/**
	 * Ensure that core's implementation serves the API, not the plugin's copy in src/.
	 *
	 * @covers ::wp_secrets_api_bootstrap
	 */
	public function test_core_declares_the_api(): void {
		$core_dir = realpath( ABSPATH . WPINC );

		$this->assertSame(
			realpath( ABSPATH . WPINC . '/secrets.php' ),
			realpath( ( new ReflectionFunction( 'wp_get_secret' ) )->getFileName() ),
			'wp_get_secret() should be declared by core.'
		);

		foreach ( array( 'WP_Secret', 'WP_Secrets_Provider', 'WP_Secrets_Keyring', 'WP_Secrets_Key_Manager', 'WP_Secrets_Libsodium_Provider' ) as $name ) {
			$file = ( new ReflectionClass( $name ) )->getFileName();

			$this->assertIsString( $file, "{$name} should be declared in a file." );
			$this->assertStringStartsWith( $core_dir . DIRECTORY_SEPARATOR, realpath( $file ), "{$name} should be declared by core, not the plugin." );
		}
	}

	/**
	 * Ensure that the plugin loads none of its plugin-only layer.
	 *
	 * @covers ::wp_secrets_api_bootstrap
	 */
	public function test_the_plugin_only_layer_is_not_loaded(): void {
		foreach ( array( 'Secrets_API_Migrator', 'Secrets_API_Legacy_Reader', 'Secrets_API_Prototype_Fallback_Store' ) as $name ) {
			$this->assertFalse( class_exists( $name, false ), "{$name} should not be loaded when core ships the API." );
		}
	}

	/**
	 * Ensure that the plugin installs no store of its own in front of core's.
	 *
	 * @covers ::wp_secrets_api_bootstrap
	 */
	public function test_the_plugin_sets_no_store(): void {
		$store = $GLOBALS['wp_secrets_store'] ?? null;

		$this->assertNull( $store, 'The plugin should leave the store global to core and the drop-in.' );
	}

	/**
	 * Ensure that the plugin says it has been superseded, and reports no conflict.
	 *
	 * @covers ::wp_secrets_api_bootstrap
	 */
	public function test_the_superseded_notice_is_hooked(): void {
		$this->assertSame( 10, has_action( 'admin_notices', 'wp_secrets_api_notice_superseded' ), 'The superseded notice should be hooked.' );
		$this->assertFalse( has_action( 'admin_notices', 'wp_secrets_api_notice_conflict' ), 'No conflict notice should be hooked.' );
	}
}
