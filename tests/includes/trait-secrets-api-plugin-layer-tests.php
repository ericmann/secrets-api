<?php
/**
 * For test cases that cover the plugin-only layer: the WP-CLI commands, the
 * migrator, the legacy reader, and the prototype fallback store.
 *
 * On a WordPress that ships the Secrets API (trunk since r64337), the plugin
 * stands down and loads none of that layer, so these cases skip. The skip keys
 * on core's own file declaring wp_get_secret(), not on a plugin class being
 * missing: a plugin that failed to load its layer on 6.8 should fail here, not
 * skip.
 *
 * @package SecretsAPI
 */

trait Secrets_API_Plugin_Layer_Tests {

	public static function set_up_before_class(): void {
		if ( self::core_provides_the_secrets_api() ) {
			self::markTestSkipped( 'This WordPress ships the Secrets API, so the plugin stands down and loads no plugin-only layer.' );
		}

		parent::set_up_before_class();
	}

	/**
	 * Whether wp_get_secret() was declared by core's wp-includes/secrets.php
	 * rather than by the plugin's copy in src/.
	 *
	 * @return bool
	 */
	private static function core_provides_the_secrets_api(): bool {
		if ( ! function_exists( 'wp_get_secret' ) ) {
			return false;
		}

		$declared_in = ( new ReflectionFunction( 'wp_get_secret' ) )->getFileName();

		return false !== $declared_in && realpath( $declared_in ) === realpath( ABSPATH . WPINC . '/secrets.php' );
	}
}
