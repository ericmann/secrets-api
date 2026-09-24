<?php
/**
 * Offline tests for AWS_Secrets_Manager_Provider's site-scope naming. Every
 * request is faked through pre_http_request; nothing here touches AWS.
 *
 * @package SecretsAPI\Examples
 */

class Tests_AWS_Secrets_Manager_Naming extends WP_UnitTestCase {

	/** @var array */
	private $requests = array();

	/** @var array */
	private $queue = array();

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->queue    = array();

		add_filter( 'pre_http_request', array( $this, 'fake_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_request' ), 10 );

		parent::tear_down();
	}

	public function fake_request( $preempt, $parsed_args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $parsed_args,
		);

		if ( ! empty( $this->queue ) ) {
			return array_shift( $this->queue );
		}

		return $this->fake_response( 200, array() );
	}

	private function fake_response( $code, array $body ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function queue_response( $code, array $body ) {
		$this->queue[] = $this->fake_response( $code, $body );
	}

	private function provider() {
		return new AWS_Secrets_Manager_Provider( 'us-east-1', 'test-key', 'test-secret' );
	}

	private function secret_id( $index = 0 ) {
		$body = json_decode( $this->requests[ $index ]['args']['body'], true );

		return isset( $body['SecretId'] ) ? $body['SecretId'] : null;
	}

	public function test_site_scope_names_include_the_blog_id() {
		$this->queue_response( 200, array( 'SecretString' => 'v' ) );

		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertSame( 'wp/site/1/acme/key', $this->secret_id() );
		$this->assertSame( 'secretsmanager.GetSecretValue', $this->requests[0]['args']['headers']['X-Amz-Target'] );
	}

	public function test_network_scope_names_are_unchanged() {
		$this->queue_response( 200, array( 'SecretString' => 'v' ) );

		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT, true );

		$this->assertSame( 'wp-network/acme/key', $this->secret_id() );
	}

	public function test_set_uses_the_same_site_scoped_name() {
		$this->queue_response( 200, array() );

		$this->provider()->set( 'acme/key', 'v' );

		$this->assertSame( 'wp/site/1/acme/key', $this->secret_id() );
	}

	public function test_listing_maps_site_scoped_names_back_and_ignores_the_rest() {
		$this->queue_response(
			200,
			array(
				'SecretList' => array(
					array( 'Name' => 'wp/site/1/acme/key' ),
					array( 'Name' => 'wp/site/2/acme/key' ),
					array( 'Name' => 'wp/acme/legacy' ),
					array( 'Name' => 'wp-network/acme/key' ),
				),
			)
		);

		$names = wp_list_pluck( $this->provider()->list_secrets(), 'name' );
		$this->assertSame( array( 'acme/key' ), $names );

		$this->queue_response(
			200,
			array(
				'SecretList' => array(
					array( 'Name' => 'wp/site/1/acme/key' ),
					array( 'Name' => 'wp/site/2/acme/key' ),
					array( 'Name' => 'wp/acme/legacy' ),
					array( 'Name' => 'wp-network/acme/key' ),
				),
			)
		);

		$names = wp_list_pluck( $this->provider()->list_secrets( '', true ), 'name' );
		$this->assertSame( array( 'acme/key' ), $names );
	}

	public function test_the_blog_id_is_read_at_call_time_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );

		$this->queue_response( 200, array( 'SecretString' => 'v' ) );

		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertSame( "wp/site/{$blog}/acme/key", $this->secret_id() );

		restore_current_blog();
	}
}
