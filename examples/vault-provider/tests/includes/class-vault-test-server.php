<?php
/**
 * Raw-HTTP test helper for the Vault KV v2 examples suite.
 *
 * Deliberately does not go through Vault_KV2_Provider: these tests need to see and
 * shape Vault's own state (versions, custom_metadata, soft deletes) directly, so the
 * helper speaks the same HTTP shapes the provider does, kept alongside it in
 * docs/PLAN.md's "Vault HTTP shapes" list.
 *
 * @package SecretsAPI\Examples
 */

/**
 * Talks to a real Vault dev server for the examples suite.
 */
final class Vault_Test_Server {

	/** @var string */
	private $addr;

	/** @var string */
	private $token;

	/** @var string */
	private $mount;

	/**
	 * @param string|null $addr Vault address override; falls back to VAULT_ADDR,
	 *                          then 'http://127.0.0.1:8200'. The token always
	 *                          comes from the environment.
	 */
	public function __construct( $addr = null ) {
		if ( null === $addr ) {
			$addr = getenv( 'VAULT_ADDR' );
		}
		$this->addr  = rtrim( $addr ? $addr : 'http://127.0.0.1:8200', '/' );
		$token       = getenv( 'VAULT_TOKEN' );
		$this->token = $token ? $token : 'dev-root';
		$this->mount = 'secret';
	}

	public function addr() {
		return $this->addr;
	}

	public function token() {
		return $this->token;
	}

	public function mount() {
		return $this->mount;
	}

	/**
	 * @return Vault_KV2_Provider
	 */
	public function provider() {
		return new Vault_KV2_Provider( $this->addr(), $this->token(), $this->mount() );
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /v1/, e.g. 'secret/data/wp/site/1/acme/key'.
	 * @param array|null $body   Request body, encoded as JSON when non-null.
	 *
	 * @return array{code:int,body:array|null}
	 */
	public function request( $method, $path, $body = null ) {
		$response = wp_remote_request(
			"{$this->addr}/v1/{$path}",
			array(
				'method'  => $method,
				'timeout' => 10,
				'headers' => array(
					'X-Vault-Token'   => $this->token,
					'X-Vault-Request' => 'true',
					'Content-Type'    => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			PHPUnit\Framework\Assert::fail(
				sprintf(
					'%s %s/v1/%s: %s',
					$method,
					$this->addr,
					$path,
					$response->get_error_message()
				)
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => $code,
			'body' => is_array( $decoded ) ? $decoded : null,
		);
	}

	/**
	 * @return array
	 */
	public function health() {
		return $this->request( 'GET', 'sys/health' )['body'];
	}

	/**
	 * @param string $vault_path Path under the mount, e.g. 'wp/site/1/acme/key'.
	 *
	 * @return array|null
	 */
	public function metadata( $vault_path ) {
		$result = $this->request( 'GET', "{$this->mount}/metadata/{$vault_path}" );

		if ( 404 === $result['code'] ) {
			return null;
		}

		if ( 200 !== $result['code'] ) {
			PHPUnit\Framework\Assert::fail(
				sprintf( 'GET %s/v1/%s/metadata/%s: unexpected HTTP %d', $this->addr, $this->mount, $vault_path, $result['code'] )
			);
		}

		return isset( $result['body']['data'] ) ? $result['body']['data'] : null;
	}

	/**
	 * @param string $vault_path Path under the mount.
	 * @param int    $version    Version number.
	 *
	 * @return int HTTP response code.
	 */
	public function read_version( $vault_path, $version ) {
		$result = $this->request( 'GET', "{$this->mount}/data/{$vault_path}?version={$version}" );

		return $result['code'];
	}

	/**
	 * @param string $vault_path   Path under the mount.
	 * @param int    $max_versions Value for max_versions.
	 *
	 * @return array{code:int,body:array|null}
	 */
	public function create_metadata( $vault_path, $max_versions ) {
		return $this->request(
			'POST',
			"{$this->mount}/metadata/{$vault_path}",
			array( 'max_versions' => $max_versions )
		);
	}

	/**
	 * @param string $vault_path Path under the mount.
	 * @param int[]  $versions   Version numbers to soft-delete.
	 *
	 * @return array{code:int,body:array|null}
	 */
	public function soft_delete_versions( $vault_path, array $versions ) {
		return $this->request(
			'POST',
			"{$this->mount}/delete/{$vault_path}",
			array( 'versions' => $versions )
		);
	}

	/**
	 * @param string $vault_path Path under the mount to list, e.g. 'wp/'.
	 *
	 * @return string[]
	 */
	public function list_keys( $vault_path ) {
		$result = $this->request( 'GET', "{$this->mount}/metadata/{$vault_path}?list=true" );

		if ( 404 === $result['code'] ) {
			return array();
		}

		if ( 200 !== $result['code'] ) {
			PHPUnit\Framework\Assert::fail(
				sprintf( 'GET %s/v1/%s/metadata/%s?list=true: unexpected HTTP %d', $this->addr, $this->mount, $vault_path, $result['code'] )
			);
		}

		return isset( $result['body']['data']['keys'] ) ? $result['body']['data']['keys'] : array();
	}

	/**
	 * Deletes every secret's metadata (and therefore all its versions) under
	 * secret/metadata/wp/, recursively. Called in set_up() by every Vault test
	 * class: WP_UnitTestCase's database rollback does not reach Vault, and the
	 * conformance suite reuses the same secret name across test methods.
	 *
	 * @return void
	 */
	public function wipe() {
		$this->wipe_recursive( 'wp/' );
	}

	/**
	 * @param string $vault_path Directory path under the mount, ending in '/'.
	 *
	 * @return void
	 */
	private function wipe_recursive( $vault_path ) {
		foreach ( $this->list_keys( $vault_path ) as $key ) {
			$full = $vault_path . $key;

			if ( '/' === substr( $key, -1 ) ) {
				$this->wipe_recursive( $full );
				continue;
			}

			$result = $this->request( 'DELETE', "{$this->mount}/metadata/{$full}" );

			if ( 204 !== $result['code'] ) {
				PHPUnit\Framework\Assert::fail(
					sprintf( 'DELETE %s/v1/%s/metadata/%s: unexpected HTTP %d', $this->addr, $this->mount, $full, $result['code'] )
				);
			}
		}
	}
}
