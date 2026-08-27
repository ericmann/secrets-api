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
		'class-wp-secrets-broken-keyring.php',
		'class-wp-secrets-cipher.php',
		'class-wp-secrets-key-manager.php',
		'interface-wp-secrets-store.php',
		'class-wp-secrets-option-store.php',
		'class-wp-secrets-broken-store.php',
	);

	foreach ( $core_bound as $file ) {
		require_once WP_SECRETS_API_PLUGIN_DIR . 'src/wp-includes/' . $file;
	}

	/*
	 * Site Health's own hooks ('site_status_tests', 'debug_information') only ever
	 * fire in wp-admin, but this is loaded unconditionally rather than gated on
	 * is_admin(): registering two filters is negligible overhead, and is_admin()
	 * is false during some of the same contexts Site Health's own async REST checks
	 * run in, which would make the gate unreliable in exactly the cases it matters.
	 */
	require_once WP_SECRETS_API_PLUGIN_DIR . 'src/wp-admin/includes/secrets-site-health.php';

	// Plugin-only: never copied to core. Loaded unconditionally since it is a
	// small, side-effect-free class definition; only the migrator and the
	// `migrate-legacy` CLI command (both landing in a later commit) actually use it.
	require_once WP_SECRETS_API_PLUGIN_DIR . 'plugin/class-secrets-api-legacy-reader.php';

	/*
	 * Loaded only now, after every core-bound interface and class this plugin
	 * defines: a drop-in that declares `class My_Store implements
	 * WP_Secrets_Store` needs that interface to already exist to compile at all.
	 * This is "as early as it can" for a plugin specifically because of that
	 * ordering constraint; the core patch moves this into wp-settings.php, ahead
	 * of plugins_loaded entirely, once the interfaces live in wp-includes from
	 * the start of the request.
	 */
	wp_secrets_api_load_dropin();

	register_activation_hook( __FILE__, 'wp_secrets_api_activate' );
	register_uninstall_hook( __FILE__, 'wp_secrets_api_uninstall' );

	/*
	 * Granting manage_network_secrets runs on every request rather than once at
	 * activation: unlike the administrator role, there is no persistent "network
	 * administrator" role object to add a capability to, so super admin status has
	 * to be checked live, the same way core itself gates network-only screens.
	 */
	add_filter( 'user_has_cap', 'wp_secrets_api_grant_network_cap_to_super_admins', 10, 4 );

	// cli/ is never copied to core and is registered only under real WP-CLI --
	// or, in this plugin's own test suite, the mock WP_CLI test double that
	// tests/bootstrap.php defines before the plugin ever loads.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WP_SECRETS_API_PLUGIN_DIR . 'cli/class-wp-cli-secret-command.php';
		require_once WP_SECRETS_API_PLUGIN_DIR . 'cli/class-wp-cli-secret-network-command.php';

		WP_CLI::add_command( 'secret', 'WP_CLI_Secret_Command' );
		WP_CLI::add_command( 'network-secret', 'WP_CLI_Secret_Network_Command' );
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

/**
 * Loads the secrets.php drop-in, if one exists, and records whether it left the
 * store and keyring overrides in a usable state.
 *
 * Idempotent: guarded by a static flag rather than relying on require_once alone,
 * since the caching in _wp_secrets_get_store()/_wp_secrets_get_key_manager() means
 * this only ever needs to run once regardless of how many times it is called.
 *
 * A malformed drop-in must not turn into a white screen for the rest of the site.
 * A syntax error, a thrown exception, or most runtime errors in the drop-in are
 * caught here and turned into WP_Secrets_Broken_Store / WP_Secrets_Broken_Keyring
 * for every operation instead. This is not airtight: PHP treats some class
 * declaration errors -- notably a class that `implements` an interface but omits
 * a required method -- as an uncatchable fatal even inside a try/catch around the
 * require, confirmed empirically on both PHP 7.4 and 8.5 before writing this
 * comment. That gap is unavoidable from userland and is recorded in
 * docs/open-questions.md rather than silently assumed away.
 *
 * @return void
 */
function wp_secrets_api_load_dropin() {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	$loaded = true;

	$dropin_path = WP_CONTENT_DIR . '/secrets.php';

	if ( ! file_exists( $dropin_path ) ) {
		return;
	}

	$GLOBALS['wp_secrets_dropin_loaded'] = true;

	try {
		require $dropin_path;
	} catch ( \Throwable $e ) {
		$GLOBALS['wp_secrets_dropin_broken'] = true;

		return;
	}

	/*
	 * Not set at all is fine -- a drop-in overriding only the keyring, say,
	 * legitimately leaves the store global untouched. Set to the wrong thing is
	 * not: that is exactly the case _wp_secrets_get_store() fails closed for.
	 */
	if ( isset( $GLOBALS['wp_secrets_store'] ) && ! ( $GLOBALS['wp_secrets_store'] instanceof WP_Secrets_Store ) ) {
		$GLOBALS['wp_secrets_dropin_broken'] = true;
	}

	if ( isset( $GLOBALS['wp_secrets_keyring'] ) && ! ( $GLOBALS['wp_secrets_keyring'] instanceof WP_Secrets_Keyring ) ) {
		$GLOBALS['wp_secrets_dropin_broken'] = true;
	}
}

/**
 * Grants the site-scope management capability to administrators.
 *
 * @return void
 */
function wp_secrets_api_activate() {
	$administrator = get_role( 'administrator' );

	if ( $administrator ) {
		$administrator->add_cap( WP_SECRETS_CAP_MANAGE );
	}
}

/**
 * Removes the capability this plugin granted, on uninstall -- not on deactivation.
 * Deactivating and reactivating the plugin must not silently strip a capability an
 * administrator may have started relying on for something else in the meantime.
 *
 * @return void
 */
function wp_secrets_api_uninstall() {
	$administrator = get_role( 'administrator' );

	if ( $administrator ) {
		$administrator->remove_cap( WP_SECRETS_CAP_MANAGE );
	}
}

/**
 * Grants manage_network_secrets to super admins.
 *
 * @param array   $allcaps All capabilities of the user.
 * @param array   $caps    Required primitive capabilities for the requested capability.
 * @param array   $args    Arguments passed to current_user_can().
 * @param WP_User $user    The user object.
 *
 * @return array
 */
function wp_secrets_api_grant_network_cap_to_super_admins( $allcaps, $caps, $args, $user ) {
	if ( is_multisite() && in_array( WP_SECRETS_CAP_MANAGE_NETWORK, $caps, true ) && is_super_admin( $user->ID ) ) {
		$allcaps[ WP_SECRETS_CAP_MANAGE_NETWORK ] = true;
	}

	return $allcaps;
}

wp_secrets_api_bootstrap();
