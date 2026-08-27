<?php
/**
 * Tests for capability registration: WP_SECRETS_CAP_MANAGE /
 * WP_SECRETS_CAP_MANAGE_NETWORK and the activation/uninstall/super-admin plumbing
 * that grants and revokes them.
 *
 * @group secrets
 */
class Tests_Secrets_Capabilities extends WP_UnitTestCase {

	public function tear_down() {
		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			$administrator->remove_cap( WP_SECRETS_CAP_MANAGE );
		}

		parent::tear_down();
	}

	public function test_capability_constant_values() {
		$this->assertSame( 'manage_secrets', WP_SECRETS_CAP_MANAGE );
		$this->assertSame( 'manage_network_secrets', WP_SECRETS_CAP_MANAGE_NETWORK );
	}

	public function test_activate_grants_manage_secrets_to_administrator() {
		$administrator = get_role( 'administrator' );
		$administrator->remove_cap( WP_SECRETS_CAP_MANAGE );

		wp_secrets_api_activate();

		$this->assertTrue( get_role( 'administrator' )->has_cap( WP_SECRETS_CAP_MANAGE ) );
	}

	public function test_uninstall_removes_manage_secrets_from_administrator() {
		wp_secrets_api_activate();
		$this->assertTrue( get_role( 'administrator' )->has_cap( WP_SECRETS_CAP_MANAGE ) );

		wp_secrets_api_uninstall();

		$this->assertFalse( get_role( 'administrator' )->has_cap( WP_SECRETS_CAP_MANAGE ) );
	}

	public function test_administrator_has_manage_secrets_after_activation() {
		wp_secrets_api_activate();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $user_id, WP_SECRETS_CAP_MANAGE ) );
	}

	public function test_subscriber_does_not_have_manage_secrets() {
		wp_secrets_api_activate();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $user_id, WP_SECRETS_CAP_MANAGE ) );
	}

	/**
	 * @dataProvider data_network_cap_filter
	 */
	public function test_network_cap_filter( $requested_caps, $is_multisite, $is_super_admin, $expect_granted ) {
		if ( $is_multisite && ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		if ( ! $is_multisite && is_multisite() ) {
			$this->markTestSkipped( 'Requires single-site (tests the is_multisite() guard itself).' );
		}

		$user_id = self::factory()->user->create();

		if ( $is_super_admin && is_multisite() ) {
			grant_super_admin( $user_id );
		}

		$allcaps = wp_secrets_api_grant_network_cap_to_super_admins(
			array(),
			$requested_caps,
			array(),
			get_user_by( 'id', $user_id )
		);

		if ( $expect_granted ) {
			$this->assertArrayHasKey( WP_SECRETS_CAP_MANAGE_NETWORK, $allcaps );
			$this->assertTrue( $allcaps[ WP_SECRETS_CAP_MANAGE_NETWORK ] );
		} else {
			$this->assertArrayNotHasKey( WP_SECRETS_CAP_MANAGE_NETWORK, $allcaps );
		}
	}

	public function data_network_cap_filter() {
		return array(
			'multisite super admin requesting the cap'     => array( array( 'manage_network_secrets' ), true, true, true ),
			'multisite non-super-admin requesting the cap' => array( array( 'manage_network_secrets' ), true, false, false ),
			'multisite super admin requesting another cap' => array( array( 'manage_options' ), true, true, false ),
			'single-site: never granted regardless'        => array( array( 'manage_network_secrets' ), false, false, false ),
		);
	}
}
