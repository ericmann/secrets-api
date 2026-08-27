<?php
/**
 * Tests for Secrets_API_Migrator. This is the highest-scrutiny file in the build --
 * per the build brief, this is where silent data loss lives.
 *
 * @group secrets
 */
class Tests_Secrets_ApiMigrator extends WP_UnitTestCase {

	private function migrator() {
		return new Secrets_API_Migrator();
	}

	private function entry_for( $report, $legacy_key ) {
		foreach ( $report['entries'] as $entry ) {
			if ( $entry['legacy_key'] === $legacy_key ) {
				return $entry;
			}
		}

		return null;
	}

	// -- basic migration, both key-derivation paths --------------------------

	public function test_migrates_a_salt_fallback_secret_with_no_re_entry() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value' );

		$report = $this->migrator()->migrate();
		$entry  = $this->entry_for( $report, 'api_key' );

		$this->assertSame( 'migrated', $entry['status'] );
		$this->assertSame( 'sk_legacy_value', wp_get_secret( 'legacy/api_key' )->reveal() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_migrates_a_wp_secrets_key_shaped_secret() {
		define( 'WP_SECRETS_KEY', 'some-legacy-shaped-constant' );

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_legacy_value', WP_SECRETS_KEY );

		$report = $this->migrator()->migrate();
		$entry  = $this->entry_for( $report, 'api_key' );

		$this->assertSame( 'migrated', $entry['status'] );
		$this->assertSame( 'sk_legacy_value', wp_get_secret( 'legacy/api_key' )->reveal() );
	}

	public function test_migrated_secret_is_flagged_needs_rotation() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->migrator()->migrate();

		$record = get_option( '_wp_secret_legacy/api_key' );
		$this->assertTrue( $record['current']['needs_rotation'] );
	}

	public function test_default_namespace_is_legacy() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate();

		$this->assertSame( 'legacy/api_key', $this->entry_for( $report, 'api_key' )['new_name'] );
	}

	public function test_custom_namespace_is_used_when_given() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'namespace' => 'myplugin' ) );

		$this->assertSame( 'myplugin/api_key', $this->entry_for( $report, 'api_key' )['new_name'] );
		$this->assertSame( 'value', wp_get_secret( 'myplugin/api_key' )->reveal() );
	}

	public function test_migrating_a_single_named_key_ignores_others() {
		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'value-one' );
		$writer->write_secret( 'other_key', 'value-two' );

		$report = $this->migrator()->migrate( array( 'name' => 'api_key' ) );

		$this->assertCount( 1, $report['entries'] );

		// Checked as a stored record rather than through wp_get_secret(): the
		// read-time fallback store would upgrade it on the spot and report a hit.
		$this->assertFalse( get_option( '_wp_secret_legacy/other_key' ) );
	}

	// -- the prototype's own rows are never touched ---------------------------

	public function test_migrating_never_modifies_or_removes_the_prototype_rows() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$secret_before = get_option( '_secret_api_key' );
		$master_before = get_option( '_secrets_master_key' );

		$this->migrator()->migrate();

		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
		$this->assertSame( $master_before, get_option( '_secrets_master_key' ) );
	}

	// -- dry run -------------------------------------------------------------

	public function test_dry_run_writes_nothing_at_all() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'dry_run' => true ) );

		$this->assertSame( 'would_migrate', $this->entry_for( $report, 'api_key' )['status'] );

		/*
		 * Deliberately not wp_get_secret(): that would upgrade the record through
		 * the fallback store and the assertion would pass for the wrong reason
		 * while silently proving the opposite of what it claims. An earlier
		 * revision of the migrator did exactly this, and its dry run wrote.
		 */
		$this->assertFalse( get_option( '_wp_secret_legacy/api_key' ) );
	}

	// -- idempotency -----------------------------------------------------

	public function test_rerunning_is_safe_and_reports_skipped() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$first  = $this->migrator()->migrate();
		$second = $this->migrator()->migrate();

		$this->assertSame( 'migrated', $this->entry_for( $first, 'api_key' )['status'] );
		$this->assertSame( 'skipped', $this->entry_for( $second, 'api_key' )['status'] );
		$this->assertSame( 'value', wp_get_secret( 'legacy/api_key' )->reveal() );
	}


	// -- unnamespaced / invalid names ----------------------------------------

	public function test_a_key_that_cannot_be_namespaced_is_reported_not_guessed() {
		// Uppercase is invalid in the new naming scheme, so 'legacy/API-Key' will
		// never validate no matter the namespace.
		( new Legacy_Fixture_Writer() )->write_secret( 'API_Key', 'value' );

		$report = $this->migrator()->migrate();
		$entry  = $this->entry_for( $report, 'API_Key' );

		$this->assertSame( 'needs_mapping', $entry['status'] );
		$this->assertStringContainsString( '--map', $entry['message'] );
		$this->assertNotInstanceOf( 'WP_Secret', wp_get_secret( 'legacy/API_Key' ) );
	}

	public function test_map_resolves_a_key_that_would_otherwise_need_mapping() {
		( new Legacy_Fixture_Writer() )->write_secret( 'API_Key', 'value' );

		$report = $this->migrator()->migrate( array( 'map' => array( 'API_Key' => 'myplugin/api-key' ) ) );
		$entry  = $this->entry_for( $report, 'API_Key' );

		$this->assertSame( 'migrated', $entry['status'] );
		$this->assertSame( 'myplugin/api-key', $entry['new_name'] );
		$this->assertSame( 'value', wp_get_secret( 'myplugin/api-key' )->reveal() );
	}

	// -- one bad key does not block the others -------------------------------

	public function test_one_undecryptable_key_does_not_block_migrating_the_others() {
		$writer     = new Legacy_Fixture_Writer();
		$master_key = $writer->write_secret( 'good_key', 'value' );
		// Shares good_key's master key, the way a real legacy site would -- calling
		// write_secret() a second time would regenerate and overwrite
		// _secrets_master_key, orphaning good_key's own ciphertext.
		$writer->write_secret_under_master_key( 'bad_key', 'value', $master_key );
		$writer->corrupt_secret( 'bad_key' );

		$report = $this->migrator()->migrate();

		$this->assertSame( 'migrated', $this->entry_for( $report, 'good_key' )['status'] );
		$this->assertSame( 'error', $this->entry_for( $report, 'bad_key' )['status'] );
		$this->assertSame( 'value', wp_get_secret( 'legacy/good_key' )->reveal() );
	}

	// -- vendored-copy detection ----------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_vendored_copy_is_detected_and_reported() {
		eval( 'namespace WordPress\AI\Vendor\Secrets; class Secrets_Manager {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- only way to define a class under a namespace conditionally, for this one test.

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate();

		$this->assertTrue( $report['vendor_detected'] );
	}

	// -- never leaks a plaintext ----------------------------------------------

	public function test_the_report_never_contains_a_plaintext() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'UNIQUE-PLAINTEXT-CANARY-9f3a' );

		$report = $this->migrator()->migrate();

		$this->assertStringNotContainsString( 'UNIQUE-PLAINTEXT-CANARY-9f3a', wp_json_encode( $report ) );
	}
}
