<?php
/**
 * Reference secrets.php drop-in.
 *
 * This file is documentation, not code this plugin loads or tests. Copy what you
 * need into wp-content/secrets.php and replace the stub classes below with real
 * calls into your platform. See docs/spec/extension-points.md for the contracts
 * these implement, and docs/decisions/0007-fail-closed-on-a-broken-drop-in.md for
 * what happens if a global ends up set to the wrong thing.
 *
 * A drop-in can set any of the three globals, or none of them:
 *
 * - $GLOBALS['wp_secrets_keyring']  -- your KMS or HSM wraps the root key. Storage
 *                                      and encryption stay WordPress's. The most
 *                                      common host integration: three methods.
 * - $GLOBALS['wp_secrets_store']    -- your backend holds the ciphertext records
 *                                      WordPress produces. Encryption stays
 *                                      WordPress's.
 * - $GLOBALS['wp_secrets_provider'] -- your platform is responsible for the
 *                                      credential itself: it holds it, protects it,
 *                                      and hands it back. This replaces the store
 *                                      and keyring entirely.
 *
 * Precedence: when wp_secrets_provider is set, the store and keyring globals are
 * ignored on the read and write path (Site Health still reads the keyring to
 * describe the key source). Without a provider, the shipped provider is built
 * around whatever store and keyring you did set, with the defaults filling in the
 * rest. All three classes are shown here so each shape has something to copy;
 * a real drop-in usually sets one.
 *
 * Before trusting a drop-in in production, run `php -l` over it and load a real
 * request. A drop-in that throws or sets a global to the wrong type fails closed
 * (every operation returns WP_Error rather than falling back to the default), but a
 * class that implements an interface and omits one of its methods is an
 * uncatchable PHP fatal that no try/catch can contain.
 *
 * @package SecretsAPI
 */

/**
 * Example platform provider.
 *
 * For a platform that is itself the encryption boundary: credentials are managed
 * in its own control panel or key service, and served to WordPress over an
 * authenticated channel. Under the proposal's original two extension points that
 * deployment was banned, because the store would have been handed something
 * WordPress did not encrypt. The provider interface is the resolution: a provider
 * must be stronger than the default, never weaker, and a platform that meets that
 * bar implements this interface instead of pretending to be a store. See
 * docs/decisions/0001-provider-as-outermost-extension-point.md.
 *
 * Every method below is a one-line stub. The comments say what a real
 * implementation returns in each case, and which contracts it must keep: three
 * states never collapsed, fail closed, no plaintext persisted more weakly than the
 * platform protects it (no wp_cache_set() of a raw value; memoize per request
 * only).
 */
final class Example_Platform_Provider implements WP_Secrets_Provider {

	/**
	 * Retrieves a secret from the platform. Stubbed to always report absence.
	 *
	 * A real implementation returns one of three things, and never collapses them:
	 *
	 *   - null      when the platform has no credential under $name, or when
	 *               $version is WP_Secret_Version::PREVIOUS and the platform keeps
	 *               no history. Absence, not an error.
	 *   - WP_Error  when the platform cannot answer: unreachable, unauthenticated,
	 *               or refusing. Use WP_SECRETS_ERROR_STORE_UNAVAILABLE. An outage
	 *               must never look like a deleted credential.
	 *   - WP_Secret when the platform releases the value to PHP:
	 *
	 *         return new WP_Secret( $name, $plaintext, $fingerprint );
	 *
	 *               where $fingerprint is a stable per-site digest the provider
	 *               computes itself (a keyed hash under a key the platform holds
	 *               is fine). Never the value, never a plain unkeyed hash.
	 *
	 *               When the platform can prove the credential exists but will not
	 *               release it, such as an HSM key that signs but never exports,
	 *               return WP_Secret::withheld( $name, $fingerprint, $reason ) with a
	 *               WP_Error $reason. Such a secret lists, fingerprints, and masks
	 *               normally; only reveal() returns the reason instead of a value.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param string $version A WP_Secret_Version constant.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Secret|null|WP_Error
	 */
	public function get( $name, $version, $network = false ) {
		return null; // Replace with a call to your platform's credential API.
	}

	/**
	 * Refused: this platform's credentials are managed in its own tooling.
	 *
	 * A writable provider stores the value, then fires the `wp_secret_changed`
	 * action itself, with the previous and new fingerprints and never the value.
	 * The API does not fire it on the provider's behalf.
	 *
	 * @param string      $name           The secret's namespaced name.
	 * @param string      $value          The plaintext value.
	 * @param bool        $network        Whether this is a network-scope secret.
	 * @param bool        $needs_rotation Flag the stored secret as needing rotation.
	 * @param string|null $action         Override for the action reported to
	 *                                    `wp_secret_changed`.
	 *
	 * @return true|WP_Error
	 */
	public function set( $name, $value, $network = false, $needs_rotation = false, $action = null ) {
		return new WP_Error( WP_SECRETS_ERROR_PROVIDER_READ_ONLY, 'This platform manages credentials outside of WordPress.' );
	}

	/**
	 * Refused: deletion happens in the platform's own tooling.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function delete( $name, $network = false ) {
		return new WP_Error( WP_SECRETS_ERROR_PROVIDER_READ_ONLY, 'This platform manages credentials outside of WordPress.' );
	}

	/**
	 * A successful no-op: this platform keeps no previous version to retire.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function retire_previous( $name, $network = false ) {
		return true; // A provider with version history removes the previous value here.
	}

	/**
	 * Lists secret names and metadata known to the platform. Stubbed to an empty list.
	 *
	 * Each entry is an array with keys 'name', 'fingerprint', 'created',
	 * 'has_previous', and 'needs_rotation'. Never a value.
	 *
	 * @param string $name_prefix Restrict to names beginning with this prefix, or ''.
	 * @param bool   $network     Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_secrets( $name_prefix = '', $network = false ) {
		return array(); // Replace with a listing call; return WP_Error if the platform cannot answer.
	}

	/**
	 * Shown in Site Health. Describes the protection, never the vendor's marketing.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Example platform (replace before use)';
	}

	/**
	 * This platform is the encryption boundary, not WordPress.
	 *
	 * @return string
	 */
	public function get_protection_boundary() {
		return self::BOUNDARY_PROVIDER;
	}

	/**
	 * Declared up front so a settings screen never offers a save control that set()
	 * would only refuse.
	 *
	 * @return bool
	 */
	public function is_writable() {
		return false;
	}
}

/**
 * Example read-only platform store.
 *
 * For swapping only where ciphertext lives while keeping WordPress's envelope. A
 * store is never handed a plaintext: every method here traffics only in the record
 * array the shipped provider assembles (see docs/spec/extension-points.md for its
 * keys). A platform that serves its own credentials to WordPress wants
 * Example_Platform_Provider above, not this.
 *
 * Refusing from set() is how a store that cannot accept writes says so:
 * wp_set_secret() surfaces the WP_Error rather than silently no-opping or, worse,
 * accepting a write it cannot honour.
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
		 * Replace with a real call to your platform's storage API. Whatever it
		 * returns must be the record array WordPress wrote through set(); this
		 * class cannot build one from a plaintext value, because a store is never
		 * handed a plaintext to encrypt.
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
	 * Refused outright: this platform's records are managed by its own tooling.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param array  $record  The record to store.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function set( $name, $record, $network = false ) {
		return new WP_Error(
			WP_SECRETS_ERROR_PROVIDER_READ_ONLY,
			'This platform manages credentials outside of WordPress.'
		);
	}

	/**
	 * Refused outright: deletion happens in the platform's own tooling.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function delete( $name, $network = false ) {
		return new WP_Error(
			WP_SECRETS_ERROR_PROVIDER_READ_ONLY,
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
		// valid if your platform has no way to enumerate names cheaply;
		// wp_list_secrets() will simply show nothing rather than erroring.
		return array();
	}
}

/**
 * Example KMS-backed keyring.
 *
 * Protects exactly one thing, the 32-byte root key, and never a secret value.
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

		return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Not implemented; example only.' );
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

		return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Not implemented; example only.' );
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
 * whatever ends up in each of them immediately after this file is required.
 * Anything other than an instance of the matching interface, including a variable
 * left set to null, a string, or a half-constructed object from a caught error,
 * fails the whole API closed for the rest of the request through
 * WP_Secrets_Broken_Provider / WP_Secrets_Broken_Store / WP_Secrets_Broken_Keyring.
 * That is deliberate: a credential backend that might be misconfigured must never
 * look like one that is simply empty.
 *
 * Keep only the line(s) for the shape you are implementing. With the provider line
 * present, the other two are ignored on the read and write path.
 */
$GLOBALS['wp_secrets_provider'] = new Example_Platform_Provider();
$GLOBALS['wp_secrets_store']    = new Example_Platform_Store();
$GLOBALS['wp_secrets_keyring']  = new Example_KMS_Keyring();
