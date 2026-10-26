<?php
/**
 * For test cases that cover the plugin-only layer the plugin stops loading once
 * WordPress ships the Secrets API: the migrator, the legacy reader, and the
 * prototype fallback store. The WP-CLI commands are not part of it; they load
 * on either implementation.
 *
 * @package SecretsAPI
 */

/**
 * Whether this WordPress ships the Secrets API (trunk since r64337), meaning core's
 * own wp-includes/secrets.php declared wp_get_secret() rather than the plugin's
 * copy in src/.
 *
 * Tests skip on this, not on a plugin class being missing: a plugin that failed
 * to load its layer on 6.8 should fail, not skip.
 *
 * @return bool
 */
function secrets_api_tests_core_ships_the_api(): bool {
	if ( ! function_exists( 'wp_get_secret' ) ) {
		return false;
	}

	$declared_in = ( new ReflectionFunction( 'wp_get_secret' ) )->getFileName();

	return false !== $declared_in && realpath( $declared_in ) === realpath( ABSPATH . WPINC . '/secrets.php' );
}

/**
 * Skips the whole test case when the plugin has stood down for core.
 */
trait Secrets_API_Plugin_Layer_Tests {

	public static function set_up_before_class(): void {
		if ( secrets_api_tests_core_ships_the_api() ) {
			self::markTestSkipped( 'This WordPress ships the Secrets API, so the plugin stands down and loads no plugin-only layer.' );
		}

		parent::set_up_before_class();
	}
}
