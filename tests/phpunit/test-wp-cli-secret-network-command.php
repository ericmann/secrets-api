<?php
/**
 * Tests for WP_CLI_Secret_Network_Command.
 *
 * @group secrets
 */
class Tests_Secrets_WPCLISecretNetworkCommand extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();
	}

	public function test_refuses_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Requires single-site: proves the network command refuses without one.' );
		}

		$this->expectException( Mock_WP_CLI_Exit_Exception::class );

		new WP_CLI_Secret_Network_Command();
	}

	public function test_set_and_get_operate_on_network_scope() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$command = new WP_CLI_Secret_Network_Command();
		$command->set( array( 'myplugin/api-key', 'network-value' ), array() );

		$this->assertSame( 'network-value', wp_get_network_secret( 'myplugin/api-key' )->reveal() );
		$this->assertNull( wp_get_secret( 'myplugin/api-key' ) );
	}

	public function test_import_option_is_refused_for_network_scope() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		update_option( 'my_option', 'value' );

		try {
			( new WP_CLI_Secret_Network_Command() )->import_option( array( 'my_option', 'myplugin/api-key' ) );
			$this->fail( 'Expected an exit.' );
		} catch ( Mock_WP_CLI_Exit_Exception $e ) {
			unset( $e );
		}

		$this->assertNull( wp_get_network_secret( 'myplugin/api-key' ) );
	}
}
