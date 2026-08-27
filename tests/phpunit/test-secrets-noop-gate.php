<?php
/**
 * Tests for the bootstrap's no-op decision -- what happens when something else
 * has already declared wp_get_secret().
 *
 * This is the mechanism that keeps the feature plugin from fighting core once the
 * API ships in 7.2, and it is the one part of the bootstrap that IS reachable from
 * a test, unlike drop-in file loading (see docs/open-questions.md #13). By the time
 * any test body runs, the plugin has bootstrapped and wp_get_secret() exists --
 * which is precisely the condition the gate is looking for, so calling
 * wp_secrets_api_bootstrap() again exercises the real branches with no simulation.
 *
 * @group secrets
 */
class Tests_Secrets_NoopGate extends WP_UnitTestCase {

	/**
	 * The real $wp_version, restored after each test.
	 *
	 * @var string
	 */
	private $original_wp_version;

	public function set_up() {
		parent::set_up();

		$this->original_wp_version = $GLOBALS['wp_version'];

		// Isolate: assert on what this bootstrap call registers, not on whatever
		// else the harness has already hooked to admin_notices.
		remove_all_actions( 'admin_notices' );
	}

	public function tear_down() {
		$GLOBALS['wp_version'] = $this->original_wp_version;

		parent::tear_down();
	}

	/**
	 * The premise every test below rests on: the symbol really is taken in this
	 * process, so the gate is being exercised rather than skipped past.
	 */
	public function test_the_api_symbol_is_already_declared_in_this_process() {
		$this->assertTrue( function_exists( 'wp_get_secret' ) );
	}

	// -- core ships the API ---------------------------------------------------

	public function test_core_providing_the_api_is_a_silent_no_op_with_an_info_notice() {
		$GLOBALS['wp_version'] = WP_SECRETS_API_CORE_VERSION;

		wp_secrets_api_bootstrap();

		$this->assertSame( 10, has_action( 'admin_notices', 'wp_secrets_api_notice_superseded' ) );
		$this->assertFalse( has_action( 'admin_notices', 'wp_secrets_api_notice_conflict' ) );
	}

	public function test_a_wordpress_newer_than_the_core_version_also_no_ops() {
		$GLOBALS['wp_version'] = '9.9';

		wp_secrets_api_bootstrap();

		$this->assertSame( 10, has_action( 'admin_notices', 'wp_secrets_api_notice_superseded' ) );
	}

	// -- something else claimed the symbol -------------------------------------

	/**
	 * The version gate is ANDed with a positive probe rather than used alone,
	 * so that a 7.2 which ships *without* the API does not silently disable this
	 * plugin and strand every site relying on it. Below the core version, a taken
	 * symbol therefore means a conflict, not a supersession.
	 */
	public function test_below_the_core_version_a_taken_symbol_is_a_conflict() {
		$GLOBALS['wp_version'] = '6.8';

		wp_secrets_api_bootstrap();

		$this->assertSame( 10, has_action( 'admin_notices', 'wp_secrets_api_notice_conflict' ) );
		$this->assertFalse( has_action( 'admin_notices', 'wp_secrets_api_notice_superseded' ) );
	}

	/**
	 * Both no-op branches must return before reaching the rest of the bootstrap.
	 * The capability filter is the last thing that bootstrap registers before the
	 * WP-CLI block, so its absence is a decent proxy for "returned early" without
	 * this test needing to know every side effect the function can have.
	 */
	public function test_the_no_op_branches_return_before_registering_anything_else() {
		remove_all_filters( 'user_has_cap' );

		$GLOBALS['wp_version'] = '6.8';
		wp_secrets_api_bootstrap();

		$this->assertFalse( has_filter( 'user_has_cap', 'wp_secrets_api_grant_network_cap_to_super_admins' ) );

		$GLOBALS['wp_version'] = WP_SECRETS_API_CORE_VERSION;
		wp_secrets_api_bootstrap();

		$this->assertFalse( has_filter( 'user_has_cap', 'wp_secrets_api_grant_network_cap_to_super_admins' ) );
	}

	// -- the notices themselves ------------------------------------------------

	public function test_notices_say_nothing_to_a_user_who_cannot_activate_plugins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		wp_secrets_api_notice_superseded();
		wp_secrets_api_notice_conflict();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * "Can activate plugins" is not the same role on both installs: on multisite
	 * activate_plugins is a super-admin capability, so a plain administrator
	 * legitimately sees nothing. The notice is gated on the capability rather
	 * than on a role for exactly that reason, and this test grants whichever one
	 * the current install actually requires rather than assuming single-site.
	 */
	public function test_notices_render_for_a_user_who_can_activate_plugins() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'activate_plugins' ) );

		ob_start();
		wp_secrets_api_notice_superseded();
		$superseded = ob_get_clean();

		ob_start();
		wp_secrets_api_notice_conflict();
		$conflict = ob_get_clean();

		$this->assertStringContainsString( 'natively', $superseded );
		$this->assertStringContainsString( 'wp_get_secret()', $conflict );
	}
}
