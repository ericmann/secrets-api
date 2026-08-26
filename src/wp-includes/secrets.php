<?php
/**
 * Secrets API
 *
 * @package WordPress
 * @subpackage Secrets
 * @since 7.2.0
 */

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
