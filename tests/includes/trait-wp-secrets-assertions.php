<?php
/**
 * Shared assertions for the Secrets API test suite.
 *
 * Populated as the API lands. Kept in one place so a change to what "never contains
 * plaintext" means is a single edit rather than a search across the suite.
 *
 * @package SecretsAPI
 */

/**
 * Assertions shared across the Secrets API test suite.
 */
trait WP_Secrets_Assertions {

	/**
	 * Assert that a haystack of any shape contains no trace of a plaintext value.
	 *
	 * Arrays and objects are walked recursively and serialized defensively, because the
	 * failure mode this guards against is a plaintext surviving somewhere nested that a
	 * shallow string comparison would miss.
	 *
	 * @param string $plaintext Value that must not appear.
	 * @param mixed  $haystack  Structure to search.
	 * @param string $message   Optional failure message.
	 */
	public function assertNeverContainsPlaintext( $plaintext, $haystack, $message = '' ) {
		$this->assertNotSame( '', $plaintext, 'Refusing to search for an empty plaintext; the assertion would be vacuous.' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Flattening arbitrary structures is the point of this assertion; print_r reaches nested values that a shallow comparison would miss.
		$flattened = is_scalar( $haystack ) ? (string) $haystack : print_r( $haystack, true );

		$this->assertStringNotContainsString(
			$plaintext,
			$flattened,
			'' !== $message ? $message : 'Plaintext leaked into a structure that must never contain it.'
		);
	}
}
