<?php
/**
 * Live-server tests for Vault_KV2_Provider's write path: set(),
 * retire_previous(), and list_secrets(). Each test wipes everything under
 * wp/ first, since WP_UnitTestCase's rollback does not reach Vault.
 *
 * @package SecretsAPI\Examples
 */

class Tests_Vault_Provider extends WP_UnitTestCase {

	/** @var Vault_Test_Server */
	private $server;

	/** @var Vault_KV2_Provider */
	private $provider;

	public function set_up() {
		parent::set_up();

		$this->server = new Vault_Test_Server();
		$this->server->wipe();
		$this->provider = $this->server->provider();
	}

	public function test_create_sets_max_versions_to_two_in_vault_itself() {
		$this->assertTrue( $this->provider->set( 'acme/key', 'v1' ) );

		$meta = $this->server->metadata( 'wp/site/1/acme/key' );

		$this->assertSame( Vault_KV2_Provider::MAX_VERSIONS, $meta['max_versions'] );
	}

	public function test_first_write_fires_created_and_second_fires_updated() {
		$fired = array();

		add_action(
			'wp_secret_changed',
			static function ( ...$args ) use ( &$fired ) {
				$fired[] = $args;
			},
			10,
			6
		);

		$this->provider->set( 'acme/key', 'canary-1' );
		$this->provider->set( 'acme/key', 'canary-2' );

		$this->assertSame( array( 'created', 'updated' ), array( $fired[0][1], $fired[1][1] ) );
		$this->assertStringNotContainsString( 'canary-1', wp_json_encode( $fired ) );
		$this->assertStringNotContainsString( 'canary-2', wp_json_encode( $fired ) );
	}

	public function test_an_explicit_action_overrides_created_or_updated() {
		$fired = array();

		add_action(
			'wp_secret_changed',
			static function ( $name, $action ) use ( &$fired ) {
				$fired[] = $action;
			},
			10,
			2
		);

		$this->provider->set( 'acme/key', 'v1', false, false, 'imported' );

		$this->assertSame( array( 'imported' ), $fired );
	}

	public function test_update_does_not_reassert_max_versions() {
		$this->server->create_metadata( 'wp/site/1/acme/key', 10 );

		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );

		$this->assertSame( 10, $this->server->metadata( 'wp/site/1/acme/key' )['max_versions'] );
	}

	public function test_retire_destroys_exactly_n_minus_1_and_fires_retired() {
		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );

		$fired = array();
		add_action(
			'wp_secret_changed',
			static function ( $name, $action ) use ( &$fired ) {
				$fired[] = $action;
			},
			10,
			2
		);

		$this->assertTrue( $this->provider->retire_previous( 'acme/key' ) );

		$meta = $this->server->metadata( 'wp/site/1/acme/key' );

		$this->assertTrue( $meta['versions']['1']['destroyed'] );
		$this->assertSame( 200, $this->server->read_version( 'wp/site/1/acme/key', 2 ) );
		$this->assertSame( array( 'retired' ), $fired );
	}

	public function test_retire_with_nothing_to_retire_fires_nothing() {
		$this->provider->set( 'acme/key', 'v1' );

		$fired = array();
		add_action(
			'wp_secret_changed',
			static function ( $name, $action ) use ( &$fired ) {
				$fired[] = $action;
			},
			10,
			2
		);

		$this->assertTrue( $this->provider->retire_previous( 'acme/key' ) );
		$this->assertSame( array(), $fired );
	}

	public function test_list_returns_names_across_namespaces_and_never_a_value() {
		$canary = 'canary-listing-value-9d2c';

		$this->provider->set( 'alpha/one', $canary );
		$this->provider->set( 'alpha/two', $canary );
		$this->provider->set( 'beta/three', $canary );

		$names = wp_list_pluck( $this->provider->list_secrets(), 'name' );
		sort( $names );

		$this->assertSame( array( 'alpha/one', 'alpha/two', 'beta/three' ), $names );
		$this->assertStringNotContainsString( $canary, wp_json_encode( $this->provider->list_secrets() ) );

		$beta_names = wp_list_pluck( $this->provider->list_secrets( 'beta' ), 'name' );

		$this->assertSame( array( 'beta/three' ), $beta_names );
	}

	public function test_list_on_an_empty_mount_is_an_empty_array() {
		$this->assertSame( array(), $this->provider->list_secrets() );
	}
}
