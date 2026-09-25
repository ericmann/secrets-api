<?php
/**
 * Creates a KMS key on Moto for the AWS KMS keyring conformance suite.
 *
 * The SigV4 signing block is copied from AWS_KMS_Keyring::call() rather than
 * calling into the example's private method -- the detailed spec accepts
 * copying the signer for readability, and a test fixture reaching into a
 * class's private internals would be worse than a second small copy of it.
 *
 * @package SecretsAPI\Examples
 */

/**
 * Static helpers for standing up a Moto KMS key before the conformance suite
 * runs against it.
 */
final class Moto_KMS_Fixture {

	/**
	 * @return string Moto's endpoint. Overridable so CI can point this at the
	 *                 service container instead of a locally-run Moto.
	 */
	public static function endpoint() {
		$endpoint = getenv( 'WP_SECRETS_TEST_AWS_ENDPOINT' );

		return false !== $endpoint && '' !== $endpoint ? $endpoint : 'http://host.docker.internal:5051';
	}

	/**
	 * @return string
	 */
	public static function region() {
		return 'us-east-1';
	}

	/**
	 * Creates a fresh KMS key on Moto.
	 *
	 * @return string The new key's KeyId.
	 */
	public static function create_key() {
		$response = self::call(
			'CreateKey',
			array( 'Description' => 'wp-secrets examples test key' )
		);

		if ( is_wp_error( $response ) || empty( $response['KeyMetadata']['KeyId'] ) ) {
			$message = is_wp_error( $response ) ? $response->get_error_message() : 'no KeyMetadata.KeyId in the response';

			// The body of a CreateKey call/response contains no secret, so it
			// is safe to fail the test with it.
			self::fail_test( sprintf( 'Moto_KMS_Fixture::create_key() failed: %s', $message ) );
		}

		return $response['KeyMetadata']['KeyId'];
	}

	/**
	 * Fails the currently-running test with a message. Kept as a tiny wrapper
	 * so create_key() reads as "do the call, or fail the test", without a
	 * PHPUnit dependency spread through the rest of the class.
	 *
	 * @param string $message Failure message.
	 *
	 * @return never
	 */
	private static function fail_test( $message ) {
		PHPUnit\Framework\Assert::fail( $message );
	}

	/**
	 * Signs and sends one KMS API call against Moto. Copied from
	 * AWS_KMS_Keyring::call(), fixed to the 'testing'/'testing' credentials
	 * Moto accepts for any request.
	 *
	 * @param string $target  API action, e.g. 'CreateKey'.
	 * @param array  $payload Request body.
	 *
	 * @return array|WP_Error Decoded response, or WP_Error.
	 */
	private static function call( $target, array $payload ) {
		$access_key = 'testing';
		$secret_key = 'testing';
		$region     = self::region();
		$service    = 'kms';
		$url        = rtrim( self::endpoint(), '/' ) . '/';
		$parsed     = wp_parse_url( $url );
		$host       = isset( $parsed['host'] ) ? $parsed['host'] : "kms.{$region}.amazonaws.com";

		if ( isset( $parsed['port'] ) ) {
			$host .= ':' . $parsed['port'];
		}

		$body       = wp_json_encode( $payload );
		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$datestamp  = gmdate( 'Ymd' );
		$amz_target = "TrentService.{$target}";

		$canonical_headers = "content-type:application/x-amz-json-1.1\n"
			. "host:{$host}\n"
			. "x-amz-date:{$amz_date}\n"
			. "x-amz-target:{$amz_target}\n";
		$signed_headers = 'content-type;host;x-amz-date;x-amz-target';

		$canonical_request = "POST\n/\n\n{$canonical_headers}\n{$signed_headers}\n" . hash( 'sha256', $body );

		$scope          = "{$datestamp}/{$region}/{$service}/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'  => 'application/x-amz-json-1.1',
					'X-Amz-Date'    => $amz_date,
					'X-Amz-Target'  => $amz_target,
					'Authorization' => "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope}, "
						. "SignedHeaders={$signed_headers}, Signature={$signature}",
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'moto_kms_fixture_unreachable', $response->get_error_message() );
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return is_array( $parsed ) ? $parsed : array();
		}

		return new WP_Error(
			'moto_kms_fixture_error',
			sprintf( 'Moto KMS error (HTTP %d): %s', $code, wp_remote_retrieve_body( $response ) )
		);
	}
}
