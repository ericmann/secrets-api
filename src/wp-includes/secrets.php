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
