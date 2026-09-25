<?php
/**
 * Proves the examples suite can reach a real Vault dev server.
 *
 * @package SecretsAPI\Examples
 */

/**
 * Tests for Vault_Test_Server itself.
 */
class Tests_Vault_Harness extends WP_UnitTestCase {

	/** @var Vault_Test_Server */
	private $server;

	public function set_up() {
		parent::set_up();

		$this->server = new Vault_Test_Server();
	}

	public function test_the_dev_server_is_reachable_and_unsealed() {
		$health = $this->server->health();

		$this->assertTrue( $health['initialized'] );
		$this->assertFalse( $health['sealed'] );
	}

	public function test_kv_v2_is_mounted_at_secret() {
		$result = $this->server->request( 'GET', 'sys/mounts' );

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( '2', $result['body']['secret/']['options']['version'] );
		$this->assertSame( '2', $result['body']['data']['secret/']['options']['version'] );
	}

	public function test_wipe_removes_everything_under_wp() {
		$this->server->request( 'POST', 'secret/data/wp/site/1/harness/one', array( 'data' => array( 'value' => 'one' ) ) );
		$this->server->request( 'POST', 'secret/data/wp/network/harness/two', array( 'data' => array( 'value' => 'two' ) ) );

		$this->server->wipe();

		$this->assertSame( array(), $this->server->list_keys( 'wp/' ) );
		$this->assertNull( $this->server->metadata( 'wp/site/1/harness/one' ) );
	}

	public function test_the_helper_fails_loudly_when_vault_is_unreachable() {
		$helper = new Vault_Test_Server( 'http://127.0.0.1:1' );

		$this->expectException( PHPUnit\Framework\AssertionFailedError::class );

		$helper->metadata( 'wp/site/1/acme/key' );
	}

	public function test_wipe_fails_loudly_when_vault_is_unreachable() {
		$helper = new Vault_Test_Server( 'http://127.0.0.1:1' );

		$this->expectException( PHPUnit\Framework\AssertionFailedError::class );

		$helper->wipe();
	}
}
