<?php
/**
 * Integration tests for the AWS KMS keyring example against Moto: a full secret
 * round trip with the keyring installed, the request-scoped call count the
 * detailed spec's "Done when" asks for, the config-keyring adoption error, and
 * adoption via `wp secret rotate --from=config`.
 *
 * @group secrets-examples
 */

require_once __DIR__ . '/class-moto-kms-fixture.php';

class Tests_AWS_KMS_Keyring extends WP_UnitTestCase {

	/**
	 * One key, created once for the whole run. See
	 * Tests_AWS_KMS_Keyring_Conformance for why.
	 *
	 * @var string
	 */
	private static $key_id;

	/**
	 * Counts TrentService.Decrypt calls seen on http_api_debug, reset per test.
	 *
	 * @var int
	 */
	private $decrypt_calls = 0;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$key_id = Moto_KMS_Fixture::create_key();
	}

	public function set_up() {
		parent::set_up();

		$this->decrypt_calls = 0;
	}

	public function tear_down() {
		remove_action( 'http_api_debug', array( $this, 'count_decrypt_calls' ) );

		parent::tear_down();
	}

	/**
	 * @param mixed  $response    Response or WP_Error.
	 * @param string $context     Always 'response'.
	 * @param string $class       HTTP transport class used.
	 * @param array  $parsed_args Request args, including headers.
	 * @param string $url         Request URL.
	 */
	public function count_decrypt_calls( $response, $context, $class, $parsed_args, $url ) {
		unset( $response, $context, $class, $url );

		if ( isset( $parsed_args['headers']['X-Amz-Target'] ) && 'TrentService.Decrypt' === $parsed_args['headers']['X-Amz-Target'] ) {
			++$this->decrypt_calls;
		}
	}

	/**
	 * @return AWS_KMS_Keyring
	 */
	private function keyring() {
		return new AWS_KMS_Keyring(
			self::$key_id,
			Moto_KMS_Fixture::region(),
			'testing',
			'testing',
			Moto_KMS_Fixture::endpoint()
		);
	}

	/**
	 * Wraps 32 fresh random bytes under $keyring and stores the result as the
	 * site's root key, the way a site that has always used this keyring would
	 * already have one.
	 *
	 * @param WP_Secrets_Keyring $keyring
	 *
	 * @return string The 32 raw bytes that were wrapped.
	 */
	private function seed_root_key( WP_Secrets_Keyring $keyring ) {
		$root = random_bytes( 32 );

		update_site_option( WP_Secrets_Key_Manager::ROOT_KEY_OPTION, $keyring->wrap( $root ) );

		return $root;
	}

	/**
	 * A hand-built provider that never touches the static getters in
	 * secrets.php, so a test can write secrets before installing the KMS
	 * keyring without priming _wp_secrets_get_provider()'s cache to the
	 * pre-installation state.
	 *
	 * @return WP_Secrets_Libsodium_Provider
	 */
	private function provider_under_config_keyring() {
		return new WP_Secrets_Libsodium_Provider(
			new WP_Secrets_Option_Store(),
			new WP_Secrets_Key_Manager( new WP_Secrets_Config_Key_Provider() )
		);
	}

	/**
	 * Collects every string WP_CLI recorded, across every kind of output the
	 * mock tracks, so a canary-leak assertion has one place to check.
	 *
	 * @return string
	 */
	private function all_wp_cli_output() {
		return implode(
			"\n",
			array_merge(
				WP_CLI::$log,
				WP_CLI::$success,
				WP_CLI::$warning,
				WP_CLI::$errors,
				array( wp_json_encode( WP_CLI::$formatted_items ) )
			)
		);
	}

	// -- install guard, wrap/unwrap contract, failure modes ------------------

	public function test_loading_the_example_does_not_install_a_keyring_without_the_constants() {
		$this->assertArrayNotHasKey( 'wp_secrets_keyring', $GLOBALS );
	}

	public function test_wrapped_values_carry_the_kms1_prefix_and_never_the_key_material() {
		$material = random_bytes( 32 );
		$wrapped  = $this->keyring()->wrap( $material );

		$this->assertIsString( $wrapped );
		$this->assertStringStartsWith( 'kms1:', $wrapped );
		$this->assertStringNotContainsString( $material, $wrapped );
		$this->assertStringNotContainsString( base64_encode( $material ), $wrapped );
	}

	public function test_a_config_keyring_blob_is_refused_with_an_adoption_message() {
		$config_wrapped = ( new WP_Secrets_Config_Key_Provider() )->wrap( random_bytes( 32 ) );

		$result = $this->keyring()->unwrap( $config_wrapped );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_KEY_UNAVAILABLE, $result->get_error_code() );
		$this->assertStringContainsString( 'rotate --from=config', $result->get_error_message() );
	}

	public function test_an_unreachable_kms_fails_closed_with_a_wp_error() {
		$keyring = new AWS_KMS_Keyring( self::$key_id, Moto_KMS_Fixture::region(), 'testing', 'testing', 'http://127.0.0.1:9' );

		$wrap_result = $keyring->wrap( random_bytes( 32 ) );
		$this->assertWPError( $wrap_result );
		$this->assertSame( WP_SECRETS_ERROR_KEY_UNAVAILABLE, $wrap_result->get_error_code() );

		$unwrap_result = $keyring->unwrap( 'kms1:AAAA' );
		$this->assertWPError( $unwrap_result );
		$this->assertSame( WP_SECRETS_ERROR_KEY_UNAVAILABLE, $unwrap_result->get_error_code() );
	}

	public function test_get_key_source_names_the_key_and_region_but_not_the_credentials() {
		$source = $this->keyring()->get_key_source();

		$this->assertStringContainsString( self::$key_id, $source );
		$this->assertStringContainsString( 'us-east-1', $source );
		$this->assertStringNotContainsString( 'testing', $source );
	}

	// -- end-to-end, isolated-process tests -----------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_full_secret_round_trip_with_the_kms_keyring_active() {
		$GLOBALS['wp_secrets_keyring'] = $this->keyring();

		$set_result = wp_set_secret( 'kms/canary', 'UNIQUE-KMS-CANARY-4b1e' );
		$this->assertTrue( $set_result );

		$secret = wp_get_secret( 'kms/canary' );
		$this->assertInstanceOf( WP_Secret::class, $secret );
		$this->assertSame( 'UNIQUE-KMS-CANARY-4b1e', $secret->reveal() );

		$stored = get_site_option( WP_Secrets_Key_Manager::ROOT_KEY_OPTION );
		$this->assertStringStartsWith( 'kms1:', $stored );

		$this->assertStringContainsString( 'AWS KMS key', wp_secrets_provider_label() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ten_secret_reads_make_one_kms_decrypt_call() {
		$keyring = $this->keyring();
		$this->seed_root_key( $keyring );

		$GLOBALS['wp_secrets_keyring'] = $keyring;

		add_action( 'http_api_debug', array( $this, 'count_decrypt_calls' ), 10, 5 );

		$this->assertTrue( wp_set_secret( 'kms/ten-reads', 'UNIQUE-KMS-CANARY-4b1e' ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$secret = wp_get_secret( 'kms/ten-reads' );
			$this->assertInstanceOf( WP_Secret::class, $secret );
			$this->assertSame( 'UNIQUE-KMS-CANARY-4b1e', $secret->reveal() );
		}

		$this->assertSame( 1, $this->decrypt_calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_adopting_an_existing_site_with_rotate_from_config_keeps_every_secret_readable() {
		// Root key starts life wrapped by the config keyring, as an existing
		// site's would.
		define( 'WP_SECRETS_KEY', base64_encode( str_repeat( 'C', 32 ) ) );

		$config_provider = $this->provider_under_config_keyring();

		$names = array( 'kms/one', 'kms/two', 'kms/three' );

		foreach ( $names as $name ) {
			$this->assertTrue( $config_provider->set( $name, 'UNIQUE-KMS-CANARY-4b1e', false ) );
		}

		// Now the drop-in installs the KMS keyring.
		$GLOBALS['wp_secrets_keyring'] = $this->keyring();

		$blocked = wp_get_secret( $names[0] );
		$this->assertWPError( $blocked );
		$this->assertStringContainsString( 'rotate --from=config', $blocked->get_error_message() );

		WP_CLI::reset();
		( new WP_CLI_Secret_Command() )->rotate( array(), array( 'from' => 'config', 'yes' => true ) );

		foreach ( $names as $name ) {
			$secret = wp_get_secret( $name );
			$this->assertInstanceOf( WP_Secret::class, $secret );
			$this->assertSame( 'UNIQUE-KMS-CANARY-4b1e', $secret->reveal() );
		}

		$stored = get_site_option( WP_Secrets_Key_Manager::ROOT_KEY_OPTION );
		$this->assertStringStartsWith( 'kms1:', $stored );

		( new WP_CLI_Secret_Command() )->health( array(), array() );

		foreach ( WP_CLI::$formatted_items as $formatted ) {
			foreach ( $formatted['items'] as $item ) {
				$this->assertNotSame( 'critical', $item['status'] );
			}
		}

		$this->assertStringNotContainsString( 'UNIQUE-KMS-CANARY-4b1e', $this->all_wp_cli_output() );
	}
}
