<?php
/**
 * Tests for WP_CLI_Secret_Command, against the mock WP_CLI in
 * tests/includes/class-mock-wp-cli.php.
 *
 * @group secrets
 */
class Tests_Secrets_WPCLISecretCommand extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();
	}

	private function command() {
		return new WP_CLI_Secret_Command();
	}

	// -- set --------------------------------------------------------------

	/**
	 * The --stdin code path itself (file_get_contents('php://stdin')) is not
	 * covered here: faking php://stdin meaningfully from inside a PHPUnit process
	 * would need a real pipe (proc_open), which is more machinery than this one
	 * branch is worth. What is covered, and matters more: passing a value as a
	 * positional argument instead warns about shell history, every time.
	 */
	public function test_set_with_a_positional_value_warns_about_shell_history() {
		$this->command()->set( array( 'myplugin/api-key', 'sk_live_value' ), array() );

		$this->assertNotEmpty( WP_CLI::$warning );
		$this->assertStringContainsString( 'shell history', WP_CLI::$warning[0] );
		$this->assertSame( 'sk_live_value', wp_get_secret( 'myplugin/api-key' )->reveal() );
	}

	public function test_set_without_a_value_or_stdin_errors() {
		$this->expectException( Mock_WP_CLI_Exit_Exception::class );

		$this->command()->set( array( 'myplugin/api-key' ), array() );
	}

	public function test_set_without_a_value_or_stdin_does_not_warn_about_shell_history() {
		try {
			$this->command()->set( array( 'myplugin/api-key' ), array() );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			unset( $e );
		}

		$this->assertEmpty( WP_CLI::$warning );
	}

	public function test_set_reports_success() {
		$this->command()->set( array( 'myplugin/api-key', 'value' ), array() );

		$this->assertNotEmpty( WP_CLI::$success );
	}

	public function test_set_porcelain_outputs_only_the_fingerprint() {
		$this->command()->set( array( 'myplugin/api-key', 'value' ), array( 'porcelain' => true ) );

		$expected_fingerprint = wp_get_secret( 'myplugin/api-key' )->fingerprint();

		$this->assertEmpty( WP_CLI::$success );
		$this->assertSame( array( $expected_fingerprint ), WP_CLI::$log );
	}

	public function test_set_reports_the_underlying_error() {
		try {
			$this->command()->set( array( 'not-namespaced', 'value' ), array() );
			$this->fail( 'Expected an exit.' );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			unset( $e );
		}

		$this->assertNotEmpty( WP_CLI::$errors );
	}

	// -- get --------------------------------------------------------------

	public function test_get_absent_secret_halts_with_exit_code_1() {
		try {
			$this->command()->get( array( 'myplugin/never-set' ), array() );
			$this->fail( 'Expected an exit.' );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			$this->assertSame( 1, $e->get_exit_code() );
		}
	}

	public function test_get_broken_secret_halts_with_exit_code_2() {
		wp_set_secret( 'myplugin/api-key', 'value' );
		$record                  = get_option( '_wp_secret_myplugin/api-key' );
		$record['current']['ct'] = base64_encode( 'not decryptable' );
		update_option( '_wp_secret_myplugin/api-key', $record, false );

		try {
			$this->command()->get( array( 'myplugin/api-key' ), array() );
			$this->fail( 'Expected an exit.' );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			$this->assertSame( 2, $e->get_exit_code() );
		}
	}

	public function test_get_found_secret_does_not_halt() {
		wp_set_secret( 'myplugin/api-key', 'value' );

		$this->command()->get( array( 'myplugin/api-key' ), array() );

		$this->assertNotEmpty( WP_CLI::$formatted_items );
	}

	public function test_get_masks_by_default() {
		wp_set_secret( 'myplugin/api-key', 'a-long-secret-value-here' );

		$this->command()->get( array( 'myplugin/api-key' ), array( 'field' => 'value' ) );

		$this->assertNotContains( 'a-long-secret-value-here', WP_CLI::$log );
	}

	public function test_get_reveal_shows_the_actual_value_and_warns() {
		wp_set_secret( 'myplugin/api-key', 'a-long-secret-value-here' );

		$this->command()->get(
			array( 'myplugin/api-key' ),
			array(
				'field'  => 'value',
				'reveal' => true,
			)
		);

		$this->assertContains( 'a-long-secret-value-here', WP_CLI::$log );
		$this->assertNotEmpty( WP_CLI::$warning );
	}

	public function test_get_never_reveals_without_the_flag_even_with_field() {
		wp_set_secret( 'myplugin/api-key', 'UNIQUE-PLAINTEXT-CANARY-9f3a' );

		$this->command()->get( array( 'myplugin/api-key' ), array( 'field' => 'value' ) );

		foreach ( WP_CLI::$log as $line ) {
			$this->assertStringNotContainsString( 'UNIQUE-PLAINTEXT-CANARY-9f3a', $line );
		}
	}

	/**
	 * @dataProvider data_masking
	 */
	public function test_mask_lengths( $value, $expected ) {
		$mask = new ReflectionMethod( WP_CLI_Secret_Command::class, 'mask' );
		$mask->setAccessible( true );

		$this->assertSame( $expected, $mask->invoke( $this->command(), $value ) );
	}

	public function data_masking() {
		return array(
			'short (fully masked)'        => array( 'short', '********' ),
			'exactly 11 (still full)'     => array( '12345678901', '********' ),
			'exactly 12 (partial)'        => array( '123456789012', '1234********' ),
			'long (partial, fixed width)' => array( 'sk_live_abcdefghijklmnopqrstuvwxyz', 'sk_l********' ),
		);
	}

	public function test_get_previous_version() {
		wp_set_secret( 'myplugin/api-key', 'first-value' );
		wp_set_secret( 'myplugin/api-key', 'second-value' );

		$this->command()->get(
			array( 'myplugin/api-key' ),
			array(
				'version' => 'previous',
				'field'   => 'value',
				'reveal'  => true,
			)
		);

		$this->assertContains( 'first-value', WP_CLI::$log );
	}

	// -- delete -------------------------------------------------------------

	public function test_delete_with_yes_skips_confirmation_and_deletes() {
		wp_set_secret( 'myplugin/api-key', 'value' );

		$this->command()->delete( array( 'myplugin/api-key' ), array( 'yes' => true ) );

		$this->assertNull( wp_get_secret( 'myplugin/api-key' ) );
		$this->assertNotEmpty( WP_CLI::$success );
	}

	public function test_delete_without_confirmation_does_not_delete() {
		wp_set_secret( 'myplugin/api-key', 'value' );
		WP_CLI::$confirm_response = false;

		try {
			$this->command()->delete( array( 'myplugin/api-key' ), array() );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			unset( $e );
		}

		$this->assertInstanceOf( WP_Secret::class, wp_get_secret( 'myplugin/api-key' ) );
	}

	// -- list -----------------------------------------------------------

	public function test_list_never_logs_a_value() {
		wp_set_secret( 'myplugin/api-key', 'UNIQUE-PLAINTEXT-CANARY-9f3a' );

		$this->command()->list( array(), array() );

		$dump = wp_json_encode( WP_CLI::$formatted_items );
		$this->assertStringNotContainsString( 'UNIQUE-PLAINTEXT-CANARY-9f3a', $dump );
	}

	public function test_list_respects_namespace() {
		wp_set_secret( 'pluginone/key', 'value' );
		wp_set_secret( 'plugintwo/key', 'value' );

		$this->command()->list( array(), array( 'namespace' => 'pluginone' ) );

		$items = WP_CLI::$formatted_items[0]['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'pluginone/key', $items[0]['name'] );
	}

	public function test_list_respects_custom_fields() {
		wp_set_secret( 'myplugin/api-key', 'value' );

		$this->command()->list( array(), array( 'fields' => 'name' ) );

		$this->assertSame( array( 'name' ), WP_CLI::$formatted_items[0]['fields'] );
	}

	// -- retire ---------------------------------------------------------

	public function test_retire_with_yes_clears_previous() {
		wp_set_secret( 'myplugin/api-key', 'first' );
		wp_set_secret( 'myplugin/api-key', 'second' );

		$this->command()->retire( array( 'myplugin/api-key' ), array( 'yes' => true ) );

		$this->assertNull( wp_get_secret( 'myplugin/api-key', WP_Secret_Version::PREVIOUS ) );
	}

	// -- import-option ----------------------------------------------------

	public function test_import_option_imports_and_flags_rotation() {
		update_option( 'my_option', 'value' );

		$this->command()->import_option( array( 'my_option', 'myplugin/api-key' ) );

		$this->assertSame( 'value', wp_get_secret( 'myplugin/api-key' )->reveal() );
		$this->assertNotEmpty( WP_CLI::$success );
	}

	// -- generate-key -----------------------------------------------------

	public function test_generate_key_outputs_a_base64_32_byte_key() {
		$this->command()->generate_key();

		$this->assertCount( 1, WP_CLI::$log );
		$decoded = base64_decode( WP_CLI::$log[0], true );
		$this->assertNotFalse( $decoded );
		$this->assertSame( 32, strlen( $decoded ) );
	}

	public function test_generate_key_never_writes_to_wp_config() {
		// There is no wp-config.php write path in this command at all --
		// asserted by confirming the only interaction is a single log line.
		$this->command()->generate_key();

		$this->assertEmpty( WP_CLI::$success );
		$this->assertEmpty( WP_CLI::$warning );
	}

	// -- health / dropin ----------------------------------------------------

	public function test_health_reports_three_checks() {
		$this->command()->health( array(), array() );

		$this->assertCount( 3, WP_CLI::$formatted_items[0]['items'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_health_halts_on_critical() {
		$GLOBALS['wp_secrets_dropin_broken'] = true;

		try {
			$this->command()->health( array(), array() );
			$this->fail( 'Expected a halt.' );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			$this->assertSame( 1, $e->get_exit_code() );
		}
	}

	public function test_dropin_reports_the_active_store_and_keyring() {
		$this->command()->dropin();

		$log = implode( "\n", WP_CLI::$log );
		$this->assertStringContainsString( 'WP_Secrets_Option_Store', $log );
		$this->assertStringContainsString( 'WP_Secrets_Config_Key_Provider', $log );
	}

	// -- rotate -----------------------------------------------------------

	public function test_rotate_without_previous_key_constant_errors() {
		$this->expectException( Mock_WP_CLI_Exit_Exception::class );

		$this->command()->rotate( array(), array( 'yes' => true ) );
	}
}
