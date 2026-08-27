<?php
/**
 * Secrets API: legacy compat shim global functions (plugin-only, never copied to core)
 *
 * Only required at all when WP_SECRETS_LEGACY_SHIM is enabled -- see
 * wp_secrets_api_maybe_load_compat_shim() in secrets-api.php. Each function is
 * additionally guarded by its own function_exists() check, per the brief's §9.6:
 * a site whose own code (or another plugin) already declares get_secret() must
 * keep winning. Two implementations of a global get_secret() cannot safely
 * coexist any more than two implementations of wp_get_secret() could.
 *
 * These four names are deliberately unprefixed: matching displace-secrets-manager's
 * own global function names exactly is the entire point of this file.
 *
 * @package SecretsAPI
 */

if ( ! function_exists( 'get_secret' ) ) {
	/**
	 * Legacy-shaped read. See Secrets_API_Compat_Shim::get_secret().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return string|null
	 */
	function get_secret( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- matching the legacy plugin's own global function name is the point of this file.
		return Secrets_API_Compat_Shim::get_secret( $key );
	}
}

if ( ! function_exists( 'set_secret' ) ) {
	/**
	 * Legacy-shaped write. See Secrets_API_Compat_Shim::set_secret().
	 *
	 * @param string $key   Legacy, unnamespaced secret key.
	 * @param string $value Plaintext value to store.
	 *
	 * @return bool
	 */
	function set_secret( $key, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- matching the legacy plugin's own global function name is the point of this file.
		return Secrets_API_Compat_Shim::set_secret( $key, $value );
	}
}

if ( ! function_exists( 'delete_secret' ) ) {
	/**
	 * Legacy-shaped delete. See Secrets_API_Compat_Shim::delete_secret().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return bool
	 */
	function delete_secret( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- matching the legacy plugin's own global function name is the point of this file.
		return Secrets_API_Compat_Shim::delete_secret( $key );
	}
}

if ( ! function_exists( 'secret_exists' ) ) {
	/**
	 * Legacy-shaped existence check. See Secrets_API_Compat_Shim::secret_exists().
	 *
	 * @param string $key Legacy, unnamespaced secret key.
	 *
	 * @return bool
	 */
	function secret_exists( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- matching the legacy plugin's own global function name is the point of this file.
		return Secrets_API_Compat_Shim::secret_exists( $key );
	}
}
