<?php
/**
 * Secrets API: legacy reader (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

/**
 * Read-only access to displace-secrets-manager's on-disk format.
 *
 * Never writes, never deletes -- this class exists purely so
 * Secrets_API_Migrator can read a value out of the old format to write it into the
 * new one. All legacy crypto is isolated here specifically so it can be deleted in
 * one commit when the compatibility window closes, per the build brief's §9.4.
 *
 * The legacy format, verified from source (§9.1 of the build brief):
 *
 * - Secret option: '_secret_{key}'.
 * - Master key option: '_secrets_master_key'.
 * - Cipher: sodium_crypto_secretbox (XSalsa20-Poly1305).
 * - Record: base64( nonce . ciphertext ) -- a single string, not an array. No AAD.
 * - Site key: sodium_crypto_generichash( $material, '', 32 ), where $material is
 *   the literal WP_SECRETS_KEY string if defined, or LOGGED_IN_KEY . LOGGED_IN_SALT.
 *
 * Critically, legacy always hashes WP_SECRETS_KEY's literal string form -- there is
 * no "raw base64-32 bytes" path here at all, unlike the new format's key provider.
 * A site with WP_SECRETS_KEY defined is therefore not automatically compatible
 * between the two formats even when the constant happens to be valid base64-32; the
 * salt-fallback path is the one that is byte-identical between old and new, which
 * is deliberate and is what lets those sites migrate with zero credential re-entry.
 */
final class Secrets_API_Legacy_Reader {

	const SECRET_OPTION_PREFIX = '_secret_';

	const MASTER_KEY_OPTION = '_secrets_master_key';

	/**
	 * Lists every legacy secret's bare key name. Still read-only: a listing, not a
	 * value.
	 *
	 * @return array|WP_Error List of bare key names on success. WP_Error on failure.
	 */
	public function list_keys() {
		global $wpdb;

		$pattern = $wpdb->esc_like( self::SECRET_OPTION_PREFIX ) . '%';

		$option_names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
		);

		if ( ! is_array( $option_names ) ) {
			return new WP_Error(
				'legacy_store_unavailable',
				__( 'Could not list legacy secrets.', 'secrets-api' )
			);
		}

		$keys = array();

		foreach ( $option_names as $option_name ) {
			$keys[] = substr( $option_name, strlen( self::SECRET_OPTION_PREFIX ) );
		}

		return $keys;
	}

	/**
	 * Reads and decrypts a legacy secret.
	 *
	 * @param string $key The legacy secret's bare key name (e.g. 'api_key' for the
	 *                     option '_secret_api_key') -- legacy names are not
	 *                     namespaced the way new-format names are.
	 *
	 * @return string|WP_Error Plaintext on success. WP_Error on failure.
	 */
	public function get( $key ) {
		$wrapped_master = get_option( self::MASTER_KEY_OPTION );

		if ( ! is_string( $wrapped_master ) || '' === $wrapped_master ) {
			return new WP_Error(
				'legacy_master_key_missing',
				__( 'The legacy master key option does not exist.', 'secrets-api' )
			);
		}

		$stored_secret = get_option( self::SECRET_OPTION_PREFIX . $key );

		if ( ! is_string( $stored_secret ) || '' === $stored_secret ) {
			return new WP_Error(
				'legacy_secret_missing',
				sprintf(
					/* translators: %s: Legacy secret key name. */
					__( 'No legacy secret found for key "%s".', 'secrets-api' ),
					$key
				)
			);
		}

		$site_key = $this->derive_site_key();

		if ( is_wp_error( $site_key ) ) {
			return $site_key;
		}

		$master_key = $this->open( $wrapped_master, $site_key );

		wp_secrets_memzero( $site_key );

		if ( is_wp_error( $master_key ) ) {
			return $master_key;
		}

		$plaintext = $this->open( $stored_secret, $master_key );

		wp_secrets_memzero( $master_key );

		return $plaintext;
	}

	/**
	 * Decrypts a single legacy record.
	 *
	 * @param string $encoded base64( nonce . ciphertext ).
	 * @param string $key     32-byte secretbox key.
	 *
	 * @return string|WP_Error
	 */
	private function open( $encoded, $key ) {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a legacy encrypted record, not obfuscating code.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return new WP_Error(
				'legacy_record_malformed',
				__( 'The legacy record is not valid base64, or is too short to contain a nonce.', 'secrets-api' )
			);
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		if ( false === $plaintext ) {
			return new WP_Error(
				'legacy_decryption_failed',
				__( 'The legacy record could not be decrypted with the derived key.', 'secrets-api' )
			);
		}

		return $plaintext;
	}

	/**
	 * Derives the legacy site key. Always hashes the literal material -- see this
	 * class's docblock for why that differs from the new format's key provider.
	 *
	 * Deliberately does not reject the wp-config-sample.php placeholder the way
	 * WP_Secrets_Config_Key_Provider does: that check is a hardening this project
	 * added to the new format, not something the legacy system ever did. A site
	 * that legitimately encrypted a value under a placeholder-derived key on the
	 * old system must still be able to read it back here -- refusing it would
	 * break exactly the migration this class exists to support, not improve
	 * security for a record that already exists.
	 *
	 * @return string|WP_Error
	 */
	private function derive_site_key() {
		if ( defined( 'WP_SECRETS_KEY' ) ) {
			$material = constant( 'WP_SECRETS_KEY' );

			if ( ! is_string( $material ) || '' === $material ) {
				return new WP_Error(
					'legacy_key_unavailable',
					__( 'WP_SECRETS_KEY is defined but is not a usable string.', 'secrets-api' )
				);
			}

			return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}

		if ( ! defined( 'LOGGED_IN_KEY' ) || ! defined( 'LOGGED_IN_SALT' )
			|| ! is_string( LOGGED_IN_KEY ) || ! is_string( LOGGED_IN_SALT )
			|| '' === LOGGED_IN_KEY || '' === LOGGED_IN_SALT
		) {
			return new WP_Error(
				'legacy_key_unavailable',
				__( 'WP_SECRETS_KEY is not defined, and LOGGED_IN_KEY/LOGGED_IN_SALT are not usable.', 'secrets-api' )
			);
		}

		return sodium_crypto_generichash( LOGGED_IN_KEY . LOGGED_IN_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
