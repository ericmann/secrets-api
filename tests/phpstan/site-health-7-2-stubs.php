<?php
/**
 * WP_Site_Health as of WordPress 7.2, for static analysis. The WordPress stubs
 * predate 7.2: they lack the Secrets API tests that cli/ calls on a core that
 * ships the API (r65394), and type get_instance() as nullable, which it no longer is.
 *
 * Read by PHPStan as a stub file, which can only change docblocks. So the new
 * methods are declared with @method tags. Never loaded at runtime.
 *
 * @package SecretsAPI
 */

// phpcs:disable

/**
 * @method array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string} get_test_secrets_api_key_source()
 * @method array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string} get_test_secrets_api_undecryptable()
 * @method array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string} get_test_secrets_api_needs_rotation()
 */
class WP_Site_Health {

	/**
	 * @return WP_Site_Health
	 */
	public static function get_instance() {}
}
