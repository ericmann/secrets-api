<?php
/**
 * Runs the keyring conformance suite against AWS_KMS_Keyring on Moto, an AWS
 * emulator, rather than real AWS. See ../README.md "Run it against an
 * emulator" for how to start Moto.
 *
 * @group secrets-examples
 */

require_once __DIR__ . '/class-moto-kms-fixture.php';

class Tests_AWS_KMS_Keyring_Conformance extends WP_Secrets_Keyring_Conformance {

	/**
	 * One key, created once for the whole run rather than per test -- KMS
	 * (and Moto) key creation is comparatively expensive, and nothing in the
	 * conformance suite depends on a fresh key per test.
	 *
	 * @var string
	 */
	private static $key_id;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$key_id = Moto_KMS_Fixture::create_key();
	}

	protected function keyring() {
		return new AWS_KMS_Keyring(
			self::$key_id,
			Moto_KMS_Fixture::region(),
			'testing',
			'testing',
			Moto_KMS_Fixture::endpoint()
		);
	}

	/**
	 * The install block at the bottom of secrets.php is guarded on wp-config.php
	 * constants that tests/bootstrap-examples.php never defines, so requiring the
	 * file to get the class declaration must not also install a keyring.
	 */
	public function test_loading_the_example_does_not_install_a_keyring_without_the_constants() {
		$this->assertArrayNotHasKey( 'wp_secrets_keyring', $GLOBALS );
	}
}
