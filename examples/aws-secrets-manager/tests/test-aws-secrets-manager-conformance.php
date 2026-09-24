<?php
/**
 * Runs the provider conformance suite against AWS_Secrets_Manager_Provider on
 * Moto, an AWS emulator, rather than real AWS. See ../README.md "Run it against
 * an emulator" for how to start Moto.
 *
 * @group secrets-examples
 */
class Tests_AWS_Secrets_Manager_Conformance extends WP_Secrets_Provider_Conformance {

	/**
	 * One name reused across every test in a run, rather than a fresh one per
	 * test. Moto, like real Secrets Manager, keeps AWSPREVIOUS around between
	 * calls on the same name, and the conformance suite's PREVIOUS-related tests
	 * depend on that history existing on the name they read.
	 *
	 * @var string
	 */
	private $subject;

	public function set_up() {
		parent::set_up();

		$this->subject = 'conformance/s' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	public function tear_down() {
		$provider = $this->provider();

		foreach ( array( $this->subject, 'conformance-a/one', 'conformance-b/two' ) as $name ) {
			$provider->delete( $name );
		}

		parent::tear_down();
	}

	protected function provider() {
		return new AWS_Secrets_Manager_Provider( 'us-east-1', 'testing', 'testing', $this->endpoint() );
	}

	protected function conformance_name() {
		return $this->subject;
	}

	/**
	 * @return string
	 */
	private function endpoint() {
		$endpoint = getenv( 'WP_SECRETS_TEST_AWS_ENDPOINT' );

		return false !== $endpoint && '' !== $endpoint ? $endpoint : 'http://host.docker.internal:5051';
	}

	/**
	 * The install block at the bottom of secrets.php is guarded on wp-config.php
	 * constants that tests/bootstrap-examples.php never defines, so requiring the
	 * file to get the class declaration must not also install a provider.
	 */
	public function test_loading_the_example_does_not_install_a_provider_without_the_constants() {
		$this->assertArrayNotHasKey( 'wp_secrets_provider', $GLOBALS );
	}
}
