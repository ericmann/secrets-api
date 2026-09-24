<?php
/**
 * AWS KMS keyring — a `wp-content/secrets.php` drop-in.
 *
 * Copy this file to wp-content/secrets.php and define four constants in
 * wp-config.php (see the README next to this file). No Composer, no AWS SDK: the
 * whole thing is one SigV4 signature and wp_remote_post(), for the same reason as
 * the AWS Secrets Manager example -- a drop-in that drags in a 100MB SDK is a
 * drop-in nobody audits.
 *
 * ## Why a keyring and not a provider
 *
 * KMS holds *keys*, not secrets: it never sees a plaintext value, only 32 bytes
 * of root-key material that WordPress's own envelope encryption derives from.
 * That is WP_Secrets_Keyring, three methods, not WP_Secrets_Provider. (If you
 * want AWS to hold the secret itself, see ../aws-secrets-manager/secrets.php.)
 *
 * ## The three questions this example exists to answer
 *
 * 1. How often does WordPress call unwrap()? Once per request at most, now that
 *    WP_Secrets_Key_Manager caches the unwrapped root key for the life of the
 *    request (see docs/decisions/0009-root-key-cached-for-the-request.md).
 * 2. How does an existing site move onto a new keyring? `wp secret rotate
 *    --from=config`, which unwraps with the site's current config key and
 *    re-wraps with whatever keyring is now active -- this one, once installed.
 * 3. What must a keyring guarantee that the interface does not say? That
 *    wrap() is non-deterministic (two calls on the same bytes must not return
 *    the same wrapped value) and that unwrap() fails closed on anything it did
 *    not produce, rather than returning a plausible-looking wrong key.
 *
 * @package SecretsAPI\Examples
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps and unwraps the site's root key using an AWS KMS customer master key.
 */
final class AWS_KMS_Keyring implements WP_Secrets_Keyring {

	/**
	 * Marks a wrapped value as ours. unwrap() uses its absence to give a
	 * specific, actionable error instead of an opaque KMS exception when a
	 * site adopts this keyring over an existing config-keyring root key.
	 */
	const PREFIX = 'kms1:';

	/**
	 * KMS authenticates this the way an AEAD cipher authenticates AAD: fixed,
	 * not per-site. Binding it to something like home_url() would make a
	 * domain change unrecoverable, and there is exactly one root key per
	 * install, so there is nothing per-site to bind it to.
	 */
	const ENCRYPTION_CONTEXT = array( 'wp-secrets' => 'root-key-v1' );

	/**
	 * Seconds. Every secret operation waits on this call, so a KMS outage
	 * that does not answer within it turns every read into a WP_Error rather
	 * than hanging the request -- fail closed, on purpose. The README repeats
	 * this.
	 */
	const TIMEOUT = 3;

	/** Root key material is always exactly this many bytes. */
	const KEY_LENGTH = 32;

	/** @var string */
	private $key_id;

	/** @var string */
	private $region;

	/** @var string */
	private $access_key;

	/** @var string */
	private $secret_key;

	/**
	 * Emulator endpoint, e.g. Moto's http://host.docker.internal:5051. Empty in
	 * production: real KMS is always reached at its regional host.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * @param string $key_id     KMS key id or ARN.
	 * @param string $region     AWS region, e.g. 'us-east-1'.
	 * @param string $access_key Access key id.
	 * @param string $secret_key Secret access key.
	 * @param string $endpoint   Emulator endpoint override, e.g. Moto. Never set
	 *                           in production; leave empty to reach real AWS.
	 */
	public function __construct( $key_id, $region, $access_key, $secret_key, $endpoint = '' ) {
		$this->key_id     = $key_id;
		$this->region     = $region;
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->endpoint   = $endpoint;
	}

	// -- the keyring contract ----------------------------------------------

	/**
	 * @param string $key_material Raw key material to protect.
	 *
	 * @return string|WP_Error
	 */
	public function wrap( $key_material ) {
		if ( ! is_string( $key_material ) || '' === $key_material ) {
			return new WP_Error( WP_SECRETS_ERROR_INVALID_VALUE, 'AWS_KMS_Keyring: key material must be a non-empty string.' );
		}

		$response = $this->call(
			'Encrypt',
			array(
				'KeyId'             => $this->key_id,
				'Plaintext'         => base64_encode( $key_material ),
				'EncryptionContext' => self::ENCRYPTION_CONTEXT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['CiphertextBlob'] ) ) {
			return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'AWS KMS: Encrypt response had no CiphertextBlob.' );
		}

		// The blob is already base64 in the JSON response; stored as is,
		// behind the prefix that marks it as ours.
		return self::PREFIX . $response['CiphertextBlob'];
	}

	/**
	 * @param string $wrapped An opaque value previously returned by wrap().
	 *
	 * @return string|WP_Error
	 */
	public function unwrap( $wrapped ) {
		if ( ! is_string( $wrapped ) || '' === $wrapped || 0 !== strpos( $wrapped, self::PREFIX ) ) {
			// The most likely adoption failure -- a root key wrapped by the
			// config keyring -- turned into a specific, actionable error
			// instead of an opaque InvalidCiphertextException from KMS.
			return new WP_Error(
				WP_SECRETS_ERROR_KEY_UNAVAILABLE,
				'The stored root key was not wrapped by AWS KMS (no kms1: prefix), so it was probably wrapped by the config keyring. Run `wp secret rotate --from=config` to move it onto this KMS key.'
			);
		}

		$blob = substr( $wrapped, strlen( self::PREFIX ) );

		$response = $this->call(
			'Decrypt',
			array(
				// Pinned on Decrypt: without it, KMS decrypts with whichever
				// key the blob names, and a swapped blob under a key this
				// IAM role can also use would otherwise succeed.
				'KeyId'             => $this->key_id,
				'CiphertextBlob'    => $blob,
				'EncryptionContext' => self::ENCRYPTION_CONTEXT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$plaintext = isset( $response['Plaintext'] ) ? base64_decode( $response['Plaintext'], true ) : false;

		if ( false === $plaintext || self::KEY_LENGTH !== strlen( $plaintext ) ) {
			return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'AWS KMS: Decrypt did not return exactly 32 bytes of key material.' );
		}

		return $plaintext;
	}

	/**
	 * @return string
	 */
	public function get_key_source() {
		return sprintf( 'AWS KMS key %s in %s', $this->key_id, $this->region );
	}

	// -- internals -----------------------------------------------------------

	/**
	 * Signs and sends one KMS API call.
	 *
	 * AWS Signature Version 4, by hand, copied from the Secrets Manager
	 * example rather than shared: each example has to be one file a reviewer
	 * can read from top to bottom.
	 *
	 * @param string $target  API action, e.g. 'Encrypt'.
	 * @param array  $payload Request body.
	 *
	 * @return array|WP_Error Decoded response, or WP_Error.
	 */
	private function call( $target, array $payload ) {
		$service    = 'kms';
		$host       = "kms.{$this->region}.amazonaws.com";
		$url        = "https://{$host}/";
		$body       = wp_json_encode( $payload );
		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$datestamp  = gmdate( 'Ymd' );
		$amz_target = "TrentService.{$target}";

		/*
		 * An emulator (Moto) is reached at its own host and port instead of the
		 * real regional endpoint. The signed "host" header has to match exactly
		 * what wp_remote_post() actually sends -- derived from the URL, the same
		 * way WP_Http itself would -- or the emulator's own signature check fails.
		 */
		if ( '' !== $this->endpoint ) {
			$url         = rtrim( $this->endpoint, '/' ) . '/';
			$parsed      = wp_parse_url( $url );
			$signed_host = isset( $parsed['host'] ) ? $parsed['host'] : $host;

			if ( isset( $parsed['port'] ) ) {
				$signed_host .= ':' . $parsed['port'];
			}
		} else {
			$signed_host = $host;
		}

		$canonical_headers = "content-type:application/x-amz-json-1.1\n"
			. "host:{$signed_host}\n"
			. "x-amz-date:{$amz_date}\n"
			. "x-amz-target:{$amz_target}\n";
		$signed_headers = 'content-type;host;x-amz-date;x-amz-target';

		$canonical_request = "POST\n/\n\n{$canonical_headers}\n{$signed_headers}\n" . hash( 'sha256', $body );

		$scope          = "{$datestamp}/{$this->region}/{$service}/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$response = wp_remote_post(
			$url,
			array(
				// Fail closed: a stuck KMS call must not hang the request
				// that is waiting on the root key.
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/x-amz-json-1.1',
					'X-Amz-Date'    => $amz_date,
					'X-Amz-Target'  => $amz_target,
					'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope}, "
						. "SignedHeaders={$signed_headers}, Signature={$signature}",
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				WP_SECRETS_ERROR_KEY_UNAVAILABLE,
				sprintf( 'AWS KMS unreachable: %s', $response->get_error_message() )
			);
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return is_array( $parsed ) ? $parsed : array();
		}

		$aws_error = isset( $parsed['__type'] ) ? $parsed['__type'] : '';

		/*
		 * AWS's JSON protocol is inconsistent about the case of this key, and
		 * reading only one spelling turns a precise error into a bare
		 * exception name. The request/response body is never echoed here --
		 * only the __type and message fields, never the raw body, which could
		 * echo a plaintext on a malformed-request response.
		 */
		$detail = '';

		foreach ( array( 'message', 'Message' ) as $key ) {
			if ( ! empty( $parsed[ $key ] ) ) {
				$detail = $parsed[ $key ];
				break;
			}
		}

		return new WP_Error(
			WP_SECRETS_ERROR_KEY_UNAVAILABLE,
			sprintf( 'AWS KMS error (HTTP %d): %s -- %s', $code, $aws_error, $detail )
		);
	}
}

/*
 * Install it, but only with all four settings actually filled in.
 *
 * Checked for emptiness rather than just defined(): a config file with the
 * keys present but blank -- the state a freshly-copied override file is in --
 * would otherwise install a keyring that fails every single call. Falling
 * back to WordPress's own keyring means an unpopulated config is just a
 * normal site.
 */
if ( defined( 'WP_SECRETS_KMS_KEY_ID' ) && defined( 'WP_SECRETS_AWS_REGION' )
	&& defined( 'WP_SECRETS_AWS_KEY' ) && defined( 'WP_SECRETS_AWS_SECRET' )
	&& '' !== trim( (string) WP_SECRETS_KMS_KEY_ID )
	&& '' !== trim( (string) WP_SECRETS_AWS_REGION )
	&& '' !== trim( (string) WP_SECRETS_AWS_KEY )
	&& '' !== trim( (string) WP_SECRETS_AWS_SECRET )
) {
	$GLOBALS['wp_secrets_keyring'] = new AWS_KMS_Keyring(
		WP_SECRETS_KMS_KEY_ID,
		WP_SECRETS_AWS_REGION,
		WP_SECRETS_AWS_KEY,
		WP_SECRETS_AWS_SECRET,
		// For an emulator such as Moto during development. Never set in production.
		defined( 'WP_SECRETS_AWS_ENDPOINT' ) ? (string) WP_SECRETS_AWS_ENDPOINT : ''
	);
}
