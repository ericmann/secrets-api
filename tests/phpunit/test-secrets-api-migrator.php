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
		$this->assertNull( wp_get_secret( 'legacy/other_key' ) );
	}

	// -- default leaves the source in place, --delete-source removes it --------

	public function test_default_run_leaves_the_legacy_option_in_place() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->migrator()->migrate();

		$this->assertNotFalse( get_option( '_secret_api_key' ) );
	}

	public function test_delete_source_removes_the_legacy_option_after_verification() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'delete_source' => true ) );

		$this->assertTrue( $this->entry_for( $report, 'api_key' )['source_deleted'] );
		$this->assertFalse( get_option( '_secret_api_key' ) );
	}

	/**
	 * The central safety property of this whole class: if verification would fail,
	 * the source is never deleted, no matter what flags were passed.
	 */
	public function test_verification_failure_never_deletes_the_source() {
		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'value' );

		// Migrate once, without deleting, so a fingerprint of 'value' is recorded.
		$this->migrator()->migrate();

		// Simulate the legacy value having changed since migration (e.g. something
		// else wrote a new value under the same legacy option). A delete-time
		// re-verification against the fingerprint recorded at migration time must
		// now fail, since the current legacy plaintext no longer matches it.
		$writer->write_secret( 'api_key', 'a different value' );

		$report = $this->migrator()->migrate( array( 'delete_source' => true ) );
		$entry  = $this->entry_for( $report, 'api_key' );

		$this->assertFalse( $entry['source_deleted'] );
		$this->assertNotFalse( get_option( '_secret_api_key' ) );
	}

	// -- dry run -------------------------------------------------------------

	public function test_dry_run_writes_nothing_at_all() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'dry_run' => true ) );

		$this->assertSame( 'would_migrate', $this->entry_for( $report, 'api_key' )['status'] );
		$this->assertNull( wp_get_secret( 'legacy/api_key' ) );
	}

	public function test_dry_run_does_not_delete_even_with_delete_source() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->migrator()->migrate(
			array(
				'dry_run'       => true,
				'delete_source' => true,
			)
		);

		$this->assertNotFalse( get_option( '_secret_api_key' ) );
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

	public function test_a_skipped_key_can_still_have_its_source_deleted_on_a_later_run() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->migrator()->migrate(); // Migrate without deleting.
		$report = $this->migrator()->migrate( array( 'delete_source' => true ) ); // Clean up later.

		$entry = $this->entry_for( $report, 'api_key' );
		$this->assertSame( 'skipped', $entry['status'] );
		$this->assertTrue( $entry['source_deleted'] );
		$this->assertFalse( get_option( '_secret_api_key' ) );
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_vendored_copy_refuses_delete_source_without_yes() {
		eval( 'namespace WordPress\AI\Vendor\Secrets; class Secrets_Manager {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'delete_source' => true ) );
		$entry  = $this->entry_for( $report, 'api_key' );

		$this->assertSame( 'migrated', $entry['status'] );
		$this->assertFalse( $entry['source_deleted'] );
		$this->assertStringContainsString( 'vendored', $entry['message'] );
		$this->assertNotFalse( get_option( '_secret_api_key' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_vendored_copy_allows_delete_source_with_confirmation() {
		eval( 'namespace WordPress\AI\Vendor\Secrets; class Secrets_Manager {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate(
			array(
				'delete_source'                 => true,
				'confirm_delete_despite_vendor' => true,
			)
		);

		$this->assertTrue( $this->entry_for( $report, 'api_key' )['source_deleted'] );
		$this->assertFalse( get_option( '_secret_api_key' ) );
	}

	public function test_no_vendored_copy_migrates_and_deletes_normally() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$report = $this->migrator()->migrate( array( 'delete_source' => true ) );

		$this->assertFalse( $report['vendor_detected'] );
		$this->assertTrue( $this->entry_for( $report, 'api_key' )['source_deleted'] );
	}

	// -- never leaks a plaintext ----------------------------------------------

	public function test_the_report_never_contains_a_plaintext() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'UNIQUE-PLAINTEXT-CANARY-9f3a' );

		$report = $this->migrator()->migrate();

		$this->assertStringNotContainsString( 'UNIQUE-PLAINTEXT-CANARY-9f3a', wp_json_encode( $report ) );
	}
}
