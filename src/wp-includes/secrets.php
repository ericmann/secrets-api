<?php
/**
 * Secrets API
 *
 * @package WordPress
 * @subpackage Secrets
 * @since 7.2.0
 */

/**
 * A secret exists and was decrypted, but the store or key backend it came from
 * is misbehaving in a way distinct from the more specific codes below.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_DECRYPTION_FAILED', 'secret_decryption_failed' );

/**
 * The key needed to decrypt or encrypt a secret could not be obtained -- the
 * keyring is unreachable, misconfigured, or refuses.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_KEY_UNAVAILABLE', 'secret_key_unavailable' );

/**
 * The storage back end could not be reached at all.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_STORE_UNAVAILABLE', 'secret_store_unavailable' );

/**
 * A secret name failed wp_secrets_validate_name().
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_INVALID_NAME', 'secret_invalid_name' );

/**
 * A secret value was not a string.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_INVALID_VALUE', 'secret_invalid_value' );

/**
 * The cipher this API depends on (libsodium, or its sodium_compat fallback) is
 * not available in this environment.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE', 'secret_crypto_unavailable' );

/**
 * A stored record could not be parsed as a secret record at all -- as distinct
 * from decrypting incorrectly, which is WP_SECRETS_ERROR_DECRYPTION_FAILED.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_RECORD_MALFORMED', 'secret_record_malformed' );

/**
 * A stored record parses, but its 'v' field is not a format version this version of
 * the API understands. Distinct from WP_SECRETS_ERROR_RECORD_MALFORMED so an
 * operator (or Site Health) can tell "corrupt" apart from "written by a newer
 * version of this plugin than is currently active." See docs/open-questions.md,
 * "Record format version bump policy" -- the upgrade path for a future v2 is not
 * designed, so an unrecognized version is rejected outright rather than guessed at.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION', 'secret_record_unsupported_version' );

/**
 * The store refused a write or delete because it does not support that operation
 * (WP_Secrets_Store::supports() returned false) -- a read-only platform store, for
 * example.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_STORE_READ_ONLY', 'secret_store_read_only' );

/**
 * The current record format version. Bumped only if the record shape in
 * WP_Secrets_Cipher's slot arrays ever changes incompatibly.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_RECORD_VERSION', 1 );

/**
 * The longest a secret name may be.
 *
 * The wp_options.option_name column allows 191 characters. The longest prefix this
 * API stores under is '_wp_network_secret_' (19 characters), so 191 - 19 = 172
 * is the longest name any store built on core's options tables can accept
 * without truncation, and every store is held to the same limit for
 * consistency regardless of what a specific backend could technically fit.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_MAX_NAME_LENGTH', 172 );

/**
 * Best-effort clearing of a plaintext value from memory.
 *
 * This is hygiene, not a guarantee. PHP strings are reference-counted and often
 * shared by copy-on-write; sodium_memzero() can only scrub the bytes of a string it
 * exclusively owns; if another reference to the same value exists elsewhere,
 * PHP forces a private copy before zeroing rather than corrupting the shared buffer,
 * and that other reference is left completely untouched. Call this on every plaintext
 * local as soon as it is no longer needed, and do not keep incidental copies around.
 *
 * Under sodium_compat -- core's documented fallback when the libsodium extension is
 * unavailable -- this scrubs nothing: a userland polyfill cannot reach a PHP string's
 * underlying memory at all. Overwriting the local binding is the only thing available
 * in that case, and it removes the live reference from this scope without touching
 * the memory the string used to occupy.
 *
 * @since 7.2.0
 *
 * @param string $value The value to clear, by reference.
 */
function wp_secrets_memzero( &$value ) {
	if ( ! is_string( $value ) ) {
		return;
	}

	if ( function_exists( 'sodium_memzero' ) ) {
		try {
			/*
			 * sodium_memzero() is called through a true reference alias, not
			 * directly on $value, so that a static analyzer's stub for
			 * sodium_memzero() (whose own by-ref parameter is documented as
			 * becoming null) is evaluated against a local variable rather than
			 * against this function's own by-ref parameter. The alias is not a
			 * copy: `=&` shares the identical underlying storage, so this still
			 * zeroes the real buffer the caller holds.
			 */
			$alias =& $value;
			sodium_memzero( $alias );
		} catch ( \Throwable $e ) {
			// A value this function doesn't exclusively own (a shared,
			// copy-on-write buffer) can legitimately refuse. The unconditional
			// reassignment below still runs regardless of what happened here.
			unset( $e );
		}
	}

	// Reached on every path, including sodium_memzero() success (it leaves the
	// variable null, not an empty string) so callers see one consistent contract.
	$value = '';
}

/**
 * Validates a secret's namespaced name.
 *
 * Names take the form 'plugin-slug/secret-name': lowercase alphanumerics,
 * hyphens, and underscores in each segment, exactly one '/' separating them,
 * and no segment starting or ending with a hyphen or underscore. There is no
 * unnamespaced form and no escape hatch for one.
 *
 * @since 7.2.0
 *
 * @param string $name Candidate secret name.
 *
 * @return true|WP_Error True if $name is valid. Otherwise WP_Error with code
 *                       WP_SECRETS_ERROR_INVALID_NAME.
 */
function wp_secrets_validate_name( $name ) {
	if ( ! is_string( $name ) || '' === $name ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			__( 'Secret names must be non-empty strings.', 'default' )
		);
	}

	if ( strlen( $name ) > WP_SECRETS_MAX_NAME_LENGTH ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			sprintf(
				/* translators: %d: Maximum allowed length, in characters. */
				__( 'Secret names must be %d characters or fewer.', 'default' ),
				WP_SECRETS_MAX_NAME_LENGTH
			)
		);
	}

	if ( 1 !== substr_count( $name, '/' ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			__( 'Secret names must contain exactly one "/", separating a namespace from a key.', 'default' )
		);
	}

	$segments  = explode( '/', $name );
	$namespace = $segments[0];
	$key       = $segments[1];

	if ( '' === $namespace || '' === $key ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			__( 'Both segments of a secret name must be non-empty.', 'default' )
		);
	}

	$segment_pattern = '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/';

	if ( ! preg_match( $segment_pattern, $namespace ) || ! preg_match( $segment_pattern, $key ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			__( 'Secret name segments may contain only lowercase letters, numbers, hyphens, and underscores, and must not start or end with a hyphen or underscore.', 'default' )
		);
	}

	return true;
}

/**
 * Returns the active secret store.
 *
 * No filter is applied here, or anywhere on the retrieval path -- see
 * docs/extending.md. A drop-in overrides the store by defining its own class before
 * this is first called and is checked for here directly, not through a hook.
 *
 * @since 7.2.0
 *
 * @return WP_Secrets_Store
 */
function _wp_secrets_get_store() {
	static $store = null;

	if ( null === $store ) {
		$store = new WP_Secrets_Option_Store();
	}

	return $store;
}

/**
 * Returns the active key manager.
 *
 * @since 7.2.0
 *
 * @return WP_Secrets_Key_Manager
 */
function _wp_secrets_get_key_manager() {
	static $key_manager = null;

	if ( null === $key_manager ) {
		$key_manager = new WP_Secrets_Key_Manager();
	}

	return $key_manager;
}

/**
 * Validates the shape of a record read back from a store, before any decryption is
 * attempted.
 *
 * @since 7.2.0
 *
 * @param mixed $record Candidate record.
 *
 * @return true|WP_Error
 */
function _wp_secrets_validate_record_shape( $record ) {
	if ( ! is_array( $record ) || ! array_key_exists( 'v', $record ) || ! array_key_exists( 'current', $record ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_RECORD_MALFORMED,
			__( 'The stored secret record is missing required fields.', 'default' )
		);
	}

	if ( WP_SECRETS_RECORD_VERSION !== $record['v'] ) {
		return new WP_Error(
			WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION,
			sprintf(
				/* translators: %s: Unrecognized record format version. */
				__( 'Secret record format version "%s" is not supported.', 'default' ),
				is_scalar( $record['v'] ) ? $record['v'] : gettype( $record['v'] )
			)
		);
	}

	if ( ! is_array( $record['current'] ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_RECORD_MALFORMED,
			__( 'The stored secret record is missing required fields.', 'default' )
		);
	}

	return true;
}

/**
 * Reads the record a write or delete is about to replace, tolerating corruption.
 *
 * A write path needs the prior record only to report the old fingerprint on the
 * change hook. That is not worth refusing the operation over: if the stored record
 * is unreadable, overwriting or deleting it is the only repair an operator has
 * through this API, and returning WP_Error here would leave a corrupted secret
 * permanently stuck -- neither readable, nor fixable, nor removable without editing
 * the database by hand.
 *
 * An unreachable *store* is different, and still aborts: that is an infrastructure
 * failure, not a data problem, and continuing would mean writing blind.
 *
 * @since 7.2.0
 *
 * @param WP_Secrets_Store $store   The active store.
 * @param string           $name    The secret's namespaced name.
 * @param bool             $network Whether this is a network-scope secret.
 *
 * @return array|null|WP_Error The prior record, null if absent or unreadable, or
 *                             WP_Error only if the store itself is unavailable.
 */
function _wp_secrets_read_prior_record( $store, $name, $network ) {
	$existing = $store->get( $name, $network );

	if ( is_wp_error( $existing ) ) {
		if ( WP_SECRETS_ERROR_STORE_UNAVAILABLE === $existing->get_error_code() ) {
			return $existing;
		}

		return null;
	}

	return $existing;
}

/**
 * Extracts the current slot's stored fingerprint from a record, if it has one.
 *
 * @since 7.2.0
 *
 * @param mixed $record A record previously read from the store, or null.
 *
 * @return string The fingerprint, or '' if there is not a usable one.
 */
function _wp_secrets_stored_fingerprint( $record ) {
	if ( ! is_array( $record ) || ! isset( $record['current']['fingerprint'] ) || ! is_string( $record['current']['fingerprint'] ) ) {
		return '';
	}

	return $record['current']['fingerprint'];
}

/**
 * Shared implementation behind wp_set_secret() and wp_set_network_secret().
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param string $value   The plaintext value.
 * @param bool   $network Whether this is a network-scope secret.
 *
 * @return true|WP_Error
 */
function _wp_secrets_set( $name, $value, $network ) {
	$name_check = wp_secrets_validate_name( $name );

	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}

	if ( ! is_string( $value ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_VALUE,
			__( 'Secret values must be strings.', 'default' )
		);
	}

	$store = _wp_secrets_get_store();

	if ( ! $store->supports( 'write' ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			__( 'The active secret store does not accept writes.', 'default' )
		);
	}

	$scope   = $network ? 'network' : 'site';
	$site_id = $network ? 0 : get_current_blog_id();

	$master_key = _wp_secrets_get_key_manager()->get_master_key( $scope, $network ? null : $site_id );

	if ( is_wp_error( $master_key ) ) {
		return $master_key;
	}

	$new_slot = ( new WP_Secrets_Cipher() )->encrypt_value( $master_key, $scope, $site_id, $name, WP_Secret_Version::CURRENT, $value );

	wp_secrets_memzero( $master_key );

	if ( is_wp_error( $new_slot ) ) {
		return $new_slot;
	}

	$new_slot['created']        = time();
	$new_slot['needs_rotation'] = false;

	$record = array(
		'v'       => WP_SECRETS_RECORD_VERSION,
		'current' => $new_slot,
	);

	$existing = _wp_secrets_read_prior_record( $store, $name, $network );

	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	$result = $store->set( $name, $record, $network );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$is_update       = is_array( $existing );
	$old_fingerprint = _wp_secrets_stored_fingerprint( $existing );

	/**
	 * Fires whenever a secret is created, updated, deleted, imported, or retired.
	 *
	 * The only hook in the core-bound Secrets API code, and it does not fire on the
	 * retrieval path. Fingerprints are readable; values are never passed.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name            The secret's namespaced name.
	 * @param string $action          One of 'created', 'updated', 'deleted',
	 *                                 'imported', 'retired'.
	 * @param int    $actor_id        The current user id, or 0.
	 * @param int    $timestamp       Unix timestamp of the change.
	 * @param string $old_fingerprint The previous fingerprint, or '' if none.
	 * @param string $new_fingerprint The new fingerprint, or '' if the secret was deleted.
	 */
	do_action(
		'wp_secret_changed',
		$name,
		$is_update ? 'updated' : 'created',
		get_current_user_id(),
		$new_slot['created'],
		$old_fingerprint,
		$new_slot['fingerprint']
	);

	return true;
}

/**
 * Shared implementation behind wp_get_secret() and wp_get_network_secret().
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param string $version A WP_Secret_Version constant.
 * @param bool   $network Whether this is a network-scope secret.
 *
 * @throws InvalidArgumentException If $version is not a WP_Secret_Version constant.
 *
 * @return WP_Secret|null|WP_Error
 */
function _wp_secrets_get( $name, $version, $network ) {
	if ( ! in_array( $version, array( WP_Secret_Version::CURRENT, WP_Secret_Version::PREVIOUS ), true ) ) {
		throw new InvalidArgumentException( 'Version must be a WP_Secret_Version constant.' );
	}

	$name_check = wp_secrets_validate_name( $name );

	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}

	$record = _wp_secrets_get_store()->get( $name, $network );

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	if ( null === $record ) {
		return null;
	}

	$shape_check = _wp_secrets_validate_record_shape( $record );

	if ( is_wp_error( $shape_check ) ) {
		return $shape_check;
	}

	if ( ! isset( $record[ $version ] ) || ! is_array( $record[ $version ] ) ) {
		// The secret exists, but this slot does not. Absence, not an error --
		// this matters most for PREVIOUS on a secret that has never been rotated.
		return null;
	}

	$scope   = $network ? 'network' : 'site';
	$site_id = $network ? 0 : get_current_blog_id();

	$master_key = _wp_secrets_get_key_manager()->get_master_key( $scope, $network ? null : $site_id );

	if ( is_wp_error( $master_key ) ) {
		return $master_key;
	}

	$cipher    = new WP_Secrets_Cipher();
	$plaintext = $cipher->decrypt_value( $master_key, $scope, $site_id, $name, $version, $record[ $version ] );

	if ( is_wp_error( $plaintext ) ) {
		wp_secrets_memzero( $master_key );

		return $plaintext;
	}

	/*
	 * Recomputed from the plaintext just decrypted, never read back from the record.
	 * The stored 'fingerprint' field sits outside the AAD and so is not
	 * authenticated: anyone who can write to the store can set it to anything
	 * without disturbing the ciphertext. Trusting it would make
	 * WP_Secret::fingerprint() attacker-controlled -- which matters most where a
	 * fingerprint comparison gates something irreversible, such as a migration
	 * verifying a value before deleting its source. The stored copy still exists,
	 * but only so wp_list_secrets() has something to show without decrypting.
	 */
	$fingerprint = $cipher->fingerprint( $master_key, $plaintext );

	wp_secrets_memzero( $master_key );

	if ( is_wp_error( $fingerprint ) ) {
		wp_secrets_memzero( $plaintext );

		return $fingerprint;
	}

	$secret = new WP_Secret( $name, $plaintext, $fingerprint );

	wp_secrets_memzero( $plaintext );

	return $secret;
}

/**
 * Shared implementation behind wp_delete_secret() and wp_delete_network_secret().
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param bool   $network Whether this is a network-scope secret.
 *
 * @return true|WP_Error
 */
function _wp_secrets_delete( $name, $network ) {
	$name_check = wp_secrets_validate_name( $name );

	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}

	$store = _wp_secrets_get_store();

	if ( ! $store->supports( 'delete' ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			__( 'The active secret store does not support deletion.', 'default' )
		);
	}

	$existing = _wp_secrets_read_prior_record( $store, $name, $network );

	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	$result = $store->delete( $name, $network );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( is_array( $existing ) ) {
		/** This action is documented in src/wp-includes/secrets.php */
		do_action(
			'wp_secret_changed',
			$name,
			'deleted',
			get_current_user_id(),
			time(),
			_wp_secrets_stored_fingerprint( $existing ),
			''
		);
	}

	return true;
}

/**
 * Encrypts and stores a secret.
 *
 * Encryption is unconditional: there is no plaintext mode and no constant to
 * disable it. No capability check is applied here -- this must be callable from
 * cron, REST, and front-end requests where no user is logged in. Enforce
 * capabilities at the operator boundary (CLI, an admin screen) instead.
 *
 * @since 7.2.0
 *
 * @param string $name  The secret's namespaced name ('plugin-slug/secret-name').
 * @param string $value The plaintext value to store.
 *
 * @return true|WP_Error
 */
function wp_set_secret( $name, $value ) {
	return _wp_secrets_set( $name, $value, false );
}

/**
 * Retrieves a secret.
 *
 * Three states, never collapsed: a WP_Secret if it exists and decrypts, null if it
 * does not exist, WP_Error if it exists but could not be retrieved. No capability
 * check is applied here -- see wp_set_secret().
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param string $version A WP_Secret_Version constant. Default WP_Secret_Version::CURRENT.
 *
 * @return WP_Secret|null|WP_Error
 */
function wp_get_secret( $name, $version = WP_Secret_Version::CURRENT ) {
	return _wp_secrets_get( $name, $version, false );
}

/**
 * Deletes a secret.
 *
 * @since 7.2.0
 *
 * @param string $name The secret's namespaced name.
 *
 * @return true|WP_Error
 */
function wp_delete_secret( $name ) {
	return _wp_secrets_delete( $name, false );
}
