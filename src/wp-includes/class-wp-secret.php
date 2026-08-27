<?php
/**
 * Secrets API: WP_Secret class
 *
 * @package WordPress
 * @subpackage Secrets
 * @since 7.2.0
 */

/**
 * A decrypted secret value, returned by wp_get_secret().
 *
 * Masking is total and unconditional. Every representation short of an explicit call
 * to reveal() yields the placeholder '[secret:{name}]' -- printing, logging, JSON
 * encoding, or dumping an instance never exposes the plaintext.
 *
 * The plaintext is never a declared property of this class. It lives in a private
 * static table keyed by spl_object_id(), which is what makes the masking unconditional
 * rather than something every magic method has to individually get right: var_export()
 * in particular ignores __debugInfo() and __toString() and serializes declared
 * properties directly, so if the plaintext lived in one there would be no way to mask
 * it there at all.
 *
 * @since 7.2.0
 */
final class WP_Secret implements JsonSerializable {

	/**
	 * Plaintext values, keyed by the owning instance's spl_object_id().
	 *
	 * Deliberately not an SplObjectStorage keyed by the instance itself: that holds a
	 * strong reference to its keys, which would keep every WP_Secret ever constructed
	 * alive -- and its plaintext un-zeroed -- for the life of the request. Keying by
	 * the integer id instead leaves an instance's own reference count to govern its
	 * lifetime normally, so __destruct() fires and zeroes the value the moment nothing
	 * else references the instance.
	 *
	 * @since 7.2.0
	 * @var array<int, string>
	 */
	private static $vault = array();

	/**
	 * The secret's namespaced name.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private $name;

	/**
	 * Keyed fingerprint of the plaintext. Not sensitive: it is a one-way, per-site,
	 * per-key digest, not the value itself.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private $fingerprint;

	/**
	 * Constructor.
	 *
	 * Throws, where the rest of this API returns WP_Error for a caller mistake.
	 * That is not an inconsistency to be tidied up later: a constructor has no
	 * return channel, so the only alternatives are throwing or building a
	 * half-valid WP_Secret and letting it fail somewhere less obvious. Every
	 * function that *can* return WP_Error does -- see
	 * docs/open-questions.md #12. The same reasoning covers the serialization
	 * and clone refusals below, which are magic methods with the same problem
	 * and an additional one: silently permitting them would leak a plaintext.
	 *
	 * Nothing outside this API constructs a WP_Secret in normal use; the values
	 * passed here come from _wp_secrets_get() having just decrypted them.
	 *
	 * @since 7.2.0
	 *
	 * @param string $name        The secret's namespaced name.
	 * @param string $value       The decrypted plaintext.
	 * @param string $fingerprint Keyed fingerprint of $value.
	 *
	 * @throws InvalidArgumentException If any argument is not a non-empty string
	 *                                  (value may be empty, but must be a string).
	 */
	public function __construct( $name, $value, $fingerprint ) {
		if ( ! is_string( $name ) || '' === $name ) {
			throw new InvalidArgumentException( 'WP_Secret requires a non-empty string name.' );
		}

		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'WP_Secret values must be strings.' );
		}

		if ( ! is_string( $fingerprint ) || '' === $fingerprint ) {
			throw new InvalidArgumentException( 'WP_Secret requires a non-empty string fingerprint.' );
		}

		$this->name        = $name;
		$this->fingerprint = $fingerprint;

		self::$vault[ spl_object_id( $this ) ] = $value;
	}

	/**
	 * Returns the decrypted plaintext.
	 *
	 * This is the only path to the stored plaintext anywhere in the API.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function reveal() {
		return self::$vault[ spl_object_id( $this ) ];
	}

	/**
	 * Returns the keyed fingerprint of the plaintext.
	 *
	 * Stable for a given value on a given site; not a value-recovery oracle.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function fingerprint() {
		return $this->fingerprint;
	}

	/**
	 * Returns the secret's namespaced name.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Builds the masked placeholder used everywhere a plaintext would otherwise appear.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	private function mask() {
		return '[secret:' . $this->name . ']';
	}

	/**
	 * Masks the instance for string conversion.
	 *
	 * Covers error_log( $secret ) and any other implicit string coercion.
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	public function __toString() {
		return $this->mask();
	}

	/**
	 * Masks the instance for var_dump().
	 *
	 * Note that print_r() and var_export() have no equivalent hook. They are safe
	 * regardless, because the plaintext is never a declared property for them to
	 * enumerate; they will surface the (non-sensitive) name and fingerprint rather
	 * than this placeholder. See docs/open-questions.md for the documented
	 * var_export() limitation.
	 *
	 * @since 7.2.0
	 *
	 * @return array
	 */
	public function __debugInfo(): array {
		return array( 'value' => $this->mask() );
	}

	/**
	 * Masks the instance for json_encode().
	 *
	 * @since 7.2.0
	 *
	 * @return string
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->mask();
	}

	/**
	 * Refuses serialization outright.
	 *
	 * @since 7.2.0
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __sleep() {
		throw new LogicException( 'WP_Secret cannot be serialized.' );
	}

	/**
	 * Refuses unserialization outright.
	 *
	 * @since 7.2.0
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new LogicException( 'WP_Secret cannot be unserialized.' );
	}

	/**
	 * Refuses serialization outright.
	 *
	 * PHP calls this in preference to __sleep() when both are defined, so both must
	 * refuse independently.
	 *
	 * @since 7.2.0
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __serialize() {
		throw new LogicException( 'WP_Secret cannot be serialized.' );
	}

	/**
	 * Refuses unserialization outright.
	 *
	 * @since 7.2.0
	 *
	 * @param array $data Ignored.
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __unserialize( $data ) {
		throw new LogicException( 'WP_Secret cannot be unserialized.' );
	}

	/**
	 * Refuses cloning outright.
	 *
	 * A clone would be a second, unaudited reference to the same plaintext with an
	 * independent lifetime -- and, since the vault is keyed by object id rather than
	 * copied, a bare `clone` would otherwise leave the clone's reveal() silently
	 * reading nothing.
	 *
	 * @since 7.2.0
	 *
	 * @throws LogicException Always.
	 */
	public function __clone() {
		throw new LogicException( 'WP_Secret cannot be cloned.' );
	}

	/**
	 * Zeroes the plaintext and removes it from the vault.
	 *
	 * @since 7.2.0
	 */
	public function __destruct() {
		$id = spl_object_id( $this );

		if ( isset( self::$vault[ $id ] ) ) {
			wp_secrets_memzero( self::$vault[ $id ] );
			unset( self::$vault[ $id ] );
		}
	}
}
