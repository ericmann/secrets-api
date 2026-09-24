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

	/** @var string|false */
	private $original_error_log = false;

	public function set_up() {
		parent::set_up();

		$this->server = new Vault_Test_Server();
		$this->server->wipe();
		$this->provider = $this->server->provider();
	}

	public function tear_down() {
		if ( false !== $this->original_error_log ) {
			ini_set( 'error_log', $this->original_error_log );
			$this->original_error_log = false;
		}

		parent::tear_down();
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

	public function test_retiring_does_not_resurrect_an_older_version() {
		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );
		$this->provider->set( 'acme/key', 'v3' );

		$this->provider->retire_previous( 'acme/key' );

		$previous = $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS );
		$this->assertNotWPError( $previous );
		$this->assertNull( $previous );

		$this->provider->set( 'acme/key', 'v4' );

		$this->assertSame( 'v3', $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS )->reveal() );
		$this->assertSame( 'v4', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );
	}

	public function test_only_two_versions_are_kept_in_vault_itself() {
		$path = 'wp/site/1/acme/key';

		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );
		$this->provider->set( 'acme/key', 'v3' );

		$this->assertSame( 404, $this->server->read_version( $path, 1 ) );

		$meta = $this->server->metadata( $path );

		$this->assertArrayNotHasKey( '1', $meta['versions'] );
		$this->assertSame( 2, $meta['oldest_version'] );
		$this->assertSame( 200, $this->server->read_version( $path, 2 ) );
		$this->assertSame( 200, $this->server->read_version( $path, 3 ) );
	}

	public function test_previous_is_strictly_n_minus_1_even_when_older_versions_survive() {
		$path = 'wp/site/1/acme/key';

		$this->server->create_metadata( $path, 10 );

		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );
		$this->provider->set( 'acme/key', 'v3' );

		$this->provider->retire_previous( 'acme/key' );

		$this->assertNull( $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS ) );
		$this->assertSame( 200, $this->server->read_version( $path, 1 ) );
	}

	public function test_a_soft_deleted_n_minus_1_reads_as_absent() {
		$path = 'wp/site/1/acme/key';

		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );

		$this->server->soft_delete_versions( $path, array( 1 ) );

		$this->assertNull( $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS ) );
		$this->assertSame( 'v2', $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );
	}

	public function test_a_soft_deleted_current_reads_as_absent_not_error() {
		$path = 'wp/site/1/acme/key';

		$this->provider->set( 'acme/key', 'v1' );
		$this->server->soft_delete_versions( $path, array( 1 ) );

		$result = $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertNull( $result );
		$this->assertNotWPError( $result );
	}

	public function test_retire_clears_the_memo() {
		$this->provider->set( 'acme/key', 'v1' );
		$this->provider->set( 'acme/key', 'v2' );

		$this->assertSame( 'v1', $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS )->reveal() );

		$this->provider->retire_previous( 'acme/key' );

		$this->assertNull( $this->provider->get( 'acme/key', WP_Secret_Version::PREVIOUS ) );
	}

	public function test_retire_is_idempotent() {
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
		$this->assertTrue( $this->provider->retire_previous( 'acme/key' ) );

		$this->assertSame( array( 'retired' ), $fired );
	}

	public function test_needs_rotation_round_trips_through_custom_metadata() {
		$path = 'wp/site/1/acme/key';

		$this->assertTrue( $this->provider->set( 'acme/key', 'v', false, true ) );

		$this->assertSame( '1', $this->server->metadata( $path )['custom_metadata']['needs_rotation'] );

		$listing = $this->provider->list_secrets();
		$this->assertTrue( $listing[0]['needs_rotation'] );
	}

	public function test_a_set_without_the_flag_clears_it() {
		$path = 'wp/site/1/acme/key';

		$this->provider->set( 'acme/key', 'v1', false, true );
		$this->provider->set( 'acme/key', 'v2' );

		$this->assertSame( '0', $this->server->metadata( $path )['custom_metadata']['needs_rotation'] );

		$listing = $this->provider->list_secrets();
		$this->assertFalse( $listing[0]['needs_rotation'] );
	}

	public function test_the_flag_is_written_on_create_when_requested() {
		$seen = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$seen ) {
				if ( 'POST' === $args['method'] && false !== strpos( $url, '/metadata/' )
					&& false !== strpos( (string) $args['body'], 'custom_metadata' )
				) {
					$seen[] = $url;
				}

				return $preempt;
			},
			10,
			3
		);

		$this->provider->set( 'acme/key', 'v', false, true );

		$this->assertCount( 1, $seen );
	}

	public function test_a_set_with_an_unchanged_flag_makes_no_metadata_write() {
		$this->provider->set( 'acme/key', 'v1' );

		$seen = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$seen ) {
				if ( 'POST' === $args['method'] && false !== strpos( $url, '/metadata/' )
					&& false !== strpos( (string) $args['body'], 'custom_metadata' )
				) {
					$seen[] = $url;
				}

				return $preempt;
			},
			10,
			3
		);

		$this->provider->set( 'acme/key', 'v2' );

		$this->assertSame( array(), $seen );
	}

	public function test_a_failed_flag_write_that_was_requested_is_an_error_after_the_value_landed() {
		$canary = 'CANARY-flag-fail-7e2a';

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( 'POST' === $args['method'] && false !== strpos( $url, '/metadata/' )
					&& false !== strpos( (string) $args['body'], 'custom_metadata' )
				) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'errors' => array( 'Vault is sealed' ) ) ),
						'response' => array( 'code' => 503, 'message' => '' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$fired = array();
		add_action(
			'wp_secret_changed',
			static function ( $name, $action ) use ( &$fired ) {
				$fired[] = $action;
			},
			10,
			2
		);

		$result = $this->provider->set( 'acme/key', $canary, false, true );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
		$this->assertStringNotContainsString( $canary, $result->get_error_message() );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( $canary, $this->provider->get( 'acme/key', WP_Secret_Version::CURRENT )->reveal() );
		$this->assertSame( array( 'created' ), $fired );
	}

	public function test_a_failed_clear_is_logged_without_the_value_and_ignored() {
		$path   = 'wp/site/1/acme/key';
		$canary = 'CANARY-clear-9c1d';

		$this->provider->set( 'acme/key', 'v1', false, true );

		$log_file                 = get_temp_dir() . 'vault-provider-test-' . wp_generate_password( 8, false ) . '.log';
		$this->original_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( 'POST' === $args['method'] && false !== strpos( $url, '/metadata/' )
					&& false !== strpos( (string) $args['body'], 'custom_metadata' )
				) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'errors' => array( 'Vault is sealed' ) ) ),
						'response' => array( 'code' => 503, 'message' => '' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$this->assertTrue( $this->provider->set( 'acme/key', $canary ) );

		remove_all_filters( 'pre_http_request' );

		$log = file_exists( $log_file ) ? file_get_contents( $log_file ) : '';

		if ( file_exists( $log_file ) ) {
			unlink( $log_file );
		}

		$this->assertStringContainsString( 'could not clear needs_rotation', $log );
		$this->assertStringNotContainsString( $canary, $log );
		$this->assertSame( '1', $this->server->metadata( $path )['custom_metadata']['needs_rotation'] );
	}

	public function test_list_reports_created_and_has_previous() {
		$this->provider->set( 'acme/key', 'v1' );

		$listing = $this->provider->list_secrets();
		$this->assertLessThan( 300, abs( time() - $listing[0]['created'] ) );
		$this->assertFalse( $listing[0]['has_previous'] );

		$this->provider->set( 'acme/key', 'v2' );
		$listing = $this->provider->list_secrets();
		$this->assertTrue( $listing[0]['has_previous'] );

		$this->provider->retire_previous( 'acme/key' );
		$listing = $this->provider->list_secrets();
		$this->assertFalse( $listing[0]['has_previous'] );
	}

	public function test_list_omits_a_secret_deleted_between_list_and_metadata_read() {
		$this->provider->set( 'acme/one', 'v1' );
		$this->provider->set( 'acme/two', 'v1' );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( 'GET' === $args['method'] && false !== strpos( $url, '/metadata/wp/site/1/acme/one' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'errors' => array() ) ),
						'response' => array( 'code' => 404, 'message' => '' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$names = wp_list_pluck( $this->provider->list_secrets(), 'name' );

		$this->assertSame( array( 'acme/two' ), $names );
	}
}
