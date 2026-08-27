<?php
/**
 * Reference secrets.php drop-in.
 *
 * This file is documentation, not code this plugin loads or tests. Copy what you
 * need into wp-content/secrets.php and replace the two stub classes below with
 * real calls into your platform. See docs/extending.md for the contracts these
 * implement and what happens if a global ends up set to the wrong thing.
 *
 * A drop-in can set either global, both, or neither. Most hosts want their own
 * key management long before they want their own row storage -- setting only
 * $wp_secrets_keyring and leaving the store on the shipped default is a normal,
 * common combination. Both are shown here for completeness.
 *
 * @package SecretsAPI
 */

/**
 * Example read-only platform store.
 *
 * Modeled on real feedback from the proposal's comment thread (see
 * docs/open-questions.md #2, #3): some platforms are themselves the encryption
 * boundary and want to serve their own credentials to WordPress without ever
 * accepting a write back. Declaring supports('write') === false is how that is
 * expressed -- wp_set_secret() then returns a WP_Error with code
 * 'secret_store_read_only' instead of silently no-opping or, worse, accepting a
 * write it cannot actually honor.
 *
 * A store CANNOT use this to hand WordPress a plaintext value. Every method here
 * traffics only in the record array WP_Secrets_Cipher produces -- get() returns
 * one, set() (were it implemented) would accept one. A platform that wants to
 * serve its own plaintext to WordPress is a materially different feature than
 * this interface provides, and is tracked, unresolved, at
 * docs/open-questions.md #2.
 */
final class Example_Platform_Store implements WP_Secrets_Store {

	/**
	 * Reads a record from the platform. Stubbed to always report absence.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return array|null|WP_Error
	 */
	public function get( $name, $network = false ) {
		/*
		 * Replace with a real call to your platform's credential API. Whatever it
		 * returns must be re-shaped into the record array WP_Secrets_Cipher
		 * produces -- this class cannot invent that shape from a plaintext value,
		 * because a store is never handed a plaintext value to encrypt.
		 *
		 *     $response = My_Platform_Client::get_secret_record( $name, $network );
		 *
		 *     if ( is_wp_error( $response ) ) {
		 *         return new WP_Error(
		 *             WP_SECRETS_ERROR_STORE_UNAVAILABLE,
		 *             'The platform API could not be reached.'
		 *         );
		 *     }
		 *
		 *     return null === $response ? null : $response;
		 */

		return null;
	}

	/**
	 * Refused outright -- see supports().
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param array  $record  The record to store.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Error
	 */
	public function set( $name, $record, $network = false ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			'This platform manages credentials outside of WordPress.'
		);
	}

	/**
	 * Refused outright -- see supports().
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Error
	 */
	public function delete( $name, $network = false ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			'This platform manages credentials outside of WordPress.'
		);
	}

	/**
	 * Lists secret names known to the platform. Stubbed to an empty list.
	 *
	 * @param bool $network Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_names( $network = false ) {
		// Replace with a real listing call. Returning an empty array is also
		// valid if your platform has no way to enumerate names cheaply --
		// wp_list_secrets() will simply show nothing rather than erroring.
		return array();
	}

	/**
	 * Declares this store read-only, plus listable.
	 *
	 * @param string $capability One of 'write', 'list', 'delete'.
	 *
	 * @return bool
	 */
	public function supports( $capability ) {
		return 'list' === $capability; // Read-only: no write, no delete.
	}
}

/**
 * Example KMS-backed keyring.
 *
 * Protects exactly one thing -- the 32-byte root key -- never a secret value.
 * Swapping this in changes where that one value is protected without touching
 * anything else: every master key, data key, and secret still derives the same
 * way, from whatever wrap()/unwrap() hand back.
 */
final class Example_KMS_Keyring implements WP_Secrets_Keyring {

	/**
	 * Wraps root key material via the platform's KMS. Stubbed to always fail.
	 *
	 * @param string $key_material Raw key material to protect.
	 *
	 * @return string|WP_Error Opaque wrapped value on success.
	 */
	public function wrap( $key_material ) {
		/*
		 * Replace with a real KMS encrypt call, e.g.:
		 *
		 *     $result = My_KMS_Client::encrypt( 'alias/my-wp-root-key', $key_material );
		 *
		 *     if ( is_wp_error( $result ) ) {
		 *         return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'KMS unreachable.' );
		 *     }
		 *
		 *     return $result;
		 */

		return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Not implemented -- example only.' );
	}

	/**
	 * Unwraps root key material via the platform's KMS. Stubbed to always fail.
	 *
	 * @param string $wrapped An opaque value previously returned by wrap().
	 *
	 * @return string|WP_Error Raw key material on success.
	 */
	public function unwrap( $wrapped ) {
		// Replace with a real KMS decrypt call, mirroring wrap() above.

		return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Not implemented -- example only.' );
	}

	/**
	 * Shown in Site Health. Never sensitive, never the key material itself.
	 *
	 * @return string
	 */
	public function get_key_source() {
		return 'Example KMS (replace before use)';
	}
}

/*
 * The globals a drop-in sets. wp_secrets_api_load_dropin() (or, once this lands
 * in core, the equivalent bootstrap code in wp-settings.php) checks the type of
 * whatever ends up here immediately after this file is required. Anything other
 * than an instance of the matching interface -- including leaving a variable set
 * to null, a string, or a half-constructed object from a caught error -- fails
 * that half of the API closed for the rest of the request, via
 * WP_Secrets_Broken_Store / WP_Secrets_Broken_Keyring. That is deliberate: a
 * credential backend that might be misconfigured must never look like one that
 * is simply empty.
 */
$GLOBALS['wp_secrets_store']   = new Example_Platform_Store();
$GLOBALS['wp_secrets_keyring'] = new Example_KMS_Keyring();
