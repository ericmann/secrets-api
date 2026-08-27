<?php
/**
 * Tests for Secrets_API_Compat_Shim and its global-function loader.
 *
 * Most coverage here calls Secrets_API_Compat_Shim's static methods directly
 * rather than the global get_secret()/set_secret()/delete_secret()/secret_exists()
 * functions: those globals are only ever declared once, guarded by
 * function_exists(), when WP_SECRETS_LEGACY_SHIM is defined ahead of the plugin
 * loading -- a real production ordering constraint, not something a normal test
 * method can flip mid-process. The class methods hold 100% of the actual logic
 * (namespacing, state-collapsing, deprecation), so testing them directly gives
 * full coverage of the real behavior. The loader itself (wp_secrets_api_maybe_load_
 * compat_shim()) and the function_exists() guards it wires up are covered
 * separately below, in isolated processes.
 *
 * @group secrets
 */
class Tests_Secrets_ApiCompatShim extends WP_UnitTestCase {

	// -- get_secret -----------------------------------------------------------

	public function test_get_secret_returns_the_plaintext_for_an_existing_secret() {
		$this->setExpectedDeprecated( 'get_secret' );

		wp_set_secret( 'legacy/api_key', 'value' );

		$this->assertSame( 'value', Secrets_API_Compat_Shim::get_secret( 'api_key' ) );
	}

	public function test_get_secret_returns_null_for_an_absent_secret() {
		$this->setExpectedDeprecated( 'get_secret' );

		$this->assertNull( Secrets_API_Compat_Shim::get_secret( 'never-set' ) );
	}

	public function test_get_secret_returns_null_for_a_broken_secret() {
		$this->setExpectedDeprecated( 'get_secret' );

		wp_set_secret( 'legacy/api_key', 'value' );
		$record                  = get_option( '_wp_secret_legacy/api_key' );
		$record['current']['ct'] = base64_encode( 'not decryptable' );
		update_option( '_wp_secret_legacy/api_key', $record, false );

		$this->assertNull( Secrets_API_Compat_Shim::get_secret( 'api_key' ) );
	}

	public function test_get_secret_is_deprecated() {
		$this->setExpectedDeprecated( 'get_secret' );

		Secrets_API_Compat_Shim::get_secret( 'anything' );
	}

	// -- set_secret -------------------------------------------------------------

	public function test_set_secret_stores_under_the_legacy_namespace() {
		$this->setExpectedDeprecated( 'set_secret' );

		$this->assertTrue( Secrets_API_Compat_Shim::set_secret( 'api_key', 'value' ) );
		$this->assertSame( 'value', wp_get_secret( 'legacy/api_key' )->reveal() );
	}

	public function test_set_secret_reports_failure() {
		$this->setExpectedDeprecated( 'set_secret' );

		// Legacy keys were free-form; anything that fails validation once
		// namespaced onto 'legacy/' -- an uppercase letter, say -- surfaces as a
		// WP_Error from wp_set_secret(), which the shim collapses to false.
		$this->assertFalse( Secrets_API_Compat_Shim::set_secret( 'Uppercase-Key', 'value' ) );
	}

	public function test_set_secret_is_deprecated() {
		$this->setExpectedDeprecated( 'set_secret' );

		Secrets_API_Compat_Shim::set_secret( 'anything', 'value' );
	}

	// -- delete_secret ------------------------------------------------------

	public function test_delete_secret_deletes_and_reports_success() {
		$this->setExpectedDeprecated( 'delete_secret' );

		wp_set_secret( 'legacy/api_key', 'value' );

		$this->assertTrue( Secrets_API_Compat_Shim::delete_secret( 'api_key' ) );
		$this->assertNull( wp_get_secret( 'legacy/api_key' ) );
	}

	public function test_delete_secret_is_deprecated() {
		$this->setExpectedDeprecated( 'delete_secret' );

		Secrets_API_Compat_Shim::delete_secret( 'anything' );
	}

	// -- secret_exists ------------------------------------------------------

	public function test_secret_exists_true_for_a_set_secret() {
		$this->setExpectedDeprecated( 'secret_exists' );

		wp_set_secret( 'legacy/api_key', 'value' );

		$this->assertTrue( Secrets_API_Compat_Shim::secret_exists( 'api_key' ) );
	}

	public function test_secret_exists_false_for_an_absent_secret() {
		$this->setExpectedDeprecated( 'secret_exists' );

		$this->assertFalse( Secrets_API_Compat_Shim::secret_exists( 'never-set' ) );
	}

	public function test_secret_exists_false_for_a_broken_secret() {
		$this->setExpectedDeprecated( 'secret_exists' );

		wp_set_secret( 'legacy/api_key', 'value' );
		$record                  = get_option( '_wp_secret_legacy/api_key' );
		$record['current']['ct'] = base64_encode( 'not decryptable' );
		update_option( '_wp_secret_legacy/api_key', $record, false );

		$this->assertFalse( Secrets_API_Compat_Shim::secret_exists( 'api_key' ) );
	}

	public function test_secret_exists_is_deprecated() {
		$this->setExpectedDeprecated( 'secret_exists' );

		Secrets_API_Compat_Shim::secret_exists( 'anything' );
	}

	// -- the documented destructive consequence of the state collapse ---------

	/**
	 * Pins the hazard described at length in Secrets_API_Compat_Shim's docblock,
	 * so it cannot change silently in either direction: the standard legacy
	 * create-if-missing idiom, run against a record that exists but is currently
	 * undecryptable, destroys the original ciphertext outright.
	 *
	 * This asserts current, deliberate behavior -- it is not an endorsement. It
	 * exists so that if wp_set_secret() ever starts preserving undecryptable
	 * slots, or secret_exists() ever starts distinguishing broken from absent,
	 * this test fails and the docblock gets revisited with it.
	 */
	public function test_create_if_missing_idiom_destroys_a_broken_records_ciphertext() {
		$this->setExpectedDeprecated( 'secret_exists' );
		$this->setExpectedDeprecated( 'set_secret' );

		wp_set_secret( 'legacy/api_key', 'original-value' );

		$record                  = get_option( '_wp_secret_legacy/api_key' );
		$original_ciphertext     = $record['current']['ct'];
		$record['current']['ct'] = base64_encode( 'not decryptable' );
		update_option( '_wp_secret_legacy/api_key', $record, false );

		// The idiom, verbatim.
		if ( ! Secrets_API_Compat_Shim::secret_exists( 'api_key' ) ) {
			Secrets_API_Compat_Shim::set_secret( 'api_key', 'regenerated-value' );
		}

		$after = get_option( '_wp_secret_legacy/api_key' );

		// The overwrite happened...
		$this->assertSame( 'regenerated-value', wp_get_secret( 'legacy/api_key' )->reveal() );

		// ...and the original ciphertext survives nowhere in the record.
		$this->assertArrayNotHasKey( 'previous', $after );
		$this->assertStringNotContainsString( $original_ciphertext, wp_json_encode( $after ) );
	}

	// -- the loader and its function_exists() guards -------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loader_does_nothing_when_the_constant_is_not_defined() {
		wp_secrets_api_maybe_load_compat_shim();

		$this->assertFalse( function_exists( 'get_secret' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loader_defines_all_four_functions_when_enabled() {
		define( 'WP_SECRETS_LEGACY_SHIM', true );

		wp_secrets_api_maybe_load_compat_shim();

		$this->assertTrue( function_exists( 'get_secret' ) );
		$this->assertTrue( function_exists( 'set_secret' ) );
		$this->assertTrue( function_exists( 'delete_secret' ) );
		$this->assertTrue( function_exists( 'secret_exists' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loader_wires_the_global_functions_through_to_the_new_api() {
		define( 'WP_SECRETS_LEGACY_SHIM', true );

		wp_secrets_api_maybe_load_compat_shim();

		$this->setExpectedDeprecated( 'set_secret' );
		$this->setExpectedDeprecated( 'get_secret' );

		set_secret( 'api_key', 'value' );

		$this->assertSame( 'value', get_secret( 'api_key' ) );
		$this->assertSame( 'value', wp_get_secret( 'legacy/api_key' )->reveal() );
	}

	/**
	 * A plugin (or the site itself) that already declared its own get_secret()
	 * must keep winning -- this shim must never clobber it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loader_does_not_override_an_already_declared_function() {
		function get_secret( $key ) { // phpcs:ignore Squiz.WhiteSpace.LanguageConstructSpacing.IncorrectSingleSpaceAfterConstruct -- test-only redeclare to prove the guard holds.
			return 'already declared elsewhere';
		}

		define( 'WP_SECRETS_LEGACY_SHIM', true );

		wp_secrets_api_maybe_load_compat_shim();

		$this->assertSame( 'already declared elsewhere', get_secret( 'anything' ) );
	}
}
