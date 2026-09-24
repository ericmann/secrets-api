<?php
/**
 * Offline tests for Vault_KV2_Provider's path mapping, HTTP client, error
 * mapping, get(), and delete(). Every request is faked through
 * pre_http_request; nothing here touches a real Vault server.
 *
 * @package SecretsAPI\Examples
 */

class Tests_Vault_Provider_Paths extends WP_UnitTestCase {

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

		return $this->fake_response( 404, array( 'errors' => array() ) );
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

	private function provider( $mount = 'secret', $namespace = '' ) {
		return new Vault_KV2_Provider( 'http://vault.test:8200', 'test-token', $mount, $namespace );
	}

	public function test_site_scope_maps_to_wp_site_blog_id_namespace_key() {
		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertSame( 'http://vault.test:8200/v1/secret/data/wp/site/1/acme/key', $this->requests[0]['url'] );
	}

	public function test_network_scope_maps_to_wp_network_namespace_key() {
		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT, true );

		$this->assertSame( 'http://vault.test:8200/v1/secret/data/wp/network/acme/key', $this->requests[0]['url'] );
	}

	public function test_a_custom_mount_and_namespace_are_used() {
		$this->provider( 'kv', 'team-a' )->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertStringContainsString( '/v1/kv/data/', $this->requests[0]['url'] );
		$this->assertSame( 'team-a', $this->requests[0]['args']['headers']['X-Vault-Namespace'] );

		$this->requests = array();
		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertArrayNotHasKey( 'X-Vault-Namespace', $this->requests[0]['args']['headers'] );
	}

	public function test_the_token_header_is_sent_and_the_timeout_is_the_constant() {
		$this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertSame( 'test-token', $this->requests[0]['args']['headers']['X-Vault-Token'] );
		$this->assertSame( Vault_KV2_Provider::REQUEST_TIMEOUT, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_404_on_current_is_null() {
		$this->queue_response( 404, array( 'errors' => array() ) );

		$this->assertNull( $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT ) );
	}

	public function test_a_403_is_store_unavailable_with_vaults_message() {
		$this->queue_response( 403, array( 'errors' => array( 'permission denied' ) ) );

		$result = $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
		$this->assertStringContainsString( 'permission denied', $result->get_error_message() );
	}

	public function test_a_sealed_vault_is_store_unavailable_not_null() {
		$this->queue_response( 503, array( 'errors' => array( 'Vault is sealed' ) ) );

		$result = $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Vault is sealed', $result->get_error_message() );
	}

	public function test_a_transport_failure_is_store_unavailable() {
		remove_filter( 'pre_http_request', array( $this, 'fake_request' ), 10 );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7' );
			}
		);

		$result = $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_a_missing_value_field_is_record_malformed() {
		$this->queue_response( 200, array( 'data' => array( 'data' => array() ) ) );

		$result = $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_RECORD_MALFORMED, $result->get_error_code() );
	}

	public function test_current_reveals_the_value_and_is_memoised() {
		$this->queue_response( 200, array( 'data' => array( 'data' => array( 'value' => 'sk_live_x' ) ) ) );

		$provider = $this->provider();
		$secret   = $provider->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertInstanceOf( 'WP_Secret', $secret );
		$this->assertSame( 'sk_live_x', $secret->reveal() );

		$count = count( $this->requests );
		$provider->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertCount( $count, $this->requests );
	}

	public function test_previous_with_one_version_is_null_without_a_data_read() {
		$this->queue_response(
			200,
			array(
				'data' => array(
					'current_version' => 1,
					'versions'        => array(
						'1' => array(),
					),
				),
			)
		);

		$this->assertNull( $this->provider()->get( 'acme/key', WP_Secret_Version::PREVIOUS ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_previous_skips_a_destroyed_n_minus_1_rather_than_falling_back() {
		$this->queue_response(
			200,
			array(
				'data' => array(
					'current_version' => 3,
					'versions'        => array(
						'1' => array(),
						'2' => array( 'destroyed' => true ),
						'3' => array(),
					),
				),
			)
		);

		$this->assertNull( $this->provider()->get( 'acme/key', WP_Secret_Version::PREVIOUS ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_previous_reads_exactly_n_minus_1() {
		$this->queue_response(
			200,
			array(
				'data' => array(
					'current_version' => 3,
					'versions'        => array(
						'2' => array(),
						'3' => array(),
					),
				),
			)
		);
		$this->queue_response( 200, array( 'data' => array( 'data' => array( 'value' => 'v2' ) ) ) );

		$secret = $this->provider()->get( 'acme/key', WP_Secret_Version::PREVIOUS );

		$this->assertStringEndsWith( '?version=2', $this->requests[1]['url'] );
		$this->assertSame( 'v2', $secret->reveal() );
	}

	public function test_delete_returns_true_on_204_and_fires_deleted() {
		$this->queue_response( 204, array() );

		$fired = array();
		add_action(
			'wp_secret_changed',
			static function ( $name, $action ) use ( &$fired ) {
				$fired[] = $action;
			},
			10,
			2
		);

		$this->assertTrue( $this->provider()->delete( 'acme/key' ) );
		$this->assertSame( array( 'deleted' ), $fired );
	}

	public function test_delete_on_a_sealed_vault_is_an_error_not_success() {
		$this->queue_response( 503, array( 'errors' => array( 'Vault is sealed' ) ) );

		$this->assertWPError( $this->provider()->delete( 'acme/key' ) );
	}

	public function test_declarations() {
		$provider = $this->provider();

		$this->assertSame( 'HashiCorp Vault (http://vault.test:8200, mount secret)', $provider->get_label() );
		$this->assertSame( WP_Secrets_Provider::BOUNDARY_PROVIDER, $provider->get_protection_boundary() );
		$this->assertTrue( $provider->is_writable() );
	}
}
