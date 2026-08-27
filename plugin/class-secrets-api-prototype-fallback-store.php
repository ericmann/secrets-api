<?php
/**
 * Secrets API: prototype-format fallback store (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

/**
 * Serves a read whose new-format record does not exist yet from the prototype's
 * option row instead, upgrading it to the current format on the way through.
 *
 * The problem this solves is adoption, not compatibility. The AI plugin was built
 * on the prototype, so its sites have credentials sitting in prototype-format
 * rows. When that plugin moves to the Secrets API, every one of those sites would
 * otherwise see wp_get_secret() return null for a credential it demonstrably has,
 * and would need an explicit migration run -- per site -- before working again.
 * Sites that nobody remembers to migrate would simply break, and a credential that
 * cannot be re-entered from memory means a rebuild.
 *
 * So instead: a miss falls through to the prototype row, the value is re-encrypted
 * into a proper current-format record, and the caller gets a normal WP_Secret. The
 * next read hits the new record directly and never comes back here. The upgrade is
 * one-way and happens once per secret, on first use.
 *
 * A note on what this is NOT. It does not implement the prototype's API, reinstate
 * its function names, or let prototype-era code keep running -- that would be a
 * compatibility layer, and there is deliberately none. It reads one option row,
 * once, and never writes to or deletes anything the prototype owns. Both systems
 * keep working on the same site throughout, because the two option namespaces do
 * not overlap.
 *
 * ---
 *
 * Implemented as a decorator around whatever store would otherwise be active,
 * rather than as changes to WP_Secrets_Option_Store, for two reasons. Core has no
 * business knowing this format ever existed, and src/ stays a clean file-copy
 * candidate. And it composes: this wraps the default option store, and a
 * secrets.php drop-in that installs a host store replaces it entirely, which is
 * the right outcome -- a host serving secrets from its own platform has no
 * prototype rows to inherit.
 */
final class Secrets_API_Prototype_Fallback_Store implements WP_Secrets_Store {

	/**
	 * The store actually holding current-format records.
	 *
	 * @var WP_Secrets_Store
	 */
	private $inner;

	/**
	 * Names currently mid-upgrade, guarding against re-entry.
	 *
	 * The upgrade writes through _wp_secrets_set(), which reads the prior record
	 * first -- landing back in this class's own get() for the same name. Without
	 * this, that second miss would start another upgrade, and so on.
	 *
	 * @var array<string, bool>
	 */
	private $upgrading = array();

	/**
	 * Wraps an existing store.
	 *
	 * @param WP_Secrets_Store $inner The store to delegate to.
	 */
	public function __construct( WP_Secrets_Store $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Reads a record, falling back to the prototype format on a miss.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return array|null|WP_Error
	 */
	public function get( $name, $network = false ) {
		$record = $this->inner->get( $name, $network );

		// Only an outright miss is a candidate. A WP_Error means the store is
		// unhealthy, and quietly answering from somewhere else would turn a
		// fail-closed read into a fail-open one.
		if ( null !== $record ) {
			return $record;
		}

		// The prototype had no network scope, so there is nothing to inherit.
		if ( $network ) {
			return null;
		}

		if ( isset( $this->upgrading[ $name ] ) ) {
			return null;
		}

		return $this->upgrade_from_prototype( $name );
	}

	/**
	 * Reads the prototype row behind a name, writes it into the current format,
	 * and returns the resulting record.
	 *
	 * Failure at any step returns null -- the same answer as the miss that got us
	 * here. A site with no prototype rows, an unreadable prototype row, or a
	 * read-only store all end up reporting the secret as absent, which is what it
	 * is as far as the current format is concerned.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return array|null
	 */
	private function upgrade_from_prototype( $name ) {
		$prototype_key = $this->prototype_key_for( $name );

		if ( null === $prototype_key ) {
			return null;
		}

		$plaintext = ( new Secrets_API_Legacy_Reader() )->get( $prototype_key );

		if ( is_wp_error( $plaintext ) ) {
			return null;
		}

		$this->upgrading[ $name ] = true;

		/*
		 * Written through the normal set path rather than assembled here: it
		 * derives the master key, binds the AAD, and fires wp_secret_changed with
		 * the 'imported' action, so an upgrade that happens silently at read time
		 * is still visible to anything auditing secret changes. Flagged
		 * needs_rotation for the same reason wp_import_option_as_secret() does --
		 * this credential has been sitting in the prototype's format, and
		 * re-encrypting it now does not undo wherever it has already been.
		 */
		$result = _wp_secrets_set( $name, $plaintext, false, true, 'imported' );

		wp_secrets_memzero( $plaintext );

		unset( $this->upgrading[ $name ] );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $this->inner->get( $name, false );
	}

	/**
	 * Whether a current-format record already exists, without the fallback.
	 *
	 * Ordinary callers want the fallback -- that is the entire point. The migrator
	 * does not: it needs to report whether a secret was already in the current
	 * format, and asking through get() would upgrade the very thing it is trying
	 * to describe. That is not just a cosmetic reporting problem; it would make
	 * `--dry-run` write.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return bool
	 */
	public function has_current_record( $name ) {
		return is_array( $this->inner->get( $name, false ) );
	}

	/**
	 * Maps a current-format name onto the prototype key that would have held it.
	 *
	 * The prototype's keys are flat and unnamespaced ('api_key'); current names
	 * are always 'namespace/key'. The mapping is therefore to drop the namespace,
	 * which means any namespace can inherit a given prototype row.
	 *
	 * That is deliberate, and it is not the exposure it first looks like. The
	 * prototype's keyspace is global: every plugin on the site could already read
	 * every prototype secret by calling its get_secret() with the bare key, with
	 * no namespace to check against. Inheriting one into 'ai/api_key' hands it to
	 * code that could already have read it, and the inherited copy is a new row --
	 * the prototype's own is never modified. Requiring an exact namespace match
	 * instead would mean guessing which namespace the adopting plugin will choose,
	 * and guessing wrong reintroduces exactly the broken-site outcome this exists
	 * to prevent.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return string|null The prototype key, or null if $name is not well-formed.
	 */
	private function prototype_key_for( $name ) {
		if ( ! is_string( $name ) || 1 !== substr_count( $name, '/' ) ) {
			return null;
		}

		$key = substr( $name, strpos( $name, '/' ) + 1 );

		return ( '' === $key ) ? null : $key;
	}

	/**
	 * Writes a current-format record. Delegated unchanged.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param array  $record  The record to store.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return bool|WP_Error
	 */
	public function set( $name, $record, $network = false ) {
		return $this->inner->set( $name, $record, $network );
	}

	/**
	 * Deletes a current-format record. The prototype's row is left alone, so a
	 * delete followed by a read will inherit the prototype value again rather than
	 * reporting absence -- the same answer the site would have given before the
	 * Secrets API was installed at all.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( $name, $network = false ) {
		return $this->inner->delete( $name, $network );
	}

	/**
	 * Lists current-format names only. Prototype rows that have not been read yet
	 * are not listed: they are not secrets of this API until something asks for
	 * one by name. `wp secret migrate-legacy --dry-run` is the way to see what is
	 * still sitting in the old format.
	 *
	 * @param bool $network Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_names( $network = false ) {
		return $this->inner->list_names( $network );
	}

	/**
	 * Reports the inner store's capabilities. Wrapping adds none of its own.
	 *
	 * @param string $capability One of 'write', 'list', 'delete'.
	 *
	 * @return bool
	 */
	public function supports( $capability ) {
		return $this->inner->supports( $capability );
	}
}
