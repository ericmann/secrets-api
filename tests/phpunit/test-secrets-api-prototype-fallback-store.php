<?php
/**
 * Tests for Secrets_API_Prototype_Fallback_Store -- the read-time upgrade path
 * that keeps prototype-era sites working when the plugins reading them adopt the
 * Secrets API.
 *
 * Reads here use the unnamespaced form ('api_key') deliberately: that is the only
 * form the prototype fallback applies to, because it is the only form the
 * prototype itself had. wp_secrets_validate_name() accepts those names and
 * reports each through _doing_it_wrong(), so tests that go through a public
 * wp_*_secret() function declare setExpectedIncorrectUsage(). Tests that call the
 * store directly do not, since the store sits below the layer that validates names.
 *
 * @group secrets
 */
class Tests_Secrets_ApiPrototypeFallbackStore extends WP_UnitTestCase {

	private function store() {
		return new Secrets_API_Prototype_Fallback_Store( new WP_Secrets_Option_Store() );
	}

	/**
	 * Declares the notice every unnamespaced name produces.
	 */
	private function expect_unnamespaced_notice() {
		$this->setExpectedIncorrectUsage( 'wp_secrets_validate_name' );
	}

	// -- the point of the whole class ---------------------------------------

	public function test_a_prototype_secret_is_readable_through_the_new_api_with_no_migration() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value' );

		$secret = wp_get_secret( 'api_key' );

		$this->assertInstanceOf( 'WP_Secret', $secret );
		$this->assertSame( 'sk_prototype_value', $secret->reveal() );
	}

	public function test_reading_upgrades_it_to_a_current_format_record() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value' );

		$this->assertFalse( get_option( '_wp_secret_api_key' ) );

		wp_get_secret( 'api_key' );

		$record = get_option( '_wp_secret_api_key' );
		$this->assertIsArray( $record );
		$this->assertSame( WP_SECRETS_RECORD_VERSION, $record['v'] );
	}

	public function test_the_upgraded_secret_is_flagged_needs_rotation() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		wp_get_secret( 'api_key' );

		$record = get_option( '_wp_secret_api_key' );
		$this->assertTrue( $record['current']['needs_rotation'] );
	}

	/**
	 * The upgrade is a one-time event: once a current-format record exists, the
	 * prototype row is never consulted again, so later divergence between the two
	 * cannot resurrect a stale value.
	 */
	public function test_the_upgrade_happens_once_and_then_the_new_record_wins() {
		$this->expect_unnamespaced_notice();

		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'original' );

		wp_get_secret( 'api_key' );

		// The prototype row changes afterwards; the new record must not follow it.
		$writer->write_secret( 'api_key', 'changed-later' );

		$this->assertSame( 'original', wp_get_secret( 'api_key' )->reveal() );
	}

	public function test_a_value_set_through_the_new_api_is_never_shadowed_by_a_prototype_row() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'prototype-value' );
		wp_set_secret( 'api_key', 'authoritative-value' );

		$this->assertSame( 'authoritative-value', wp_get_secret( 'api_key' )->reveal() );
	}

	// -- only the unnamespaced form maps, and it maps exactly -----------------

	/**
	 * The central rule after the mapping was tightened: a namespaced name
	 * describes something the prototype never had, so it inherits nothing. An
	 * earlier revision dropped the namespace instead, which let any namespace
	 * silently claim any prototype row.
	 */
	public function test_a_namespaced_name_never_inherits_a_prototype_row() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'prototype-value' );

		$this->assertNull( $this->store()->get( 'myplugin/api_key' ) );
		$this->assertNull( $this->store()->get( 'ai/api_key' ) );
	}

	/**
	 * And the mapping is identity, not a search: an unnamespaced name reaches its
	 * own prototype key and no other.
	 */
	public function test_an_unnamespaced_name_maps_only_to_the_identical_prototype_key() {
		$this->setExpectedIncorrectUsage( 'wp_secrets_validate_name' );

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'prototype-value' );

		$this->assertIsArray( $this->store()->get( 'api_key' ) );
		$this->assertNull( $this->store()->get( 'other_key' ) );
	}

	// -- fingerprint round-trip, both key-derivation paths --------------------

	/**
	 * Computes what the fingerprint of a plaintext must be under this site's
	 * current master key, independently of the upgrade path.
	 *
	 * @param string $plaintext Value to fingerprint.
	 *
	 * @return string
	 */
	private function expected_fingerprint( $plaintext ) {
		return ( new WP_Secrets_Cipher() )->fingerprint(
			_wp_secrets_get_key_manager()->get_master_key( 'site' ),
			$plaintext
		);
	}

	/**
	 * The same definition-of-done property the migrator is held to, applied to
	 * the path that actually runs on an adopting site. This one matters more:
	 * the migrator is an operator running a command and reading a report, while
	 * this happens silently on a front-end request, so a fingerprint that did not
	 * survive the upgrade would surface only later, as a value whose
	 * wp_list_secrets() entry disagrees with itself.
	 */
	public function test_salt_fallback_record_upgrades_with_a_matching_fingerprint() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value' );

		$secret = wp_get_secret( 'api_key' );

		$this->assertInstanceOf( 'WP_Secret', $secret );
		$this->assertSame( $this->expected_fingerprint( 'sk_prototype_value' ), $secret->fingerprint() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_secrets_key_record_upgrades_with_a_matching_fingerprint() {
		$this->expect_unnamespaced_notice();

		define( 'WP_SECRETS_KEY', 'some-legacy-shaped-constant' );

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value', WP_SECRETS_KEY );

		$secret = wp_get_secret( 'api_key' );

		$this->assertInstanceOf( 'WP_Secret', $secret );
		$this->assertSame( $this->expected_fingerprint( 'sk_prototype_value' ), $secret->fingerprint() );
	}

	// -- non-interference: the prototype's own data is never touched -----------

	public function test_reading_never_modifies_or_removes_the_prototype_row() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$secret_before = get_option( '_secret_api_key' );
		$master_before = get_option( '_secrets_master_key' );

		wp_get_secret( 'api_key' );

		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
		$this->assertSame( $master_before, get_option( '_secrets_master_key' ) );
	}

	public function test_writing_and_deleting_never_touch_the_prototype_row() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$secret_before = get_option( '_secret_api_key' );

		wp_set_secret( 'api_key', 'new-value' );
		wp_delete_secret( 'api_key' );

		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
	}

	// -- absence and failure still behave -------------------------------------

	public function test_absent_everywhere_is_still_null() {
		$this->expect_unnamespaced_notice();

		$this->assertNull( wp_get_secret( 'never-existed' ) );
	}

	public function test_an_undecryptable_prototype_row_reports_absence_not_a_false_positive() {
		$this->expect_unnamespaced_notice();

		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'value' );
		$writer->corrupt_secret( 'api_key' );

		$this->assertNull( wp_get_secret( 'api_key' ) );
		$this->assertFalse( get_option( '_wp_secret_api_key' ) );
	}

	/**
	 * A store reporting a genuine error must not have that error quietly replaced
	 * by a prototype value -- that would turn a fail-closed read into a fail-open
	 * one, which is the opposite of this API's whole posture.
	 */
	public function test_a_store_error_is_never_answered_from_the_prototype() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$failing = new Mock_Store();
		$failing->configure_fail( 'get' );

		$result = ( new Secrets_API_Prototype_Fallback_Store( $failing ) )->get( 'api_key' );

		$this->assertWPError( $result );
	}

	// -- delegation ------------------------------------------------------------

	public function test_network_reads_are_never_served_from_the_prototype() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->assertNull( $this->store()->get( 'api_key', true ) );
	}

	public function test_list_names_reports_only_current_format_secrets() {
		$this->expect_unnamespaced_notice();

		( new Legacy_Fixture_Writer() )->write_secret( 'unread_key', 'value' );
		wp_set_secret( 'known', 'value' );

		$names = $this->store()->list_names();

		$this->assertContains( 'known', $names );
		$this->assertNotContains( 'unread_key', $names );
	}

	public function test_supports_delegates_to_the_inner_store() {
		$this->assertTrue( $this->store()->supports( 'write' ) );
	}
}
