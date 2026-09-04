<?php
/**
 * AWS Secrets Manager provider — a `wp-content/secrets.php` drop-in.
 *
 * Copy this file to wp-content/secrets.php and define four constants in
 * wp-config.php (see the README next to this file). No Composer, no AWS SDK: the
 * whole thing is one SigV4 signature and wp_remote_post(), which is deliberate --
 * a drop-in that drags in a 100MB SDK is a drop-in nobody audits.
 *
 * ## Why a provider and not a store or keyring
 *
 * Secrets Manager holds the *secret*, so WordPress is a consumer rather than a
 * custodian: WP_Secrets_Provider, and get_protection_boundary() reports
 * BOUNDARY_PROVIDER. (If you want AWS KMS instead, that holds *keys*, and the
 * right seam is WP_Secrets_Keyring -- three methods, and WordPress keeps its own
 * envelope. See ../README.md.)
 *
 * ## The part that is a happy accident
 *
 * Secrets Manager tracks versions with staging labels, and two of them are
 * AWSCURRENT and AWSPREVIOUS. That is precisely WP_Secret_Version::CURRENT and
 * ::PREVIOUS. The two-slot rotation model in the Secrets API is not a WordPress
 * invention; it is the shape this problem already has.
 *
 * @package SecretsAPI\Examples
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves secrets from AWS Secrets Manager.
 */
final class AWS_Secrets_Manager_Provider implements WP_Secrets_Provider {

	/**
	 * Maps this API's version constants onto Secrets Manager staging labels.
	 *
	 * @var array<string, string>
	 */
	const STAGES = array(
		WP_Secret_Version::CURRENT  => 'AWSCURRENT',
		WP_Secret_Version::PREVIOUS => 'AWSPREVIOUS',
	);

	/** @var string */
	private $region;

	/** @var string */
	private $access_key;

	/** @var string */
	private $secret_key;

	/**
	 * Request-scoped only. Never the persistent object cache: WP_Secret
	 * deliberately cannot round-trip a plaintext through wp_cache_set(), and
	 * caching the raw value beside it would quietly undo that.
	 *
	 * @var array<string, string>
	 */
	private $memo = array();

	/**
	 * @param string $region     AWS region, e.g. 'us-east-1'.
	 * @param string $access_key Access key id.
	 * @param string $secret_key Secret access key.
	 */
	public function __construct( $region, $access_key, $secret_key ) {
		$this->region     = $region;
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
	}

	// -- the provider contract -------------------------------------------------

	/**
	 * @param string $name    Secret name.
	 * @param string $version A WP_Secret_Version constant.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Secret|null|WP_Error
	 */
	public function get( $name, $version, $network = false ) {
		$stage = isset( self::STAGES[ $version ] ) ? self::STAGES[ $version ] : 'AWSCURRENT';
		$key   = $this->aws_name( $name, $network ) . '@' . $stage;

		if ( ! isset( $this->memo[ $key ] ) ) {
			$response = $this->call(
				'GetSecretValue',
				array(
					'SecretId'     => $this->aws_name( $name, $network ),
					'VersionStage' => $stage,
				)
			);

			/*
			 * Absence is null; anything else is an error. A secret with no
			 * AWSPREVIOUS yet also lands here as ResourceNotFound, which is
			 * correct: "no previous version" is absence, not a failure.
			 */
			if ( is_wp_error( $response ) ) {
				return 'aws_not_found' === $response->get_error_code() ? null : $response;
			}

			if ( ! isset( $response['SecretString'] ) ) {
				return new WP_Error(
					WP_SECRETS_ERROR_RECORD_MALFORMED,
					__( 'Secrets Manager returned a binary secret; this provider stores strings.', 'default' )
				);
			}

			$this->memo[ $key ] = $response['SecretString'];
		}

		return $this->build_secret( $name, $this->memo[ $key ] );
	}

	/**
	 * @param string      $name           Secret name.
	 * @param string      $value          Plaintext value.
	 * @param bool        $network        Whether this is a network-scope secret.
	 * @param bool        $needs_rotation Ignored: AWS tracks rotation itself.
	 * @param string|null $action         Action reported to wp_secret_changed.
	 *
	 * @return true|WP_Error
	 */
	public function set( $name, $value, $network = false, $needs_rotation = false, $action = null ) {
		$aws_name = $this->aws_name( $name, $network );

		// PutSecretValue rotates AWSCURRENT -> AWSPREVIOUS for us, which is the
		// whole two-slot model handled server-side.
		$result = $this->call(
			'PutSecretValue',
			array(
				'SecretId'           => $aws_name,
				'SecretString'       => $value,
				'ClientRequestToken' => wp_generate_uuid4(),
			)
		);

		$created = false;

		if ( is_wp_error( $result ) && 'aws_not_found' === $result->get_error_code() ) {
			$result  = $this->call(
				'CreateSecret',
				array(
					'Name'               => $aws_name,
					'SecretString'       => $value,
					'ClientRequestToken' => wp_generate_uuid4(),
				)
			);
			$created = true;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->memo = array();

		/**
		 * Fires whenever a secret is created, updated, deleted, or imported.
		 *
		 * Providers own firing this -- see WP_Secrets_Provider::set().
		 */
		do_action(
			'wp_secret_changed',
			$name,
			null === $action ? ( $created ? 'created' : 'updated' ) : $action,
			get_current_user_id(),
			time(),
			'',
			''
		);

		return true;
	}

	/**
	 * @param string $name    Secret name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function delete( $name, $network = false ) {
		$result = $this->call(
			'DeleteSecret',
			array(
				'SecretId'                   => $this->aws_name( $name, $network ),
				'ForceDeleteWithoutRecovery' => true,
			)
		);

		// Deleting something already absent is the state the caller asked for.
		if ( is_wp_error( $result ) && 'aws_not_found' === $result->get_error_code() ) {
			return true;
		}

		$this->memo = array();

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * A successful no-op: Secrets Manager manages staging labels itself, so there
	 * is no separate "previous slot" for WordPress to clear. Reporting success is
	 * honest -- the caller wanted no previous version exposed, and there is none
	 * this provider controls.
	 *
	 * @param string $name    Secret name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true
	 */
	public function retire_previous( $name, $network = false ) {
		return true;
	}

	/**
	 * @param string $name_prefix Restrict to names beginning with this prefix.
	 * @param bool   $network     Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_secrets( $name_prefix = '', $network = false ) {
		$response = $this->call( 'ListSecrets', array( 'MaxResults' => 100 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$prefix  = $this->aws_name( $name_prefix, $network );
		$entries = array();

		foreach ( isset( $response['SecretList'] ) ? $response['SecretList'] : array() as $item ) {
			$aws_name = isset( $item['Name'] ) ? $item['Name'] : '';
			$wp_name  = $this->wp_name( $aws_name, $network );

			if ( null === $wp_name ) {
				continue;
			}

			if ( '' !== $name_prefix && 0 !== strpos( $aws_name, $prefix ) ) {
				continue;
			}

			$entries[] = array(
				'name' => $wp_name,
				// Fingerprinting every entry would mean a GetSecretValue call per
				// secret. Left empty rather than faked; wp secret get reports the
				// real one for a single secret.
				'fingerprint'    => '',
				'created'        => isset( $item['CreatedDate'] ) ? (int) $item['CreatedDate'] : 0,
				'has_previous'   => false,
				'needs_rotation' => false,
			);
		}

		return $entries;
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return sprintf( 'AWS Secrets Manager (%s)', $this->region );
	}

	/**
	 * @return string
	 */
	public function get_protection_boundary() {
		return self::BOUNDARY_PROVIDER;
	}

	/**
	 * @return bool
	 */
	public function is_writable() {
		return true;
	}

	// -- internals -------------------------------------------------------------

	/**
	 * Wraps a plaintext into a WP_Secret, fingerprinted with this site's own
	 * master key so fingerprints stay comparable with every other provider.
	 *
	 * @param string $name  Secret name.
	 * @param string $value Plaintext.
	 *
	 * @return WP_Secret|WP_Error
	 */
	private function build_secret( $name, $value ) {
		$master_key = _wp_secrets_get_key_manager()->get_master_key( 'site' );

		if ( is_wp_error( $master_key ) ) {
			return $master_key;
		}

		$fingerprint = ( new WP_Secrets_Cipher() )->fingerprint( $master_key, $value );

		wp_secrets_memzero( $master_key );

		if ( is_wp_error( $fingerprint ) ) {
			return $fingerprint;
		}

		return new WP_Secret( $name, $value, $fingerprint );
	}

	/**
	 * Secrets Manager names allow alphanumerics and /_+=.@- so a namespaced
	 * WordPress name maps across unchanged. Network-scope secrets get a prefix so
	 * they cannot collide with a site-scope secret of the same name.
	 *
	 * @param string $name    WordPress secret name.
	 * @param bool   $network Whether this is network scope.
	 *
	 * @return string
	 */
	private function aws_name( $name, $network ) {
		return ( $network ? 'wp-network/' : 'wp/' ) . $name;
	}

	/**
	 * The inverse, returning null for anything this scope does not own.
	 *
	 * @param string $aws_name Secrets Manager name.
	 * @param bool   $network  Whether this is network scope.
	 *
	 * @return string|null
	 */
	private function wp_name( $aws_name, $network ) {
		$prefix = $network ? 'wp-network/' : 'wp/';

		if ( 0 !== strpos( $aws_name, $prefix ) ) {
			return null;
		}

		return substr( $aws_name, strlen( $prefix ) );
	}

	/**
	 * Signs and sends one Secrets Manager API call.
	 *
	 * AWS Signature Version 4, by hand. It looks like a lot of string building
	 * because it is, but there is no cleverness in it: build a canonical request,
	 * hash it, sign the hash with a key derived from date/region/service, and put
	 * the result in an Authorization header.
	 *
	 * Note that write actions need a ClientRequestToken. AWS documents it as
	 * optional, which is true only because every SDK generates one for you --
	 * calling the API directly, its absence is a flat InvalidRequestException. It
	 * is an idempotency token, so each call gets a fresh UUID.
	 *
	 * @param string $target  API action, e.g. 'GetSecretValue'.
	 * @param array  $payload Request body.
	 *
	 * @return array|WP_Error Decoded response, or WP_Error. A missing secret
	 *                         reports code 'aws_not_found' so callers can tell
	 *                         absence from failure.
	 */
	private function call( $target, array $payload ) {
		$service   = 'secretsmanager';
		$host      = "secretsmanager.{$this->region}.amazonaws.com";
		$body      = wp_json_encode( $payload );
		$amz_date  = gmdate( 'Ymd\THis\Z' );
		$datestamp = gmdate( 'Ymd' );
		$amz_target = "secretsmanager.{$target}";

		$canonical_headers = "content-type:application/x-amz-json-1.1\n"
			. "host:{$host}\n"
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
			"https://{$host}/",
			array(
				'timeout' => 10,
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
				WP_SECRETS_ERROR_STORE_UNAVAILABLE,
				sprintf( 'Secrets Manager unreachable: %s', $response->get_error_message() )
			);
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return is_array( $parsed ) ? $parsed : array();
		}

		$aws_error = isset( $parsed['__type'] ) ? $parsed['__type'] : '';

		if ( false !== strpos( $aws_error, 'ResourceNotFoundException' ) ) {
			return new WP_Error( 'aws_not_found', 'No such secret.' );
		}

		/*
		 * AWS's JSON protocol is inconsistent about the case of this key, and
		 * reading only one spelling turns a precise error into a bare exception
		 * name -- which is exactly how much use "InvalidRequestException" on its
		 * own is when you are trying to find out what was invalid.
		 */
		$detail = '';

		foreach ( array( 'message', 'Message' ) as $key ) {
			if ( ! empty( $parsed[ $key ] ) ) {
				$detail = $parsed[ $key ];
				break;
			}
		}

		if ( '' === $detail ) {
			$detail = wp_remote_retrieve_body( $response );
		}

		return new WP_Error(
			WP_SECRETS_ERROR_STORE_UNAVAILABLE,
			sprintf( 'Secrets Manager error (HTTP %d): %s -- %s', $code, $aws_error, $detail )
		);
	}
}

/*
 * Install it, but only with all three credentials actually filled in.
 *
 * Checked for emptiness rather than just defined(): a config file with the keys
 * present but blank -- the state a freshly-copied override file is in -- would
 * otherwise install a provider that fails every single call. Falling back to
 * WordPress's own provider means an unpopulated config is just a normal site.
 */
if ( defined( 'WP_SECRETS_AWS_REGION' ) && defined( 'WP_SECRETS_AWS_KEY' ) && defined( 'WP_SECRETS_AWS_SECRET' )
	&& '' !== trim( (string) WP_SECRETS_AWS_REGION )
	&& '' !== trim( (string) WP_SECRETS_AWS_KEY )
	&& '' !== trim( (string) WP_SECRETS_AWS_SECRET )
) {
	$GLOBALS['wp_secrets_provider'] = new AWS_Secrets_Manager_Provider(
		WP_SECRETS_AWS_REGION,
		WP_SECRETS_AWS_KEY,
		WP_SECRETS_AWS_SECRET
	);
}
