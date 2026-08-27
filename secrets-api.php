<?php
/**
 * Plugin Name:       Secrets API
 * Plugin URI:        https://github.com/WordPress/secrets-api
 * Description:       Feature plugin for the WordPress Secrets API proposed for 7.2. Encrypted, versioned credential storage with pluggable storage and keyring back ends.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secrets-api
 *
 * @package SecretsAPI
 */

/*
 * The slug and display name above are provisional. See docs/open-questions.md #2 -- a
 * neutral, non-Displace-branded slug needs a human decision before any .org submission.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'WP_SECRETS_API_PLUGIN_VERSION', '0.1.0' );

/**
 * The WordPress version expected to ship the Secrets API in core.
 *
 * Overridable from wp-config.php for the case where the API lands in a different
 * release than currently planned. The proposal's own timeline allows for the API to
 * be deferred to 7.3, which is why this gate is never used on its own -- see below.
 */
defined( 'WP_SECRETS_API_CORE_VERSION' ) || define( 'WP_SECRETS_API_CORE_VERSION', '7.2' );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'WP_SECRETS_API_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Decide whether to load, and load.
 *
 * The entire no-op decision lives here. Files under src/ carry no function_exists() or
 * class_exists() guards at all, for two reasons:
 *
 * 1. src/ is destined to be copied verbatim into wordpress-develop, and core's
 *    wp-includes files do not guard their own function declarations.
 * 2. A per-function guard on a credential retrieval function is an overloading surface.
 *    An mu-plugin that declared wp_get_secret() first would silently win, and every
 *    secret read on the site would flow through it. All-or-nothing is the only safe
 *    granularity here.
 *
 * @return void
 */
function wp_secrets_api_bootstrap() {
	global $wp_version;

	$symbol_taken = function_exists( 'wp_get_secret' );

	// Core ships the API. Load nothing; the plugin is redundant.
	if ( version_compare( $wp_version, WP_SECRETS_API_CORE_VERSION, '>=' ) && $symbol_taken ) {
		add_action( 'admin_notices', 'wp_secrets_api_notice_superseded' );

		return;
	}

	/*
	 * The version gate is deliberately ANDed with a positive probe rather than used on
	 * its own. If 7.2 ships without the API, a bare ">= 7.2" check would silently
	 * disable this plugin and strand every site relying on it.
	 */

	// Something other than core already claimed the symbol. Refuse loudly rather than
	// deferring to an unknown implementation of a credential store.
	if ( $symbol_taken ) {
		add_action( 'admin_notices', 'wp_secrets_api_notice_conflict' );

		return;
	}

	/*
	 * Dependency order: public helper functions first (WP_Secret's destructor calls
	 * wp_secrets_memzero()), then value objects, then crypto, then storage.
	 */
	$core_bound = array(
		'secrets.php',
		'class-wp-secret-version.php',
		'class-wp-secret.php',
		'interface-wp-secrets-keyring.php',
		'class-wp-secrets-config-key-provider.php',
		'class-wp-secrets-cipher.php',
	);

	foreach ( $core_bound as $file ) {
		require_once WP_SECRETS_API_PLUGIN_DIR . 'src/wp-includes/' . $file;
	}
}

/**
 * Admin notice shown when core supersedes this plugin.
 *
 * @return void
 */
function wp_secrets_api_notice_superseded() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	wp_admin_notice(
		esc_html__( 'This version of WordPress provides the Secrets API natively. The Secrets API feature plugin is no longer doing anything and can be deactivated.', 'secrets-api' ),
		array( 'type' => 'info' )
	);
}

/**
 * Admin notice shown when another plugin has already declared the Secrets API.
 *
 * @return void
 */
function wp_secrets_api_notice_conflict() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	wp_admin_notice(
		esc_html__( 'The Secrets API feature plugin did not load: another plugin or mu-plugin has already declared wp_get_secret(). Two implementations of a credential store cannot safely coexist. Deactivate one of them.', 'secrets-api' ),
		array( 'type' => 'error' )
	);
}

wp_secrets_api_bootstrap();
