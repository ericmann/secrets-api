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
 * A caller passed an argument this API cannot act on -- an unrecognised version
 * constant, an invalid scope or slot, a non-string namespace.
 *
 * Distinct from the codes above in cause rather than in severity: those describe
 * a runtime condition a correct caller can still hit (a name that failed
 * validation, an unavailable key), while this one only ever means the calling
 * code is wrong. Every site that returns it also calls _doing_it_wrong(), so the
 * mistake is visible in development rather than only in a return value the caller
 * may not be checking.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_INVALID_ARGUMENT', 'secret_invalid_argument' );

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
 * The active provider does not accept writes: its credentials are managed by host
 * tooling, a control panel, or a key policy outside WordPress. Distinct from
 * WP_SECRETS_ERROR_STORE_UNAVAILABLE, which means the write could have happened and
 * did not -- this one means it was never going to.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_ERROR_PROVIDER_READ_ONLY', 'secret_provider_read_only' );

/**
 * The store declined a write, delete, or list because it does not support that
 * operation (WP_Secrets_Store::supports() returned false) -- a read-only platform
 * store, for example. The constant name reflects the operation this was first
 * written for; the code covers all three capabilities.
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
 * Capability required at the operator boundary (CLI, a future admin screen) to
 * manage site-scope secrets. Never checked by the function-level API itself --
 * see wp_set_secret()'s docblock.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_CAP_MANAGE', 'manage_secrets' );

/**
 * Capability required at the operator boundary to manage network-scope secrets.
 *
 * @since 7.2.0
 */
define( 'WP_SECRETS_CAP_MANAGE_NETWORK', 'manage_network_secrets' );

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
 * Validates a secret's name.
 *
 * Names take the form 'plugin-slug/secret-name': lowercase alphanumerics,
 * hyphens, and underscores in each segment, exactly one '/' separating them,
 * and no segment starting or ending with a hyphen or underscore.
 *
 * An unnamespaced name ('secret-name', no '/') is accepted, but reports through
 * _doing_it_wrong(). It exists for one reason: code written against the Displace
 * prototype used a flat keyspace, and refusing those names outright would mean
 * every such call site has to be rewritten before it can be ported at all.
 * Accepting them keeps that migration incremental. Nothing else should use one --
 * without a namespace there is nothing for a future cross-namespace access check
 * to check against, and two plugins picking 'api-key' collide silently.
 *
 * @since 7.2.0
 *
 * @param string $name Candidate secret name.
 *
 * @return true|WP_Error True if $name is usable, including the unnamespaced form.
 *                       Otherwise WP_Error with code WP_SECRETS_ERROR_INVALID_NAME.
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

	$slashes = substr_count( $name, '/' );

	if ( $slashes > 1 ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_NAME,
			__( 'Secret names may contain at most one "/", separating a namespace from a key.', 'default' )
		);
	}

	$segment_pattern = '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/';

	if ( 0 === $slashes ) {
		if ( ! preg_match( $segment_pattern, $name ) ) {
			return new WP_Error(
				WP_SECRETS_ERROR_INVALID_NAME,
				__( 'Secret names may contain only lowercase letters, numbers, hyphens, and underscores, and must not start or end with a hyphen or underscore.', 'default' )
			);
		}

		/*
		 * Reported here rather than at each public entry point: this is the one
		 * place that decides what a name is, so a caller cannot reach the store
		 * with an unnamespaced name without passing through it.
		 */
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: %s: The unnamespaced secret name. */
				__( 'The secret name "%s" has no namespace. Namespaced names ("plugin-slug/secret-name") group secrets by owner and give a future access check something to check against; unnamespaced names are supported only so that code written against the Displace prototype can be ported incrementally.', 'default' ),
				$name
			),
			'7.2.0'
		);

		return true;
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
 * docs/extending.md. A secrets.php drop-in overrides the store by setting
 * $GLOBALS['wp_secrets_store'] to an instance before this is first called, and
 * that global is checked here directly, not through a hook.
 *
 * If the global was set but is not a valid WP_Secrets_Store, or if the drop-in
 * itself failed to load cleanly, this returns WP_Secrets_Broken_Store rather than
 * silently using the default: the drop-in's presence signals the operator wants
 * storage other than local options, and falling back to local options anyway would
 * be the exact "fall back to local storage" failure §2.6 forbids.
 *
 * @since 7.2.0
 *
 * @return WP_Secrets_Store
 */
function _wp_secrets_get_store() {
	static $store = null;

	if ( null !== $store ) {
		return $store;
	}

	if ( ! empty( $GLOBALS['wp_secrets_dropin_broken'] ) ) {
		$store = new WP_Secrets_Broken_Store();

		return $store;
	}

	if ( isset( $GLOBALS['wp_secrets_store'] ) && $GLOBALS['wp_secrets_store'] instanceof WP_Secrets_Store ) {
		$store = $GLOBALS['wp_secrets_store'];

		return $store;
	}

	$store = new WP_Secrets_Option_Store();

	return $store;
}

/**
 * Returns the active key manager.
 *
 * A secrets.php drop-in overrides the keyring by setting
 * $GLOBALS['wp_secrets_keyring'] to an instance before this is first called. See
 * _wp_secrets_get_store() for why an invalid override or a failed drop-in load
 * fails closed (WP_Secrets_Broken_Keyring) rather than falling back to the default.
 *
 * @since 7.2.0
 *
 * @return WP_Secrets_Key_Manager
 */
function _wp_secrets_get_key_manager() {
	static $key_manager = null;

	if ( null !== $key_manager ) {
		return $key_manager;
	}

	if ( ! empty( $GLOBALS['wp_secrets_dropin_broken'] ) ) {
		$key_manager = new WP_Secrets_Key_Manager( new WP_Secrets_Broken_Keyring() );

		return $key_manager;
	}

	$keyring = null;

	if ( isset( $GLOBALS['wp_secrets_keyring'] ) && $GLOBALS['wp_secrets_keyring'] instanceof WP_Secrets_Keyring ) {
		$keyring = $GLOBALS['wp_secrets_keyring'];
	}

	$key_manager = new WP_Secrets_Key_Manager( $keyring );

	return $key_manager;
}

/**
 * Whether a secrets.php drop-in is present and was loaded.
 *
 * True whether or not the drop-in successfully provided a store or keyring
 * override -- this reports presence, not health. Site Health reports separately
 * on whether a loaded drop-in is actually working.
 *
 * @since 7.2.0
 *
 * @return bool
 */
function wp_using_secrets_dropin() {
	return ! empty( $GLOBALS['wp_secrets_dropin_loaded'] );
}

/**
 * Whether the active store supports a given capability.
 *
 * Exists so a settings screen can disable its own save button before an operator
 * types a credential into a store that will only reject it, rather than
 * discovering that after the fact.
 *
 * @since 7.2.0
 *
 * @param string $capability One of 'write', 'list', 'delete'.
 *
 * @return bool
 */
function wp_secrets_store_supports( $capability ) {
	return _wp_secrets_get_store()->supports( $capability );
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
 * Re-encrypts an outgoing current slot so it can be stored as the previous slot.
 *
 * A slot's ciphertext is bound, via AAD, to the exact position it was written to.
 * Copying its stored bytes as-is into the other slot would leave it permanently
 * undecryptable there: the authentication tag was computed for the slot it came
 * from, and every future read asks for it under the other one. Demoting a slot
 * means decrypting it under its old binding and re-encrypting the same plaintext
 * under the new one.
 *
 * @since 7.2.0
 *
 * @param WP_Secrets_Cipher $cipher       A cipher instance.
 * @param string            $master_key   32-byte master key.
 * @param string            $scope        'site' or 'network'.
 * @param int               $site_id      Blog id for site scope, 0 for network scope.
 * @param string            $name         The secret's namespaced name.
 * @param array             $current_slot The outgoing current slot, as stored.
 *
 * @return array|WP_Error The same value, re-encrypted and bound to
 *                        WP_Secret_Version::PREVIOUS. 'created' and
 *                        'needs_rotation' carry over unchanged; 'fingerprint' is
 *                        recomputed but identical, since the plaintext and master
 *                        key are unchanged.
 */
function _wp_secrets_demote_slot( $cipher, $master_key, $scope, $site_id, $name, $current_slot ) {
	$plaintext = $cipher->decrypt_value( $master_key, $scope, $site_id, $name, WP_Secret_Version::CURRENT, $current_slot );

	if ( is_wp_error( $plaintext ) ) {
		return $plaintext;
	}

	$demoted = $cipher->encrypt_value( $master_key, $scope, $site_id, $name, WP_Secret_Version::PREVIOUS, $plaintext );

	wp_secrets_memzero( $plaintext );

	if ( is_wp_error( $demoted ) ) {
		return $demoted;
	}

	$demoted['created']        = isset( $current_slot['created'] ) ? $current_slot['created'] : time();
	$demoted['needs_rotation'] = isset( $current_slot['needs_rotation'] ) ? $current_slot['needs_rotation'] : false;

	return $demoted;
}

/**
 * Shared implementation behind wp_set_secret(), wp_set_network_secret(), and
 * wp_import_option_as_secret() (via the last two parameters).
 *
 * @since 7.2.0
 *
 * @param string      $name           The secret's namespaced name.
 * @param string      $value          The plaintext value.
 * @param bool        $network        Whether this is a network-scope secret.
 * @param bool        $needs_rotation Value for the new current slot's
 *                                    'needs_rotation' flag. False for an ordinary
 *                                    write; wp_import_option_as_secret() passes true,
 *                                    since a credential that sat in an option is
 *                                    already in backups and re-encrypting does not
 *                                    fix that.
 * @param string|null $action_override When given, used as the $action reported to
 *                                     the wp_secret_changed hook instead of the
 *                                     usual 'created'/'updated' detection --
 *                                     wp_import_option_as_secret() passes 'imported'.
 *
 * @return true|WP_Error
 */
function _wp_secrets_set( $name, $value, $network, $needs_rotation = false, $action_override = null ) {
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

	$existing = _wp_secrets_read_prior_record( $store, $name, $network );

	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	$master_key = _wp_secrets_get_key_manager()->get_master_key( $scope, $network ? null : $site_id );

	if ( is_wp_error( $master_key ) ) {
		return $master_key;
	}

	$cipher   = new WP_Secrets_Cipher();
	$new_slot = $cipher->encrypt_value( $master_key, $scope, $site_id, $name, WP_Secret_Version::CURRENT, $value );

	if ( is_wp_error( $new_slot ) ) {
		wp_secrets_memzero( $master_key );

		return $new_slot;
	}

	$new_slot['created']        = time();
	$new_slot['needs_rotation'] = $needs_rotation;

	$record = array(
		'v'       => WP_SECRETS_RECORD_VERSION,
		'current' => $new_slot,
	);

	/*
	 * Demotion: the outgoing current slot becomes the new previous slot. Only
	 * one previous slot is ever kept -- whatever was already in
	 * $existing['previous'] is never carried forward, which is what makes a
	 * third write discard the oldest value rather than accumulating history.
	 *
	 * This cannot be a plain array copy. A slot's AAD binds its ciphertext to
	 * the exact position it occupies -- current or previous -- so a slot moved
	 * verbatim would still carry an authentication tag computed for 'current'
	 * while every future read asks for it under 'previous', and would fail to
	 * decrypt forever. Demoting requires decrypting under the outgoing binding
	 * and re-encrypting under the incoming one.
	 *
	 * If the outgoing current slot cannot be decrypted at all -- already
	 * corrupted, or orphaned by a botched key rotation -- it is dropped rather
	 * than failing this write. The value was already unreadable before this
	 * call; refusing to let an operator set a new one over it would repeat the
	 * corrupted-record-blocks-everything failure this API is built to avoid.
	 */
	if ( is_array( $existing ) && isset( $existing['current'] ) && is_array( $existing['current'] ) ) {
		$demoted = _wp_secrets_demote_slot( $cipher, $master_key, $scope, $site_id, $name, $existing['current'] );

		if ( ! is_wp_error( $demoted ) ) {
			$record['previous'] = $demoted;
		}
	}

	wp_secrets_memzero( $master_key );

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
		null !== $action_override ? $action_override : ( $is_update ? 'updated' : 'created' ),
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
 * @return WP_Secret|null|WP_Error
 */
function _wp_secrets_get( $name, $version, $network ) {
	if ( ! in_array( $version, array( WP_Secret_Version::CURRENT, WP_Secret_Version::PREVIOUS ), true ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			__( 'The version must be WP_Secret_Version::CURRENT or WP_Secret_Version::PREVIOUS.', 'default' ),
			'7.2.0'
		);

		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_ARGUMENT,
			__( 'The version must be WP_Secret_Version::CURRENT or WP_Secret_Version::PREVIOUS.', 'default' )
		);
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
 * Shared implementation behind wp_retire_secret_version() and
 * wp_retire_network_secret_version().
 *
 * Beyond the published API surface -- see docs/open-questions.md, "API surface that was never published". The proposal
 * states retirement is "an explicit operator action" but names no function; this is
 * this implementation's name for it, pending confirmation in the comments thread.
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param bool   $network Whether this is a network-scope secret.
 *
 * @return true|WP_Error
 */
function _wp_secrets_retire( $name, $network ) {
	$name_check = wp_secrets_validate_name( $name );

	if ( is_wp_error( $name_check ) ) {
		return $name_check;
	}

	$store  = _wp_secrets_get_store();
	$record = $store->get( $name, $network );

	if ( is_wp_error( $record ) ) {
		return $record;
	}

	if ( null === $record || ! isset( $record['previous'] ) ) {
		/*
		 * The goal of retirement -- "there is no previous slot" -- is already
		 * true, whether because the secret doesn't exist or because it was
		 * never rotated. A successful no-op, not an error, and deliberately
		 * checked before the write-support check below: a store that can't
		 * accept writes can still report a correct "nothing to do" without
		 * that being mistaken for the store refusing the operation.
		 */
		return true;
	}

	if ( ! $store->supports( 'write' ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			__( 'The active secret store does not accept writes.', 'default' )
		);
	}

	$retired_fingerprint = isset( $record['previous']['fingerprint'] ) && is_string( $record['previous']['fingerprint'] )
		? $record['previous']['fingerprint']
		: '';

	unset( $record['previous'] );

	$result = $store->set( $name, $record, $network );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	/** This action is documented in src/wp-includes/secrets.php */
	do_action(
		'wp_secret_changed',
		$name,
		'retired',
		get_current_user_id(),
		time(),
		$retired_fingerprint,
		''
	);

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
 * Imports an existing option's value as a secret.
 *
 * For a deliberate, explicit migration -- "on their own explicit upgrade schedule,"
 * in the proposal's words, because "core cannot reliably tell which options are
 * credentials, and guessing would break sites." The source option is left
 * untouched: this reads it, it does not move or delete it.
 *
 * The imported secret is flagged needs_rotation, unconditionally. A credential that
 * sat in a plain option has already been through however many backups and
 * replication paths that option went through; re-encrypting it here does not undo
 * that, and the flag exists so an operator (or a future admin screen) knows to
 * actually rotate the value rather than considering the migration finished.
 *
 * @since 7.2.0
 *
 * @param string $option The existing option's name.
 * @param string $name   The secret's namespaced name to store it under.
 *
 * @return true|WP_Error
 */
function wp_import_option_as_secret( $option, $name ) {
	if ( ! is_string( $option ) || '' === $option ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_VALUE,
			__( 'Option name must be a non-empty string.', 'default' )
		);
	}

	$value = get_option( $option, null );

	if ( null === $value ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_VALUE,
			__( 'The option does not exist.', 'default' )
		);
	}

	if ( ! is_string( $value ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_VALUE,
			__( 'The option value is not a string and cannot be imported as a secret.', 'default' )
		);
	}

	return _wp_secrets_set( $name, $value, false, true, 'imported' );
}

/**
 * Shared implementation behind wp_list_secrets() and wp_list_network_secrets().
 *
 * Beyond the published API surface -- see docs/open-questions.md, "API surface that was never published". Justified by
 * the proposal's statement that "the hooks and accessors an admin screen would need
 * are in scope now; the screen itself is not."
 *
 * Fingerprints returned here come directly from the stored record field, not
 * recomputed by decrypting each secret. That is a deliberate difference from
 * WP_Secret::fingerprint(), which always recomputes (see Checkpoint C in
 * docs/open-questions.md) -- recomputing here would mean decrypting every matching
 * secret just to list them, defeating the point of a lightweight listing call. This
 * is safe specifically because a list entry is documented as informational only and
 * is never used to gate anything; nothing in this codebase performs a security
 * decision based on a fingerprint returned from this function.
 *
 * @since 7.2.0
 *
 * @param string $name_prefix Only secrets whose name starts with "{$name_prefix}/"
 *                             are returned. Default '' returns every secret in this
 *                             scope. Named to match the public function's own
 *                             $namespace parameter, without using the reserved word
 *                             'namespace' in an internal signature.
 * @param bool   $network     Whether to list network-scope secrets.
 *
 * @return array|WP_Error Array of associative arrays, each with keys 'name',
 *                        'fingerprint', 'created', 'has_previous', and
 *                        'needs_rotation'. Never a value. WP_Error on failure.
 */
function _wp_secrets_list( $name_prefix, $network ) {
	if ( ! is_string( $name_prefix ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			__( 'The namespace must be a string.', 'default' ),
			'7.2.0'
		);

		return new WP_Error(
			WP_SECRETS_ERROR_INVALID_ARGUMENT,
			__( 'The namespace must be a string.', 'default' )
		);
	}

	$store = _wp_secrets_get_store();

	if ( ! $store->supports( 'list' ) ) {
		return new WP_Error(
			WP_SECRETS_ERROR_STORE_READ_ONLY,
			__( 'The active secret store does not support listing.', 'default' )
		);
	}

	$names = $store->list_names( $network );

	if ( is_wp_error( $names ) ) {
		return $names;
	}

	$entries = array();

	foreach ( $names as $name ) {
		if ( '' !== $name_prefix && 0 !== strpos( $name, $name_prefix . '/' ) ) {
			continue;
		}

		$record = $store->get( $name, $network );

		if ( ! is_wp_error( $record ) && null !== $record && true === _wp_secrets_validate_record_shape( $record ) ) {
			$entries[] = array(
				'name'           => $name,
				'fingerprint'    => _wp_secrets_stored_fingerprint( $record ),
				'created'        => isset( $record['current']['created'] ) && is_int( $record['current']['created'] ) ? $record['current']['created'] : 0,
				'has_previous'   => isset( $record['previous'] ) && is_array( $record['previous'] ),
				'needs_rotation' => ! empty( $record['current']['needs_rotation'] ),
			);

			continue;
		}

		/*
		 * A corrupted or unreadable record is still listed, with whatever
		 * metadata could not be salvaged left blank, rather than silently
		 * omitted. Site Health's "undecryptable secrets" check (§8) depends on
		 * exactly this: a secret that has gone bad must remain visible.
		 */
		$entries[] = array(
			'name'           => $name,
			'fingerprint'    => '',
			'created'        => 0,
			'has_previous'   => false,
			'needs_rotation' => false,
		);
	}

	return $entries;
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

/**
 * Clears a secret's previous version, retiring it for good.
 *
 * An explicit operator action -- no timers, no cron. Calling this on a secret
 * that has never been rotated, or that does not exist, is a successful no-op:
 * the previous slot is already absent either way.
 *
 * Beyond the published API surface; see docs/open-questions.md, "API surface that was never published".
 *
 * @since 7.2.0
 *
 * @param string $name The secret's namespaced name.
 *
 * @return true|WP_Error
 */
function wp_retire_secret_version( $name ) {
	return _wp_secrets_retire( $name, false );
}

/**
 * Lists secrets by name and metadata. Never a value.
 *
 * Beyond the published API surface; see docs/open-questions.md, "API surface that was never published". Justified by the
 * proposal's statement that the hooks and accessors a future admin screen needs are
 * in scope now, even though the screen itself is not.
 *
 * @since 7.2.0
 *
 * @param string $namespace Only secrets whose name starts with "{$namespace}/" are
 *                           returned. Default '' returns every secret.
 *
 * @return array|WP_Error Array of associative arrays, each with keys 'name',
 *                        'fingerprint', 'created', 'has_previous', and
 *                        'needs_rotation'.
 */
function wp_list_secrets( $namespace = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- matches the build brief's specified signature exactly.
	return _wp_secrets_list( $namespace, false );
}

/**
 * Encrypts and stores a network-scope secret.
 *
 * Site secrets and network secrets are separate functions with separate
 * capabilities and separate storage prefixes, with no implicit fallback from one
 * scope to the other. The names, not published by the proposal, are tracked in
 * docs/open-questions.md, "API surface that was never published".
 *
 * @since 7.2.0
 *
 * @param string $name  The secret's namespaced name.
 * @param string $value The plaintext value to store.
 *
 * @return true|WP_Error
 */
function wp_set_network_secret( $name, $value ) {
	return _wp_secrets_set( $name, $value, true );
}

/**
 * Retrieves a network-scope secret.
 *
 * @since 7.2.0
 *
 * @param string $name    The secret's namespaced name.
 * @param string $version A WP_Secret_Version constant. Default WP_Secret_Version::CURRENT.
 *
 * @return WP_Secret|null|WP_Error
 */
function wp_get_network_secret( $name, $version = WP_Secret_Version::CURRENT ) {
	return _wp_secrets_get( $name, $version, true );
}

/**
 * Deletes a network-scope secret.
 *
 * @since 7.2.0
 *
 * @param string $name The secret's namespaced name.
 *
 * @return true|WP_Error
 */
function wp_delete_network_secret( $name ) {
	return _wp_secrets_delete( $name, true );
}

/**
 * Clears a network-scope secret's previous version.
 *
 * @since 7.2.0
 *
 * @param string $name The secret's namespaced name.
 *
 * @return true|WP_Error
 */
function wp_retire_network_secret_version( $name ) {
	return _wp_secrets_retire( $name, true );
}

/**
 * Lists network-scope secrets by name and metadata. Never a value.
 *
 * @since 7.2.0
 *
 * @param string $namespace Only secrets whose name starts with "{$namespace}/" are
 *                           returned. Default '' returns every network-scope secret.
 *
 * @return array|WP_Error
 */
function wp_list_network_secrets( $namespace = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- matches wp_list_secrets()'s own signature.
	return _wp_secrets_list( $namespace, true );
}
