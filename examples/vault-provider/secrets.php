<?php
/**
 * HashiCorp Vault KV v2 provider — a `wp-content/secrets.php` drop-in.
 *
 * Copy this file to wp-content/secrets.php and define four constants in
 * wp-config.php (see the README next to this file). No Composer, no Vault SDK:
 * the whole thing is wp_remote_request() against Vault's KV v2 HTTP API --
 * a drop-in that drags in a dependency tree is a drop-in nobody audits.
 *
 * Why a provider, not a store or keyring: Vault holds the *secret*, so
 * WordPress is a consumer rather than a custodian -- WP_Secrets_Provider, and
 * get_protection_boundary() reports BOUNDARY_PROVIDER.
 *
 * The part that is a translation: KV v2 keeps an integer version history
 * (1, 2, 3, ...), not the two named slots this API exposes. This provider
 * makes Vault a two-slot store by setting `max_versions: 2` when it creates a
 * secret and by defining "previous" as strictly version N-1 -- never an older
 * survivor. See ../README.md for the four questions this translation answers.
 *
 * A secret's data and its metadata (max_versions, custom_metadata) are two
 * separate Vault requests, not a transaction: a write can succeed on one and
 * fail on the other, and each method below says what it does when that
 * happens.
 *
 * Fingerprints still need this site's own root key material even though the
 * protection boundary is Vault: the fingerprint proves "this plaintext is the
 * same one WordPress last saw," a WordPress-side question regardless of where
 * the plaintext itself is protected.
 *
 * @package SecretsAPI\Examples
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves secrets from a HashiCorp Vault KV v2 secrets engine.
 */
final class Vault_KV2_Provider implements WP_Secrets_Provider {

	/** Versions kept per secret in Vault; makes it a two-slot store. See README.md question 2. @var int */
	const MAX_VERSIONS = 2;

	/**
	 * ⚠️ ASSUMPTION: seconds to wait for a Vault response -- long enough for a
	 * cold TLS handshake to a remote Vault, short enough that an outage fails a
	 * page in seconds rather than tying up PHP workers. Measured in P4-02.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 5;

	/** The custom_metadata key the rotation flag is stored under. @var string */
	const ROTATION_FLAG = 'needs_rotation';

	/** @var string */
	private $addr;

	/** @var string */
	private $token;

	/** @var string */
	private $mount;

	/** @var string */
	private $namespace;

	/**
	 * Request-scoped only. Never the persistent object cache: WP_Secret
	 * deliberately cannot round-trip a plaintext through wp_cache_set(), and
	 * caching the raw value beside it would quietly undo that.
	 *
	 * @var array<string, string>
	 */
	private $memo = array();

	/**
	 * @param string $addr      Vault address, e.g. 'https://vault.example.com:8200'.
	 * @param string $token     Vault token.
	 * @param string $mount     KV v2 mount point. Default 'secret'.
	 * @param string $namespace Vault Enterprise namespace, or '' for none.
	 */
	public function __construct( $addr, $token, $mount = 'secret', $namespace = '' ) {
		$this->addr      = rtrim( $addr, '/' );
		$this->token     = $token;
		$this->mount     = trim( $mount, '/' );
		$this->namespace = $namespace;
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
		$vault_path = $this->vault_path( $name, $network );
		$memo_key   = $vault_path . '#' . $version;

		if ( isset( $this->memo[ $memo_key ] ) ) {
			return $this->build_secret( $name, $this->memo[ $memo_key ], $network );
		}

		if ( WP_Secret_Version::PREVIOUS === $version ) {
			$meta = $this->read_metadata( $name, $network );

			if ( is_wp_error( $meta ) ) {
				return $meta;
			}

			if ( null === $meta ) {
				return null;
			}

			$previous = $this->previous_version( $meta );

			if ( null === $previous ) {
				return null;
			}

			$data = $this->request( 'GET', $this->url( 'data', $vault_path, array( 'version' => $previous ) ) );
		} else {
			$data = $this->request( 'GET', $this->url( 'data', $vault_path ) );
		}

		if ( is_wp_error( $data ) || null === $data ) {
			return $data;
		}

		if ( ! isset( $data['data']['value'] ) || ! is_string( $data['data']['value'] ) ) {
			return new WP_Error(
				WP_SECRETS_ERROR_RECORD_MALFORMED,
				'Vault returned a secret without a string "value" field.'
			);
		}

		$this->memo[ $memo_key ] = $data['data']['value'];

		return $this->build_secret( $name, $data['data']['value'], $network );
	}

	/**
	 * Creates or updates a secret. On first write, max_versions is set to
	 * self::MAX_VERSIONS before the value is written, so Vault is a two-slot
	 * store from its very first version. A secret created outside this
	 * provider keeps whatever max_versions it already has -- see ADR 0009.
	 * The rotation flag is written in a separate metadata request (P4-01).
	 *
	 * @param string      $name           Secret name.
	 * @param string      $value          Plaintext value.
	 * @param bool        $network        Whether this is a network-scope secret.
	 * @param bool        $needs_rotation Mark the stored secret as needing rotation.
	 * @param string|null $action         Overrides the action reported to wp_secret_changed.
	 *
	 * @return true|WP_Error
	 */
	public function set( $name, $value, $network = false, $needs_rotation = false, $action = null ) {
		$vault_path = $this->vault_path( $name, $network );
		$meta       = $this->read_metadata( $name, $network );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$created = ( null === $meta );

		if ( $created ) {
			$result = $this->request(
				'POST',
				$this->url( 'metadata', $vault_path ),
				array( 'max_versions' => self::MAX_VERSIONS )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$result = $this->request( 'POST', $this->url( 'data', $vault_path ), array( 'data' => array( 'value' => $value ) ) );

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
			null !== $action ? $action : ( $created ? 'created' : 'updated' ),
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
		$vault_path = $this->vault_path( $name, $network );
		$result     = $this->request( 'DELETE', $this->url( 'metadata', $vault_path ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->memo = array();

		/**
		 * Fires whenever a secret is created, updated, deleted, or imported.
		 *
		 * Providers own firing this -- see WP_Secrets_Provider::set().
		 */
		do_action( 'wp_secret_changed', $name, 'deleted', get_current_user_id(), time(), '', '' );

		return true;
	}

	/**
	 * Destroys the secret's version N-1, so a retired value can never be
	 * un-deleted. Completed in P2-02.
	 *
	 * @param string $name    Secret name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return true|WP_Error
	 */
	public function retire_previous( $name, $network = false ) {
		$vault_path = $this->vault_path( $name, $network );
		$meta       = $this->read_metadata( $name, $network );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( null === $meta ) {
			return true;
		}

		$previous = $this->previous_version( $meta );

		if ( null === $previous ) {
			return true;
		}

		$result = $this->request( 'POST', $this->url( 'destroy', $vault_path ), array( 'versions' => array( $previous ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->memo = array();

		do_action( 'wp_secret_changed', $name, 'retired', get_current_user_id(), time(), '', '' );

		return true;
	}

	/**
	 * Lists secret names and metadata under a namespace, never values.
	 * Completed in P2-02 and P4-01.
	 *
	 * @param string $name_prefix Restrict to names beginning with this prefix.
	 * @param bool   $network     Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_secrets( $name_prefix = '', $network = false ) {
		$base = $this->scope_prefix( $network );

		if ( '' !== $name_prefix ) {
			$namespaces = array( $name_prefix );
		} else {
			$keys = $this->list_keys( $this->url( 'metadata', $base, array( 'list' => 'true' ) ) );

			if ( is_wp_error( $keys ) ) {
				return $keys;
			}

			if ( null === $keys ) {
				return array();
			}

			$namespaces = array();

			foreach ( $keys as $key ) {
				if ( '/' === substr( $key, -1 ) ) {
					$namespaces[] = rtrim( $key, '/' );
				}
			}
		}

		$entries = array();

		foreach ( $namespaces as $ns ) {
			$keys = $this->list_keys( $this->url( 'metadata', "{$base}{$ns}/", array( 'list' => 'true' ) ) );

			if ( is_wp_error( $keys ) ) {
				return $keys;
			}

			if ( null === $keys ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( '/' === substr( $key, -1 ) ) {
					continue;
				}

				$entries[] = array(
					'name'           => "{$ns}/{$key}",
					// One LIST per namespace and no data reads: fingerprinting every
					// entry would mean a read per secret. See README.md question 4.
					'fingerprint'    => '',
					'created'        => 0,
					'has_previous'   => false,
					'needs_rotation' => false,
				);
			}
		}

		return $entries;
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return sprintf( 'HashiCorp Vault (%s, mount %s)', $this->addr, $this->mount );
	}

	/**
	 * @return string
	 */
	public function get_protection_boundary() {
		return self::BOUNDARY_PROVIDER;
	}

	/**
	 * Always true: a token without write policy is not detected in advance,
	 * only surfaced as WP_Error from set() when the write is actually refused.
	 *
	 * @return bool
	 */
	public function is_writable() {
		return true;
	}

	// -- internals -------------------------------------------------------------

	/**
	 * @param bool $network Whether this is a network-scope secret.
	 *
	 * @return string
	 */
	private function scope_prefix( $network ) {
		return $network ? 'wp/network/' : 'wp/site/' . get_current_blog_id() . '/';
	}

	/**
	 * @param string $name    Secret name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return string
	 */
	private function vault_path( $name, $network ) {
		return $this->scope_prefix( $network ) . $name;
	}

	/**
	 * @param string $kind       'data', 'metadata', 'destroy', or 'delete'.
	 * @param string $vault_path Path under the mount.
	 * @param array  $query      Query args.
	 *
	 * @return string
	 */
	private function url( $kind, $vault_path, array $query = array() ) {
		$url = "{$this->addr}/v1/{$this->mount}/{$kind}/{$vault_path}";

		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		return $url;
	}

	/**
	 * Sends one Vault request and maps the response.
	 *
	 * Absence (404) is null. A transport failure or any non-2xx response,
	 * including 403 (permission denied) and 503 (sealed), is WP_Error with code
	 * WP_SECRETS_ERROR_STORE_UNAVAILABLE -- both read as "the store cannot
	 * answer right now," which is the correct state for a caller that must
	 * never confuse "sealed" with "the secret was deleted."
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Full request URL.
	 * @param array|null $body   Request body, encoded as JSON when non-null.
	 *
	 * @return array|null|WP_Error Decoded 'data' array on 2xx (empty array for
	 *                              204), null on 404, WP_Error otherwise.
	 */
	private function request( $method, $url, $body = null ) {
		$headers = array(
			'X-Vault-Token'   => $this->token,
			'X-Vault-Request' => 'true',
			'Content-Type'    => 'application/json',
		);

		if ( '' !== $this->namespace ) {
			$headers['X-Vault-Namespace'] = $this->namespace;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => $headers,
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				WP_SECRETS_ERROR_STORE_UNAVAILABLE,
				sprintf( 'Vault unreachable: %s', $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] )
				? $decoded['data']
				: array();
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$errors   = isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ? $decoded['errors'] : array();
		$detail   = ! empty( $errors ) ? implode( '; ', $errors ) : $raw_body;

		return new WP_Error(
			WP_SECRETS_ERROR_STORE_UNAVAILABLE,
			sprintf( 'Vault error (HTTP %d): %s', $code, $detail )
		);
	}

	/**
	 * @param string $name    Secret name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return array|null|WP_Error
	 */
	private function read_metadata( $name, $network ) {
		return $this->request( 'GET', $this->url( 'metadata', $this->vault_path( $name, $network ) ) );
	}

	/**
	 * Runs a Vault LIST (GET ...?list=true) and returns just the keys.
	 * Isolated so P4-01's per-secret metadata read does not restructure
	 * list_secrets() itself.
	 *
	 * @param string $url Full LIST URL, including ?list=true.
	 *
	 * @return string[]|null|WP_Error
	 */
	private function list_keys( $url ) {
		$result = $this->request( 'GET', $url );

		if ( is_wp_error( $result ) || null === $result ) {
			return $result;
		}

		return isset( $result['keys'] ) && is_array( $result['keys'] ) ? $result['keys'] : array();
	}

	/**
	 * The version this provider calls "previous": strictly N-1, and only when
	 * N-1 is itself readable. Never the newest surviving version below N --
	 * retiring must never resurrect an older version by promoting it into the
	 * previous slot.
	 *
	 * @param array $meta Decoded metadata (the 'data' object from
	 *                    GET secret/metadata/<path>).
	 *
	 * @return int|null
	 */
	private function previous_version( array $meta ) {
		$current = isset( $meta['current_version'] ) ? (int) $meta['current_version'] : 0;

		if ( $current < 2 ) {
			return null;
		}

		$previous = $current - 1;
		$key      = (string) $previous;

		if ( ! isset( $meta['versions'][ $key ] ) ) {
			return null;
		}

		$version_meta = $meta['versions'][ $key ];

		if ( ! empty( $version_meta['deletion_time'] ) || ! empty( $version_meta['destroyed'] ) ) {
			return null;
		}

		return $previous;
	}

	/**
	 * Wraps a plaintext into a WP_Secret, fingerprinted with this site's own
	 * master key so fingerprints stay comparable with every other provider.
	 *
	 * @param string $name    Secret name.
	 * @param string $value   Plaintext.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return WP_Secret|WP_Error
	 */
	private function build_secret( $name, $value, $network ) {
		$master_key = _wp_secrets_get_key_manager()->get_master_key(
			$network ? 'network' : 'site',
			$network ? null : get_current_blog_id()
		);

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
}

/*
 * Install it, but only with the address and token actually filled in.
 *
 * Checked for emptiness rather than just defined(): a config file with the
 * constants present but blank -- the state a freshly-copied override file is
 * in -- would otherwise install a provider that fails every single call.
 * Falling back to WordPress's own provider means an unpopulated config is
 * just a normal site.
 */
if ( defined( 'WP_SECRETS_VAULT_ADDR' ) && defined( 'WP_SECRETS_VAULT_TOKEN' )
	&& '' !== trim( (string) WP_SECRETS_VAULT_ADDR )
	&& '' !== trim( (string) WP_SECRETS_VAULT_TOKEN )
) {
	$mount     = ( defined( 'WP_SECRETS_VAULT_MOUNT' ) && '' !== trim( (string) WP_SECRETS_VAULT_MOUNT ) )
		? WP_SECRETS_VAULT_MOUNT
		: 'secret';
	$namespace = defined( 'WP_SECRETS_VAULT_NAMESPACE' ) ? WP_SECRETS_VAULT_NAMESPACE : '';

	$GLOBALS['wp_secrets_provider'] = new Vault_KV2_Provider(
		WP_SECRETS_VAULT_ADDR,
		WP_SECRETS_VAULT_TOKEN,
		$mount,
		$namespace
	);
}
