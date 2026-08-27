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
	 * The central compatibility fact from §9.3: legacy always hashes
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
}
