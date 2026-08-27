<?php
/**
 * WP-CLI: wp secret
 *
 * Never copied to core -- registered only when defined( 'WP_CLI' ) && WP_CLI.
 *
 * @package SecretsAPI
 */

/**
 * Manages secrets stored via the Secrets API.
 *
 * Shared by `wp secret` (site scope) and `wp network-secret` (network scope,
 * WP_CLI_Secret_Network_Command below): every method here is scope-agnostic,
 * switching between the site and network public functions via $this->network,
 * which the network subclass overrides to true.
 */
class WP_CLI_Secret_Command {

	/**
	 * Whether this instance manages network-scope secrets.
	 *
	 * @var bool
	 */
	protected $network = false;

	/**
	 * Refuses network-scope commands outright on a single-site install.
	 *
	 * WP-CLI instantiates a command class lazily, at the point its subcommand is
	 * actually invoked (when registered by class name, as this plugin does) --
	 * so this runs once per invocation, not once at registration, and never fires
	 * for `wp secret` at all since only the network subclass sets $network true.
	 */
	public function __construct() {
		if ( $this->network && ! is_multisite() ) {
			WP_CLI::error( 'Network secrets require a multisite installation.' );
		}
	}

	/**
	 * Sets a secret's value.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The secret's namespaced name ('plugin-slug/secret-name').
	 *
	 * [<value>]
	 * : The plaintext value. Passing this as an argument leaks it into shell
	 * history and process listings on shared hosts -- use --stdin instead.
	 *
	 * [--stdin]
	 * : Read the value from STDIN. The documented way to pass a value.
	 *
	 * [--porcelain]
	 * : Output only the new fingerprint, for scripting.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp secret set myplugin/api-key --stdin <<< "sk_live_..."
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( $args, $assoc_args ) {
		$name = $args[0];

		if ( isset( $assoc_args['stdin'] ) ) {
			$value = rtrim( file_get_contents( 'php://stdin' ), "\r\n" );
		} elseif ( isset( $args[1] ) ) {
			WP_CLI::warning( 'Passing a secret value as a command argument leaks it into shell history. Use --stdin instead.' );
			$value = $args[1];
		} else {
			WP_CLI::error( 'Provide a value as an argument or via --stdin.' );

			return;
		}

		$result = $this->network ? wp_set_network_secret( $name, $value ) : wp_set_secret( $name, $value );

		wp_secrets_memzero( $value );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );

			return;
		}

		$secret = $this->network ? wp_get_network_secret( $name ) : wp_get_secret( $name );

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( ( $secret instanceof WP_Secret ) ? $secret->fingerprint() : '' );

			return;
		}

		WP_CLI::success( sprintf( 'Set secret "%s".', $name ) );
	}

	/**
	 * Gets a secret. Masks the value by default.
	 *
	 * Exit code doubles as an existence check: 0 if found, 1 if absent, 2 on error.
	 * There is no separate `exists` subcommand.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The secret's namespaced name.
	 *
	 * [--version=<version>]
	 * : Which version to get.
	 * ---
	 * default: current
	 * options:
	 *   - current
	 *   - previous
	 * ---
	 *
	 * [--reveal]
	 * : Show the actual value. Without this, it is masked.
	 *
	 * [--field=<field>]
	 * : Print a single field (name, fingerprint, value) instead of a table.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) {
		$name    = $args[0];
		$version = ( isset( $assoc_args['version'] ) && WP_Secret_Version::PREVIOUS === $assoc_args['version'] )
			? WP_Secret_Version::PREVIOUS
			: WP_Secret_Version::CURRENT;

		$secret = $this->network ? wp_get_network_secret( $name, $version ) : wp_get_secret( $name, $version );

		if ( is_wp_error( $secret ) ) {
			WP_CLI::error( $secret->get_error_message(), false );
			WP_CLI::halt( 2 );
		}

		if ( null === $secret ) {
			WP_CLI::halt( 1 );
		}

		if ( isset( $assoc_args['reveal'] ) ) {
			WP_CLI::warning( 'Revealing a secret value. Make sure this output does not end up somewhere logged.' );
			$value = $secret->reveal();
		} else {
			$value = $this->mask( $secret->reveal() );
		}

		$item = array(
			'name'        => $secret->get_name(),
			'fingerprint' => $secret->fingerprint(),
			'value'       => $value,
		);

		if ( isset( $assoc_args['field'] ) ) {
			WP_CLI::line( isset( $item[ $assoc_args['field'] ] ) ? $item[ $assoc_args['field'] ] : '' );

			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		\WP_CLI\Utils\format_items( $format, array( $item ), array( 'name', 'fingerprint', 'value' ) );
	}

	/**
	 * Masks a value for display: full masking under 12 characters, otherwise the
	 * first 4 characters plus a fixed-width run of asterisks. Fixed width, not
	 * proportional to the real length, so the mask does not itself leak how long
	 * the secret is.
	 *
	 * @param string $value Plaintext value.
	 *
	 * @return string
	 */
	protected function mask( $value ) {
		if ( strlen( $value ) < 12 ) {
			return str_repeat( '*', 8 );
		}

		return substr( $value, 0, 4 ) . str_repeat( '*', 8 );
	}

	/**
	 * Deletes a secret.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The secret's namespaced name.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$name = $args[0];

		WP_CLI::confirm( sprintf( 'Delete secret "%s"? This cannot be undone.', $name ), $assoc_args );

		$result = $this->network ? wp_delete_network_secret( $name ) : wp_delete_secret( $name );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );

			return;
		}

		WP_CLI::success( sprintf( 'Deleted secret "%s".', $name ) );
	}

	/**
	 * Lists secrets by name and metadata. Never shows a value.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * : Only secrets whose name starts with "<namespace>/".
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) {
		$namespace = isset( $assoc_args['namespace'] ) ? $assoc_args['namespace'] : '';

		$entries = $this->network ? wp_list_network_secrets( $namespace ) : wp_list_secrets( $namespace );

		if ( is_wp_error( $entries ) ) {
			WP_CLI::error( $entries->get_error_message() );

			return;
		}

		$fields = isset( $assoc_args['fields'] )
			? explode( ',', $assoc_args['fields'] )
			: array( 'name', 'fingerprint', 'created', 'has_previous', 'needs_rotation' );

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		\WP_CLI\Utils\format_items( $format, $entries, $fields );
	}

	/**
	 * Clears a secret's previous version.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The secret's namespaced name.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retire( $args, $assoc_args ) {
		$name = $args[0];

		WP_CLI::confirm( sprintf( 'Retire the previous version of "%s"? This clears it permanently.', $name ), $assoc_args );

		$result = $this->network ? wp_retire_network_secret_version( $name ) : wp_retire_secret_version( $name );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );

			return;
		}

		WP_CLI::success( sprintf( 'Retired the previous version of "%s".', $name ) );
	}

	/**
	 * Imports an existing option's value as a secret. The source option is left
	 * untouched, and the imported secret is flagged for rotation.
	 *
	 * ## OPTIONS
	 *
	 * <option>
	 * : The existing option's name.
	 *
	 * <name>
	 * : The secret's namespaced name to store it under.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments.
	 */
	public function import_option( $args ) {
		if ( $this->network ) {
			WP_CLI::error( 'Importing an option as a network-scope secret is not supported.' );

			return;
		}

		list( $option, $name ) = $args;

		$result = wp_import_option_as_secret( $option, $name );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );

			return;
		}

		WP_CLI::success( sprintf( 'Imported option "%s" as secret "%s". Flagged for rotation.', $option, $name ) );
	}

	/**
	 * Migrates secrets from displace-secrets-manager's legacy format.
	 *
	 * With no flags, migrates every legacy secret into the new format and leaves
	 * every legacy option in place -- writing the new-format secret is never
	 * destructive, and the migration is idempotent (re-running is safe; an
	 * already-migrated key is reported "skipped"). Deleting the legacy source is
	 * the actually destructive step and always requires --delete-source, which is
	 * only ever honoured after this run's own fingerprint verification passes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Write nothing at all; report what would happen.
	 *
	 * [--name=<key>]
	 * : Migrate only this legacy key.
	 *
	 * [--map=<mapping>]
	 * : Comma-separated old:new pairs for keys whose derived name would not
	 * validate, e.g. --map=api_key:myplugin/api-key,other:myplugin/other-key.
	 *
	 * [--namespace=<namespace>]
	 * : Namespace prefixed onto a legacy key with no --map entry.
	 * ---
	 * default: legacy
	 * ---
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate_legacy( $args, $assoc_args ) {
		if ( $this->network ) {
			WP_CLI::error( 'The legacy format has no network-scope equivalent.' );

			return;
		}

		$map = array();

		if ( isset( $assoc_args['map'] ) ) {
			foreach ( explode( ',', $assoc_args['map'] ) as $pair ) {
				$parts = explode( ':', $pair, 2 );

				if ( 2 === count( $parts ) ) {
					$map[ $parts[0] ] = $parts[1];
				}
			}
		}

		$report = ( new Secrets_API_Migrator() )->migrate(
			array(
				'dry_run'   => isset( $assoc_args['dry-run'] ),
				'name'      => isset( $assoc_args['name'] ) ? $assoc_args['name'] : null,
				'map'       => $map,
				'namespace' => isset( $assoc_args['namespace'] ) ? $assoc_args['namespace'] : 'legacy',
			)
		);

		if ( $report['vendor_detected'] ) {
			WP_CLI::warning( "The AI plugin's vendored Secrets_Manager class is present, so the prototype's option rows are still in active use. They are left untouched: after this runs, each migrated credential exists in both formats, and that plugin keeps reading its own copy until it moves to the Secrets API." );
		}

		$items = array();

		foreach ( $report['entries'] as $entry ) {
			$items[] = array(
				'legacy_key' => $entry['legacy_key'],
				'new_name'   => $entry['new_name'],
				'status'     => $entry['status'],
				'message'    => $entry['message'],
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		\WP_CLI\Utils\format_items( $format, $items, array( 'legacy_key', 'new_name', 'status', 'message' ) );

		foreach ( $report['entries'] as $entry ) {
			if ( 'error' === $entry['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	}

	/**
	 * Re-wraps the root key under a new WP_SECRETS_KEY after a site-key change.
	 *
	 * No secret is re-encrypted: rotation only changes what the root key is
	 * wrapped under, not the root key's own bytes.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rotate( $args, $assoc_args ) {
		if ( ! defined( 'WP_SECRETS_KEY_PREVIOUS' ) ) {
			WP_CLI::error( 'WP_SECRETS_KEY_PREVIOUS is not defined. Move the current WP_SECRETS_KEY value to WP_SECRETS_KEY_PREVIOUS, set WP_SECRETS_KEY to a new value from `wp secret generate-key`, then run this again.' );

			return;
		}

		WP_CLI::confirm( 'Rotate the site key? This re-wraps the root key under the new WP_SECRETS_KEY.', $assoc_args );

		$result = _wp_secrets_get_key_manager()->rotate_site_key(
			new WP_Secrets_Config_Key_Provider( true ),
			new WP_Secrets_Config_Key_Provider( false )
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );

			return;
		}

		WP_CLI::success( 'Site key rotated. No secret needed to be re-encrypted.' );
	}

	/**
	 * Emits a base64-encoded 32-byte key, suitable for WP_SECRETS_KEY.
	 *
	 * Writes to STDOUT only. Never touches wp-config.php -- adding the constant is
	 * the operator's own step.
	 *
	 * @when after_wp_load
	 */
	public function generate_key() {
		WP_CLI::line( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding key bytes for display, not obfuscating code.
	}

	/**
	 * Reports the Secrets API's Site Health status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function health( $args, $assoc_args ) {
		$results = array(
			wp_secrets_site_health_test_key_source(),
			wp_secrets_site_health_test_undecryptable(),
			wp_secrets_site_health_test_needs_rotation(),
		);

		$items = array();

		foreach ( $results as $result ) {
			$items[] = array(
				'check'  => $result['label'],
				'status' => $result['status'],
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		\WP_CLI\Utils\format_items( $format, $items, array( 'check', 'status' ) );

		foreach ( $results as $result ) {
			if ( 'critical' === $result['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	}

	/**
	 * Reports what store and keyring are active.
	 *
	 * @when after_wp_load
	 */
	public function dropin() {
		$key_manager = _wp_secrets_get_key_manager();

		WP_CLI::log( sprintf( 'Drop-in active: %s', wp_using_secrets_dropin() ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Store: %s', get_class( _wp_secrets_get_store() ) ) );
		WP_CLI::log( sprintf( 'Keyring: %s', get_class( $key_manager->get_keyring() ) ) );
		WP_CLI::log( sprintf( 'Key source: %s', $key_manager->get_keyring()->get_key_source() ) );
	}
}
