<?php
/**
 * Configurable WP_Secrets_Store test double: can fail on demand per operation,
 * decline capabilities independently of failing, and records every value it is
 * ever handed so a test can assert it was never given a plaintext.
 */
class Mock_Store implements WP_Secrets_Store {

	private $records          = array();
	private $fail             = array(
		'get'    => false,
		'set'    => false,
		'delete' => false,
		'list'   => false,
	);
	private $capabilities     = array(
		'write'  => true,
		'list'   => true,
		'delete' => true,
	);
	private $received_records = array();

	public function get( $name, $network = false ) {
		if ( $this->fail['get'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, 'Mock_Store: get() configured to fail.' );
		}

		$key = $this->key( $name, $network );

		return array_key_exists( $key, $this->records ) ? $this->records[ $key ] : null;
	}

	public function set( $name, $record, $network = false ) {
		$this->received_records[] = $record;

		if ( $this->fail['set'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, 'Mock_Store: set() configured to fail.' );
		}

		if ( ! $this->capabilities['write'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_READ_ONLY, 'Mock_Store: write not supported.' );
		}

		$this->records[ $this->key( $name, $network ) ] = $record;

		return true;
	}

	public function delete( $name, $network = false ) {
		if ( $this->fail['delete'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, 'Mock_Store: delete() configured to fail.' );
		}

		if ( ! $this->capabilities['delete'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_READ_ONLY, 'Mock_Store: delete not supported.' );
		}

		unset( $this->records[ $this->key( $name, $network ) ] );

		return true;
	}

	public function list_names( $network = false ) {
		if ( $this->fail['list'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, 'Mock_Store: list_names() configured to fail.' );
		}

		if ( ! $this->capabilities['list'] ) {
			return new WP_Error( WP_SECRETS_ERROR_STORE_READ_ONLY, 'Mock_Store: list not supported.' );
		}

		$prefix = $network ? 'network:' : 'site:';
		$names  = array();

		foreach ( array_keys( $this->records ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$names[] = substr( $key, strlen( $prefix ) );
			}
		}

		return $names;
	}

	public function supports( $capability ) {
		return isset( $this->capabilities[ $capability ] ) && $this->capabilities[ $capability ];
	}

	/**
	 * @param string $operation One of 'get', 'set', 'delete', 'list'.
	 * @param bool   $fail      Whether that operation should return WP_Error.
	 *
	 * @return $this
	 */
	public function configure_fail( $operation, $fail = true ) {
		$this->fail[ $operation ] = $fail;

		return $this;
	}

	/**
	 * @param string $capability One of 'write', 'list', 'delete'.
	 * @param bool   $supports   Whether supports() should report true for it.
	 *
	 * @return $this
	 */
	public function configure_supports( $capability, $supports ) {
		$this->capabilities[ $capability ] = $supports;

		return $this;
	}

	/**
	 * Every record ever passed to set(), in call order -- including ones set()
	 * went on to reject, since the assertion this exists for is "was this store
	 * ever handed a plaintext," not "was a plaintext ever successfully stored."
	 *
	 * @return array
	 */
	public function get_received_records() {
		return $this->received_records;
	}

	private function key( $name, $network ) {
		return ( $network ? 'network:' : 'site:' ) . $name;
	}
}
