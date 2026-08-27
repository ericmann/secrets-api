<?php
/**
 * @group secrets
 */
class Tests_Secrets_WpSecretsValidateName extends WP_UnitTestCase {

	/**
	 * @dataProvider data_valid_names
	 */
	public function test_accepts_valid_names( $name ) {
		$this->assertTrue( wp_secrets_validate_name( $name ) );
	}

	public function data_valid_names() {
		return array(
			'simple'                      => array( 'myplugin/api-key' ),
			'single character segments'   => array( 'a/b' ),
			'hyphens'                     => array( 'my-plugin/my-secret-key' ),
			'underscores'                 => array( 'my_plugin/my_secret_key' ),
			'digits'                      => array( 'plugin123/key456' ),
			'mixed hyphen and underscore' => array( 'a1-b2_c3/d4-e5_f6' ),
			'consecutive separators'      => array( 'a--b/c__d' ),
			'exactly the max length'      => array( str_repeat( 'a', 85 ) . '/' . str_repeat( 'b', 86 ) ),
		);
	}

	/**
	 * @dataProvider data_invalid_names
	 */
	public function test_rejects_invalid_names( $name ) {
		$result = wp_secrets_validate_name( $name );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_INVALID_NAME, $result->get_error_code() );
	}

	public function data_invalid_names() {
		return array(
			'empty string'                 => array( '' ),
			'no slash'                     => array( 'noslash' ),
			'two slashes'                  => array( 'too/many/slashes' ),
			'empty namespace'              => array( '/key' ),
			'empty key'                    => array( 'namespace/' ),
			'uppercase namespace'          => array( 'MyPlugin/key' ),
			'uppercase key'                => array( 'plugin/MyKey' ),
			'leading hyphen in namespace'  => array( '-plugin/key' ),
			'trailing hyphen in namespace' => array( 'plugin-/key' ),
			'leading underscore in key'    => array( 'plugin/_key' ),
			'trailing underscore in key'   => array( 'plugin/key_' ),
			'space in key'                 => array( 'plugin/key with space' ),
			'dot in key'                   => array( 'plugin/key.name' ),
			'non-ascii'                    => array( 'plügin/key' ),
			'not a string: null'           => array( null ),
			'not a string: int'            => array( 12345 ),
			'not a string: array'          => array( array( 'plugin/key' ) ),
			'not a string: bool'           => array( true ),
			'one character over the max'   => array( str_repeat( 'a', 85 ) . '/' . str_repeat( 'b', 87 ) ),
		);
	}
}
