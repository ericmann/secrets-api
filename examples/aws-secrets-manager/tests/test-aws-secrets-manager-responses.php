<?php
/**
 * Offline tests for how AWS_Secrets_Manager_Provider reads Secrets Manager's
 * responses. Every request is faked through pre_http_request; nothing here
 * touches AWS. Both cases were found against live AWS, where Moto behaves
 * differently.
 *
 * @package SecretsAPI\Examples
 */

class Tests_AWS_Secrets_Manager_Responses extends WP_UnitTestCase {

	/** @var array */
	private $queue = array();

	public function set_up() {
		parent::set_up();

		$this->queue = array();

		add_filter( 'pre_http_request', array( $this, 'fake_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_request' ), 10 );

		parent::tear_down();
	}

	public function fake_request( $preempt, $parsed_args, $url ) {
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

	public function test_listing_reports_has_previous_from_the_version_stages() {
		$this->queue_response(
			200,
			array(
				'SecretList' => array(
					array(
						'Name'                   => 'wp/site/1/acme/rotated',
						'SecretVersionsToStages' => array(
							'v2' => array( 'AWSCURRENT' ),
							'v1' => array( 'AWSPREVIOUS' ),
						),
					),
					array(
						'Name'                   => 'wp/site/1/acme/fresh',
						'SecretVersionsToStages' => array(
							'v1' => array( 'AWSCURRENT' ),
						),
					),
					array( 'Name' => 'wp/site/1/acme/no-stages' ),
				),
			)
		);

		$entries = array_column( $this->provider()->list_secrets(), 'has_previous', 'name' );

		$this->assertSame(
			array(
				'acme/rotated'   => true,
				'acme/fresh'     => false,
				'acme/no-stages' => false,
			),
			$entries
		);
	}

	public function test_a_secret_marked_for_deletion_reads_as_absent() {
		$this->queue_response(
			400,
			array(
				'__type'  => 'InvalidRequestException',
				'Message' => "You can't perform this operation on the secret because it was marked for deletion.",
			)
		);

		$this->assertNull( $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT ) );
	}

	public function test_deleting_a_secret_already_marked_for_deletion_succeeds() {
		$this->queue_response(
			400,
			array(
				'__type'  => 'InvalidRequestException',
				'message' => 'You tried to perform the operation on a secret that is currently marked for deletion.',
			)
		);

		$this->assertTrue( $this->provider()->delete( 'acme/key' ) );
	}

	public function test_any_other_invalid_request_is_still_an_error() {
		$this->queue_response(
			400,
			array(
				'__type'  => 'InvalidRequestException',
				'Message' => 'You must provide a ClientRequestToken.',
			)
		);

		$result = $this->provider()->get( 'acme/key', WP_Secret_Version::CURRENT );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
	}
}
