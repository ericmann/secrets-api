<?php
/**
 * Tests for Secrets_API_Legacy_Reader, against fixtures written independently by
 * Legacy_Fixture_Writer.
 *
 * @group secrets
 */
class Tests_Secrets_ApiLegacyReader extends WP_UnitTestCase {

	public function test_reads_a_salt_fallback_fixture() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertSame( 'sk_legacy_value', $result );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reads_a_wp_secrets_key_fixture() {
		define( 'WP_SECRETS_KEY', 'some-legacy-shaped-constant-value' );

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value', WP_SECRETS_KEY );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertSame( 'sk_legacy_value', $result );
	}

	/**
	 * The central compatibility fact: the prototype always hashes
	 * WP_SECRETS_KEY's literal string, even when that string happens to also be
	 * valid base64-32 -- unlike the new format, which would use it raw. Proven
	 * directly here: the reader succeeds using the hashed interpretation, and
	 * fails using the new format's raw-bytes interpretation of the identical
	 * constant, against the identical fixture.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_legacy_always_hashes_even_a_base64_32_shaped_key() {
		$base64_32_shaped = base64_encode( str_repeat( 'A', 32 ) );
		define( 'WP_SECRETS_KEY', $base64_32_shaped );

		// Written the way a real legacy site would: hashing the literal string.
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value', $base64_32_shaped );

		$this->assertSame( 'sk_legacy_value', ( new Secrets_API_Legacy_Reader() )->get( 'api_key' ) );

		// The new format's interpretation of the same constant (raw decoded
		// bytes, not hashed) must fail to unwrap the same stored master key.
		$raw_bytes_key      = base64_decode( $base64_32_shaped, true );
		$wrapped_master_key = get_option( '_secrets_master_key' );
		$raw                = base64_decode( $wrapped_master_key, true );
		$nonce              = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext         = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$this->assertFalse( sodium_crypto_secretbox_open( $ciphertext, $nonce, $raw_bytes_key ) );
	}

	public function test_never_writes_or_deletes_anything() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value' );

		$master_before = get_option( '_secrets_master_key' );
		$secret_before = get_option( '_secret_api_key' );

		( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertSame( $master_before, get_option( '_secrets_master_key' ) );
		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
	}

	public function test_missing_master_key_option_is_wp_error() {
		update_option( '_secret_api_key', 'irrelevant' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_master_key_missing', $result->get_error_code() );
	}

	public function test_missing_secret_option_is_wp_error() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );
		delete_option( '_secret_api_key' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_secret_missing', $result->get_error_code() );
	}

	public function test_malformed_base64_is_wp_error() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );
		update_option( '_secret_api_key', 'not valid base64 at all!!!' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_record_malformed', $result->get_error_code() );
	}

	public function test_corrupted_ciphertext_is_wp_error() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );
		( new Legacy_Fixture_Writer() )->corrupt_secret( 'api_key' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_decryption_failed', $result->get_error_code() );
	}

	public function test_two_secrets_under_the_same_master_key_both_read_correctly() {
		$writer     = new Legacy_Fixture_Writer();
		$master_key = $writer->write_secret( 'api_key', 'value-one' );
		$writer->write_secret_under_master_key( 'other_key', 'value-two', $master_key );

		$reader = new Secrets_API_Legacy_Reader();

		$this->assertSame( 'value-one', $reader->get( 'api_key' ) );
		$this->assertSame( 'value-two', $reader->get( 'other_key' ) );
	}

	// -- site key candidates ------------------------------------------------

	/**
	 * The operator sequence that would otherwise strand a site: legacy records
	 * were sealed under the salt fallback, then WP_SECRETS_KEY was defined (as
	 * the new format's own documentation instructs) before migrate-legacy ran.
	 * Deriving only from the now-defined constant fails every key with a generic
	 * decryption error, even though the value is perfectly recoverable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_salt_fallback_records_still_read_after_wp_secrets_key_is_defined() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value' );

		define( 'WP_SECRETS_KEY', base64_encode( str_repeat( 'B', 32 ) ) );

		$this->assertSame( 'sk_legacy_value', ( new Secrets_API_Legacy_Reader() )->get( 'api_key' ) );
	}

	/**
	 * The mirror case, to prove the candidate list is not just always falling
	 * through to the salt fallback: records sealed under the constant still read
	 * when the constant is what is defined.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_secrets_key_records_still_read_when_the_constant_is_defined() {
		define( 'WP_SECRETS_KEY', 'the-legacy-constant' );

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value', WP_SECRETS_KEY );

		$this->assertSame( 'sk_legacy_value', ( new Secrets_API_Legacy_Reader() )->get( 'api_key' ) );
	}

	/**
	 * The prototype lets an operator rotate WP_SECRETS_KEY and keep the old value in
	 * WP_SECRETS_KEY_PREVIOUS so records sealed under it still read. A site part-way
	 * through that rotation must not lose those records by adopting this plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_records_sealed_under_wp_secrets_key_previous_still_read() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value', 'the-old-key' );

		define( 'WP_SECRETS_KEY', 'the-new-key' );
		define( 'WP_SECRETS_KEY_PREVIOUS', 'the-old-key' );

		$this->assertSame( 'sk_legacy_value', ( new Secrets_API_Legacy_Reader() )->get( 'api_key' ) );
	}

	/**
	 * Trying several candidates must not turn into "eventually accepts anything":
	 * when none of them opens the master key, that is still a hard failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_all_candidates_failing_is_a_wp_error() {
		// Sealed under a site key derived from material matching neither the
		// salt fallback nor whatever WP_SECRETS_KEY is set to below.
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value', 'material-from-a-different-site' );

		define( 'WP_SECRETS_KEY', 'not-the-material-it-was-sealed-under' );

		$result = ( new Secrets_API_Legacy_Reader() )->get( 'api_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_master_key_unwrap_failed', $result->get_error_code() );
	}

	// -- list_keys ----------------------------------------------------------

	public function test_list_keys_returns_bare_key_names() {
		$writer     = new Legacy_Fixture_Writer();
		$master_key = $writer->write_secret( 'api_key', 'value-one' );
		$writer->write_secret_under_master_key( 'other_key', 'value-two', $master_key );

		$keys = ( new Secrets_API_Legacy_Reader() )->list_keys();

		sort( $keys );
		$this->assertSame( array( 'api_key', 'other_key' ), $keys );
	}

	/**
	 * '_' is a single-character wildcard in SQL LIKE, so an unescaped '_secret_%'
	 * pattern also matches '_secrets_master_key'. The migrator would then try to
	 * migrate the master key itself as though it were a secret. The escaping in
	 * list_keys() is what prevents that, and it is invisible enough to be worth
	 * pinning.
	 */
	public function test_list_keys_does_not_return_the_master_key_option() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$keys = ( new Secrets_API_Legacy_Reader() )->list_keys();

		$this->assertSame( array( 'api_key' ), $keys );
		$this->assertNotContains( 's_master_key', $keys );
	}
}
