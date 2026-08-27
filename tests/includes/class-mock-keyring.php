<?php
/**
 * Configurable WP_Secrets_Keyring test double. Not real cryptography -- a
 * deterministic, reversible marker transform, useful for exercising code that
 * consumes a keyring without needing that code to also exercise libsodium.
 */
class Mock_Keyring implements WP_Secrets_Keyring {

	const MARKER = 'mock-wrapped:';

	private $fail_wrap   = false;
	private $fail_unwrap = false;

	public function wrap( $key_material ) {
		if ( $this->fail_wrap ) {
			return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Mock_Keyring: wrap() configured to fail.' );
		}

		return self::MARKER . base64_encode( $key_material );
	}

	public function unwrap( $wrapped ) {
		if ( $this->fail_unwrap ) {
			return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Mock_Keyring: unwrap() configured to fail.' );
		}

		if ( ! is_string( $wrapped ) || 0 !== strpos( $wrapped, self::MARKER ) ) {
			return new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Mock_Keyring: not a value this keyring wrapped.' );
		}

		return base64_decode( substr( $wrapped, strlen( self::MARKER ) ), true );
	}

	public function get_key_source() {
		return 'mock keyring';
	}

	/**
	 * @param bool $fail Whether wrap() should return WP_Error.
	 *
	 * @return $this
	 */
	public function configure_fail_wrap( $fail = true ) {
		$this->fail_wrap = $fail;

		return $this;
	}

	/**
	 * @param bool $fail Whether unwrap() should return WP_Error.
	 *
	 * @return $this
	 */
	public function configure_fail_unwrap( $fail = true ) {
		$this->fail_unwrap = $fail;

		return $this;
	}
}
