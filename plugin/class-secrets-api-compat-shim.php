<?php
/**
 * Secrets API: legacy compat shim (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

/**
 * Implements the four global functions displace-secrets-manager plugins call
 * directly -- get_secret(), set_secret(), delete_secret(), secret_exists() --
 * mapped onto the new API, for sites not ready to update every caller at once.
 *
 * This is a bridge with an expiry date, not an API of its own: every method here
 * collapses wp_get_secret()'s three-state WP_Secret|null|WP_Error return into a
 * plain string|null (or a plain bool, for set/delete/exists), reintroducing
 * exactly the absent-versus-broken ambiguity the three-state contract exists to
 * eliminate. A caller using this shim cannot tell "never set" from "undecryptable"
 * from "store unreachable." Anything that can update to call wp_get_secret()
 * directly should.
 *
 * Reads and writes go through the 'legacy' namespace -- the same default the
 * migrator uses for an unmapped key -- so a key migrated with the migrator's own
 * defaults keeps working under its old bare name through this shim. A key migrated
 * with an explicit --map or --namespace will not resolve here; that tradeoff is
 * inherent to a shim with no per-call configuration surface, and is why this is
 * off by default rather than a permanent compatibility layer.
 */
final class Secrets_API_Compat_Shim {

	/**
	 * The namespace every legacy-shaped key is read from and written to.
	 *
	 * @var string
	 */
	const NAMESPACE_PREFIX = 'legacy';

	/**
	 * Legacy-shaped read, mapped onto wp_get_secret().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return string|null The plaintext value, or null if absent, broken, or
	 *                      unreadable for any reason.
	 */
	public static function get_secret( $key ) {
		_deprecated_function( __FUNCTION__, '7.2.0', 'wp_get_secret()' );

		$secret = wp_get_secret( self::namespaced( $key ) );

		return ( $secret instanceof WP_Secret ) ? $secret->reveal() : null;
	}

	/**
	 * Legacy-shaped write, mapped onto wp_set_secret().
	 *
	 * @param string $key   Legacy, unnamespaced secret key.
	 * @param string $value Plaintext value to store.
	 *
	 * @return bool Whether the value was stored.
	 */
	public static function set_secret( $key, $value ) {
		_deprecated_function( __FUNCTION__, '7.2.0', 'wp_set_secret()' );

		return ! is_wp_error( wp_set_secret( self::namespaced( $key ), $value ) );
	}

	/**
	 * Legacy-shaped delete, mapped onto wp_delete_secret().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return bool Whether the secret was deleted.
	 */
	public static function delete_secret( $key ) {
		_deprecated_function( __FUNCTION__, '7.2.0', 'wp_delete_secret()' );

		return ! is_wp_error( wp_delete_secret( self::namespaced( $key ) ) );
	}

	/**
	 * Legacy-shaped existence check, mapped onto wp_get_secret().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return bool Whether the secret exists and is currently readable. A broken
	 *              (undecryptable) record reports false, the same as one that was
	 *              never set -- see the class docblock.
	 */
	public static function secret_exists( $key ) {
		_deprecated_function( __FUNCTION__, '7.2.0', 'wp_get_secret()' );

		return wp_get_secret( self::namespaced( $key ) ) instanceof WP_Secret;
	}

	/**
	 * Prefixes a legacy key with the shim's fixed namespace.
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return string The new-format namespaced name.
	 */
	private static function namespaced( $key ) {
		return self::NAMESPACE_PREFIX . '/' . $key;
	}
}
