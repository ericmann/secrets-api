<?php
/**
 * Live-server multisite tests: site scope is isolated per blog, network
 * scope is shared across blogs.
 *
 * @package SecretsAPI\Examples
 */

class Tests_Vault_Provider_Multisite extends WP_UnitTestCase {

	/** @var Vault_Test_Server */
	private $server;

	/** @var Vault_KV2_Provider */
	private $provider;

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$this->server = new Vault_Test_Server();
		$this->server->wipe();
		$this->provider = $this->server->provider();
	}

	public function tear_down() {
		if ( ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	public function test_site_scope_is_isolated_per_blog() {
		$this->provider->set( 'acme/key', 'blog-one' );

		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );

		$this->assertNull( $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT ) );

		$this->provider->set( 'acme/key', 'blog-two' );

		$this->assertSame( 'blog-two', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );

		$names = wp_list_pluck( $this->provider->list_secrets(), 'name' );
		$this->assertSame( array( 'acme/key' ), $names );

		restore_current_blog();

		$this->assertSame( 'blog-one', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );

		$this->assertNotNull( $this->server->metadata( 'wp/site/1/acme/key' ) );
		$this->assertNotNull( $this->server->metadata( "wp/site/{$blog}/acme/key" ) );
	}

	public function test_network_scope_is_shared_across_blogs() {
		$this->provider->set( 'acme/key', 'net', true );

		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );

		$this->assertSame( 'net', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT, true )->reveal() );

		restore_current_blog();

		$this->assertNotNull( $this->server->metadata( 'wp/network/acme/key' ) );
		$this->assertNull( $this->server->metadata( "wp/site/{$blog}/acme/key" ) );
	}

	public function test_deleting_on_one_blog_leaves_the_other() {
		$this->provider->set( 'acme/key', 'blog-one' );

		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		$this->provider->set( 'acme/key', 'blog-two' );

		$this->assertTrue( $this->provider->delete( 'acme/key' ) );

		restore_current_blog();

		$this->assertSame( 'blog-one', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );
	}
}
