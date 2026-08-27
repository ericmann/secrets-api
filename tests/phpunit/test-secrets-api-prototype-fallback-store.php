<?php
/**
 * Tests for Secrets_API_Prototype_Fallback_Store -- the read-time upgrade path
 * that keeps prototype-era sites working when the plugins reading them adopt the
 * Secrets API.
 *
 * @group secrets
 */
class Tests_Secrets_ApiPrototypeFallbackStore extends WP_UnitTestCase {

	private function store() {
		return new Secrets_API_Prototype_Fallback_Store( new WP_Secrets_Option_Store() );
	}

	// -- the point of the whole class ---------------------------------------

	public function test_a_prototype_secret_is_readable_through_the_new_api_with_no_migration() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value' );

		$secret = wp_get_secret( 'ai/api_key' );

		$this->assertInstanceOf( 'WP_Secret', $secret );
		$this->assertSame( 'sk_prototype_value', $secret->reveal() );
	}

	public function test_reading_upgrades_it_to_a_current_format_record() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'sk_prototype_value' );

		$this->assertFalse( get_option( '_wp_secret_ai/api_key' ) );

		wp_get_secret( 'ai/api_key' );

		$record = get_option( '_wp_secret_ai/api_key' );
		$this->assertIsArray( $record );
		$this->assertSame( WP_SECRETS_RECORD_VERSION, $record['v'] );
	}

	public function test_the_upgraded_secret_is_flagged_needs_rotation() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		wp_get_secret( 'ai/api_key' );

		$record = get_option( '_wp_secret_ai/api_key' );
		$this->assertTrue( $record['current']['needs_rotation'] );
	}

	/**
	 * The upgrade is a one-time event: once a current-format record exists, the
	 * prototype row is never consulted again, so later divergence between the two
	 * cannot resurrect a stale value.
	 */
	public function test_the_upgrade_happens_once_and_then_the_new_record_wins() {
		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'original' );

		wp_get_secret( 'ai/api_key' );

		// The prototype row changes afterwards; the new record must not follow it.
		$writer->write_secret( 'api_key', 'changed-later' );

		$this->assertSame( 'original', wp_get_secret( 'ai/api_key' )->reveal() );
	}

	public function test_a_value_set_through_the_new_api_is_never_shadowed_by_a_prototype_row() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'prototype-value' );
		wp_set_secret( 'ai/api_key', 'authoritative-value' );

		$this->assertSame( 'authoritative-value', wp_get_secret( 'ai/api_key' )->reveal() );
	}

	// -- non-interference: the prototype's own data is never touched -----------

	public function test_reading_never_modifies_or_removes_the_prototype_row() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$secret_before = get_option( '_secret_api_key' );
		$master_before = get_option( '_secrets_master_key' );

		wp_get_secret( 'ai/api_key' );

		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
		$this->assertSame( $master_before, get_option( '_secrets_master_key' ) );
	}

	public function test_writing_and_deleting_never_touch_the_prototype_row() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$secret_before = get_option( '_secret_api_key' );

		wp_set_secret( 'ai/api_key', 'new-value' );
		wp_delete_secret( 'ai/api_key' );

		$this->assertSame( $secret_before, get_option( '_secret_api_key' ) );
	}

	// -- absence and failure still behave -------------------------------------

	public function test_absent_everywhere_is_still_null() {
		$this->assertNull( wp_get_secret( 'ai/never-existed' ) );
	}

	public function test_an_undecryptable_prototype_row_reports_absence_not_a_false_positive() {
		$writer = new Legacy_Fixture_Writer();
		$writer->write_secret( 'api_key', 'value' );
		$writer->corrupt_secret( 'api_key' );

		$this->assertNull( wp_get_secret( 'ai/api_key' ) );
		$this->assertFalse( get_option( '_wp_secret_ai/api_key' ) );
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

		$result = ( new Secrets_API_Prototype_Fallback_Store( $failing ) )->get( 'ai/api_key' );

		$this->assertWPError( $result );
	}

	// -- delegation ------------------------------------------------------------

	public function test_network_reads_are_never_served_from_the_prototype() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->assertNull( $this->store()->get( 'ai/api_key', true ) );
	}

	public function test_list_names_reports_only_current_format_secrets() {
		( new Legacy_Fixture_Writer() )->write_secret( 'unread_key', 'value' );
		wp_set_secret( 'ai/known', 'value' );

		$names = $this->store()->list_names();

		$this->assertContains( 'ai/known', $names );
		$this->assertNotContains( 'ai/unread_key', $names );
	}

	public function test_supports_delegates_to_the_inner_store() {
		$this->assertTrue( $this->store()->supports( 'write' ) );
	}

	public function test_a_malformed_name_is_not_mapped_to_a_prototype_key() {
		( new Legacy_Fixture_Writer() )->write_secret( 'api_key', 'value' );

		$this->assertNull( $this->store()->get( 'no-namespace-here' ) );
	}
}
