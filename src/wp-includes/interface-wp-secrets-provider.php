<?php
/**
 * Secrets API: WP_Secrets_Provider interface
 *
 * @package WordPress
 * @subpackage Secrets
 * @since 7.2.0
 */

/**
 * What is responsible for a secret: holding it, protecting it, and answering for it.
 *
 * This is the outermost extension point, and the one a hosting platform implements.
 * WordPress ships a provider that encrypts with libsodium and stores ciphertext in
 * the options tables, and that provider is the default rather than the privileged
 * case: a platform that protects credentials in a KMS, an HSM, or its own control
 * panel implements this interface instead and is a peer, not a special case bolted
 * onto the side of the default.
 *
 * The rule a provider must satisfy is **stronger than the default, never weaker**.
 * Storing a plaintext where the default would have stored ciphertext is the one
 * thing this interface exists to keep impossible; receiving a value over an
 * authenticated channel and protecting it in an HSM is not that, and was only ever
 * blocked by an earlier contract that described a mechanism ("never handed a
 * plaintext") where it meant a property ("encryption cannot be turned off").
 *
 * ### What WordPress can and cannot check
 *
 * It cannot check any of this. A provider is loaded from a drop-in, which is fully
 * trusted code that runs before plugins and could already read every secret by
 * implementing the keyring. get_protection_boundary() and is_writable() are
 * therefore **declarations, not enforcement**: their value is that a human
 * reviewing a drop-in can see what it claims, Site Health can tell an operator
 * where their credentials are actually protected, and a settings screen can find
 * out that a write will be refused before an operator types a credential into it.
 * Treating them as a security boundary would be a mistake.
 *
 * ### Contracts every implementation shares
 *
 * - **Three states, never collapsed.** get() returns a WP_Secret, null when the
 *   secret does not exist, or WP_Error when it exists but cannot be produced.
 *   "Unreachable" must never be reported as "absent" -- that turns an outage into
 *   an apparently-deleted credential, and is the single failure this API exists to
 *   prevent.
 * - **Fail closed.** A provider that cannot answer returns WP_Error. It never
 *   substitutes a weaker source, and never returns a partial or placeholder value.
 * - **No filter on the retrieval path.** A provider is a replacement, not a hook.
 *   Nothing may be given the opportunity to observe or alter a value in transit,
 *   which is the whole reason this is an interface rather than an
 *   'wp_secret_value' filter.
 * - **Never persist a value more weakly than you protect it.** In particular, a
 *   provider that fetches over the network must not write the plaintext into the
 *   persistent object cache: WP_Secret deliberately cannot round-trip a plaintext
 *   through wp_cache_set(), and caching the raw value alongside it would quietly
 *   undo that. Request-scoped memoisation only.
 *
 * @since 7.2.0
 */
interface WP_Secrets_Provider {

	/**
	 * Protection happens inside WordPress: this provider holds ciphertext that
	 * WordPress produced, and WordPress holds the key material.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	const BOUNDARY_WORDPRESS = 'wordpress';

	/**
	 * Protection happens outside WordPress: this provider is itself the encryption
	 * boundary, and WordPress is a consumer of a credential it does not protect.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	const BOUNDARY_PROVIDER = 'provider';

	/**
	 * Retrieves a secret.
	 *
	 * Returning null means "I am not responsible for this name" as well as "this
	 * name does not exist" -- the two are the same answer from a caller's point of
	 * view, and keeping them distinct would require a provider to enumerate names
	 * it has never heard of.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name    The secret's name.
	 * @param string $version A WP_Secret_Version constant. A provider with no
	 *                        version history returns null for PREVIOUS, which is
	 *                        absence rather than an error.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Secret|null|WP_Error
	 */
	public function get( $name, $version, $network = false );

	/**
	 * Stores a secret.
	 *
	 * A provider whose credentials are managed elsewhere -- a control panel, host
	 * tooling, a KMS with its own access policy -- returns WP_Error here with code
	 * WP_SECRETS_ERROR_PROVIDER_READ_ONLY, and reports false from is_writable() so
	 * that callers can find that out without attempting the write first.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name    The secret's name.
	 * @param string $value   The plaintext value.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function set( $name, $value, $network = false );

	/**
	 * Deletes a secret.
	 *
	 * Deleting something already absent is success, not failure.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name    The secret's name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function delete( $name, $network = false );

	/**
	 * Clears a secret's previous version, if this provider keeps one.
	 *
	 * A provider with no version history treats this as a successful no-op: there
	 * is nothing to retire, which is the state the caller asked for.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name    The secret's name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function retire_previous( $name, $network = false );

	/**
	 * Lists secrets by name and metadata. Never values.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name_prefix Restrict to names beginning with this prefix, or
	 *                            '' for all of them.
	 * @param bool   $network     Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error List of arrays with keys 'name', 'fingerprint',
	 *                        'created', 'has_previous', 'needs_rotation'.
	 */
	public function list_secrets( $name_prefix = '', $network = false );

	/**
	 * A short human-readable name for this provider, for Site Health.
	 *
	 * Describes the protection, not the vendor's marketing: "AWS KMS
	 * (alias/wp-secrets)" tells an operator something, "SuperSecure Pro" does not.
	 * Never key material, never a credential, never a value.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Where secrets this provider holds are actually protected.
	 *
	 * One of the BOUNDARY_* constants. This is what lets Site Health and a future
	 * admin screen tell an operator whether their credentials are protected by
	 * WordPress's own libsodium envelope or by something outside it -- a question
	 * they currently have no way to answer, and the one hosts asked to be able to
	 * answer honestly.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function get_protection_boundary();

	/**
	 * Whether wp_set_secret() can succeed against this provider.
	 *
	 * False for a provider whose credentials are managed by host tooling or a
	 * control panel. Declared rather than discovered so a settings screen can
	 * disable its save control before an operator types a credential into a field
	 * that will only reject it.
	 *
	 * @since 7.2.0
	 *
	 * @return bool
	 */
	public function is_writable();
}
