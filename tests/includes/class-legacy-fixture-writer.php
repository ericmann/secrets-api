<?php
/**
 * Writes genuine displace-format ("legacy") records for testing
 * Secrets_API_Legacy_Reader against.
 *
 * Deliberately independent of Secrets_API_Legacy_Reader's own code: if this reused
 * the reader's logic to write fixtures, a bug in that logic could cancel itself out
 * and the tests would never notice. This reimplements the same algorithm from the
 * documented prototype format, from scratch.
 */
class Legacy_Fixture_Writer {

	/**
	 * Writes a legacy-format secret, generating its own master key.
	 *
	 * @param string      $key               Legacy secret key (no namespace, no
	 *                                       leading '_secret_').
	 * @param string      $plaintext         Value to store.
	 * @param string|null $site_key_material Raw material to hash into the site key.
	 *                                       Null uses the salt-fallback form
	 *                                       (LOGGED_IN_KEY . LOGGED_IN_SALT), the
	 *                                       same as a site with no WP_SECRETS_KEY
	 *                                       defined.
	 *
	 * @return string The generated legacy master key's raw bytes, in case a test
	 *                needs to write a second secret under the same master key.
	 */
	public function write_secret( $key, $plaintext, $site_key_material = null ) {
		$site_key   = $this->derive_site_key( $site_key_material );
		$master_key = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		update_option( '_secrets_master_key', $this->seal( $master_key, $site_key ) );
		update_option( '_secret_' . $key, $this->seal( $plaintext, $master_key ) );

		return $master_key;
	}

	/**
	 * Writes a legacy-format secret using a specific, already-wrapped master key --
	 * for building fixtures that share one master key across multiple secrets, the
	 * way a real legacy site would.
	 *
	 * @param string $key        Legacy secret key.
	 * @param string $plaintext  Value to store.
	 * @param string $master_key Raw 32-byte master key, as returned by
	 *                           write_secret().
	 */
	public function write_secret_under_master_key( $key, $plaintext, $master_key ) {
		update_option( '_secret_' . $key, $this->seal( $plaintext, $master_key ) );
	}

	/**
	 * Corrupts a stored legacy secret's ciphertext, for testing failure paths.
	 *
	 * @param string $key Legacy secret key.
	 */
	public function corrupt_secret( $key ) {
		update_option( '_secret_' . $key, base64_encode( 'not decryptable by any key' ) );
	}

	/**
	 * @param string $plaintext Value to encrypt.
	 * @param string $key       32-byte secretbox key.
	 *
	 * @return string base64( nonce . ciphertext ).
	 */
	private function seal( $plaintext, $key ) {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * @param string|null $material Raw material, or null for the salt fallback.
	 *
	 * @return string 32-byte site key.
	 */
	private function derive_site_key( $material ) {
		if ( null === $material ) {
			$material = LOGGED_IN_KEY . LOGGED_IN_SALT;
		}

		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
