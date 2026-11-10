<?php
/**
 * Secrets API: Site Health checks for WordPress before 7.2 (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

/**
 * Adds the Secrets API's Site Health tests and debug information on WordPress before 7.2.
 *
 * In core they are methods of WP_Site_Health and WP_Debug_Data (r65394). A plugin cannot
 * add methods to those classes, so this class carries copies of them and registers them
 * through the 'site_status_tests' and 'debug_information' filters, under the same test
 * IDs, labels and debug section key that core uses.
 *
 * Everything after add_debug_information() is copied from core trunk unchanged, so it
 * passes no text domain, as core does. Porting a later core change means copying the
 * methods again. On trunk, the stand-down suite compares each copy with core's.
 */
final class Secrets_API_Site_Health {

	/**
	 * Hooks the tests and the debug information into Site Health.
	 */
	public static function register(): void {
		$site_health = new self();

		add_filter( 'site_status_tests', array( $site_health, 'add_tests' ) );
		add_filter( 'debug_information', array( $site_health, 'add_debug_information' ) );
	}

	/**
	 * Registers the three tests as direct tests, as WP_Site_Health::get_tests() does in core.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests Existing Site Health tests.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function add_tests( $tests ) {
		$tests['direct']['secrets_api_key_source'] = array(
			'label' => __( 'Secrets API key source', 'secrets-api' ),
			'test'  => array( $this, 'get_test_secrets_api_key_source' ),
		);

		$tests['direct']['secrets_api_undecryptable'] = array(
			'label' => __( 'Secrets API: undecryptable secrets', 'secrets-api' ),
			'test'  => array( $this, 'get_test_secrets_api_undecryptable' ),
		);

		$tests['direct']['secrets_api_needs_rotation'] = array(
			'label' => __( 'Secrets API: credentials pending rotation', 'secrets-api' ),
			'test'  => array( $this, 'get_test_secrets_api_needs_rotation' ),
		);

		return $tests;
	}

	/**
	 * Adds the Secrets API section, as WP_Debug_Data::debug_data() does in core.
	 *
	 * @param array<string, array<string, mixed>> $info Existing debug information sections.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_debug_information( $info ) {
		$info['wp-secrets-api'] = self::get_wp_secrets_api();

		return $info;
	}

	/**
	 * Tests whether the Secrets API has a usable key.
	 *
	 * There is no settings screen for secrets, so this is how an administrator notices
	 * a missing or broken key, or a key that no longer unlocks the stored secrets.
	 *
	 * @since 7.2.0
	 *
	 * @return array The test result.
	 *
	 * @phpstan-return array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string}
	 */
	public function get_test_secrets_api_key_source(): array {
		$provider = _wp_secrets_get_provider();

		if ( ! $this->secrets_api_uses_keyring( $provider ) ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_key_source',
				__( 'Secrets API keys are managed by the provider' ),
				'good',
				sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: 1: secrets.php, 2: Name of the active secrets provider, 3: WP_SECRETS_KEY */
						__( 'Secrets are protected by the provider of the %1$s drop-in: %2$s. %3$s is not used.' ),
						'<code>secrets.php</code>',
						esc_html( $provider->get_label() ),
						'<code>WP_SECRETS_KEY</code>'
					)
				)
			);
		}

		$keyring    = _wp_secrets_get_key_manager()->get_keyring();
		$key_source = $keyring->get_key_source();
		$key_status = $keyring instanceof WP_Secrets_Config_Key_Provider ? $keyring->get_key_status() : null;

		// The broken keyring's description is translated, so check its class rather than its text.
		if ( $keyring instanceof WP_Secrets_Broken_Keyring ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_key_source',
				__( 'Secrets API key source is broken' ),
				'critical',
				sprintf(
					'<p>%s</p>',
					esc_html__( 'The active keyring did not load correctly. No secret can be encrypted or decrypted until this is fixed.' )
				)
			);
		}

		/*
		 * Once a root key exists, it is wrapped with the current site key, and core has no way
		 * to re-wrap it under another one. Changing the key source then locks every stored
		 * secret, and blocks saving new ones, until the change is reverted.
		 */
		$has_root_key = false !== get_site_option( WP_Secrets_Key_Manager::ROOT_KEY_OPTION );

		if ( $has_root_key ) {
			$root_key = _wp_secrets_get_key_manager()->get_root_key();

			if ( is_wp_error( $root_key ) ) {
				/*
				 * Only the default keyring reads wp-config.php. A keyring from a secrets.php
				 * drop-in fails for its own reasons, such as an unreachable key service, and
				 * its error is the useful part.
				 */
				$cause = $keyring instanceof WP_Secrets_Config_Key_Provider
					? sprintf(
						/* translators: %s: WP_SECRETS_KEY */
						__( 'This happens when %s was changed or removed after secrets were stored. Restoring the previous value fixes it.' ),
						'<code>WP_SECRETS_KEY</code>'
					)
					: sprintf(
						/* translators: 1: secrets.php, 2: Error message from the keyring. */
						__( 'The keyring of the %1$s drop-in reported: %2$s' ),
						'<code>secrets.php</code>',
						esc_html( $root_key->get_error_message() )
					);

				return $this->get_secrets_api_test_result(
					'secrets_api_key_source',
					__( 'Secrets API cannot unlock the stored secrets' ),
					'critical',
					sprintf(
						'<p>%s</p><p>%s</p>',
						esc_html__( 'The configured key cannot decrypt the root key that protects the stored secrets, so no secret can be read or saved.' ),
						$cause
					)
				);
			}

			wp_secrets_memzero( $root_key );
		}

		if ( WP_Secrets_Config_Key_Provider::KEY_STATUS_LEGACY === $key_status ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_key_source',
				__( 'Secrets API key is not in the recommended form' ),
				'recommended',
				sprintf(
					'<p>%s</p>',
					$has_root_key
						? sprintf(
							/* translators: %s: WP_SECRETS_KEY */
							__( '%s is defined, but not as a base64-encoded random 32-byte value. Keep its current value: secrets are already encrypted with it, and changing it makes them unreadable.' ),
							'<code>WP_SECRETS_KEY</code>'
						)
						: sprintf(
							/* translators: %s: WP_SECRETS_KEY */
							__( '%s is defined, but not as a base64-encoded random 32-byte value. No secrets are stored yet, so it can still be replaced with such a value.' ),
							'<code>WP_SECRETS_KEY</code>'
						)
				)
			);
		}

		if ( WP_Secrets_Config_Key_Provider::KEY_STATUS_UNDEFINED === $key_status || WP_Secrets_Config_Key_Provider::KEY_STATUS_UNUSABLE === $key_status ) {
			$is_defined = WP_Secrets_Config_Key_Provider::KEY_STATUS_UNUSABLE === $key_status;
			$example    = sprintf( "define( 'WP_SECRETS_KEY', '%s' );", base64_encode( random_bytes( 32 ) ) );

			return $this->get_secrets_api_test_result(
				'secrets_api_key_source',
				__( 'Secrets API has no encryption key' ),
				'recommended',
				sprintf(
					'<p>%s</p><p>%s</p><p><code>%s</code></p><p>%s</p>',
					$is_defined
						? sprintf(
							/* translators: 1: WP_SECRETS_KEY, 2: wp-config.php, 3: wp-config-sample.php */
							__( '%1$s is defined in %2$s, but its value cannot be used: it is empty, still the placeholder from %3$s, or the same as one of the authentication keys and salts. Plugins cannot store API keys and other credentials with the Secrets API until it is fixed.' ),
							'<code>WP_SECRETS_KEY</code>',
							'<code>wp-config.php</code>',
							'<code>wp-config-sample.php</code>'
						)
						: sprintf(
							/* translators: 1: WP_SECRETS_KEY, 2: wp-config.php */
							__( '%1$s is not defined in %2$s. Plugins cannot store API keys and other credentials with the Secrets API until it is.' ),
							'<code>WP_SECRETS_KEY</code>',
							'<code>wp-config.php</code>'
						),
					$is_defined
						? sprintf(
							/* translators: 1: WP_SECRETS_KEY, 2: wp-config.php */
							__( 'Replace the %1$s line in %2$s with this one:' ),
							'<code>WP_SECRETS_KEY</code>',
							'<code>wp-config.php</code>'
						)
						: sprintf(
							/* translators: 1: wp-config.php, 2: Translated version of "That's all, stop editing! Happy publishing." */
							__( 'Add this line to %1$s, above the line reading %2$s:' ),
							'<code>wp-config.php</code>',
							'<code>/* ' . __( 'That&#8217;s all, stop editing! Happy publishing.' ) . ' */</code>'
						),
					esc_html( $example ),
					esc_html__( 'A new value is generated each time this page loads. Keep a copy of the one you use with your backups: secrets stored with it cannot be read without it.' )
				)
			);
		}

		return $this->get_secrets_api_test_result(
			'secrets_api_key_source',
			__( 'Secrets API is using a dedicated key' ),
			'good',
			sprintf( '<p>%s</p>', esc_html( $key_source ) )
		);
	}

	/**
	 * Tests whether every stored secret still decrypts.
	 *
	 * Network secrets are included only for a super admin on a multisite install –
	 * never shown to a site administrator who is not one.
	 *
	 * @since 7.2.0
	 *
	 * @return array The test result.
	 *
	 * @phpstan-return array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string}
	 */
	public function get_test_secrets_api_undecryptable(): array {
		$broken = $this->get_undecryptable_secrets( false );

		if ( ! is_wp_error( $broken ) && is_multisite() && is_super_admin() ) {
			$network_broken = $this->get_undecryptable_secrets( true );
			$broken         = is_wp_error( $network_broken ) ? $network_broken : array_merge( $broken, $network_broken );
		}

		// Secrets that cannot be listed cannot be checked, which must not read as all of them decrypting.
		if ( is_wp_error( $broken ) ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_undecryptable',
				__( 'Secrets could not be checked' ),
				'critical',
				sprintf(
					'<p>%s</p><p>%s</p>',
					esc_html__( 'The stored secrets could not be listed, so none of them could be checked. Secrets probably cannot be read or written either until this is fixed.' ),
					esc_html( $broken->get_error_message() )
				)
			);
		}

		if ( empty( $broken ) ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_undecryptable',
				__( 'All secrets can be decrypted' ),
				'good',
				sprintf( '<p>%s</p>', esc_html__( 'Every stored secret decrypted successfully.' ) )
			);
		}

		$items = '';

		foreach ( $broken as $entry ) {
			$items .= sprintf(
				'<li>%s (%s)</li>',
				esc_html( $entry['name'] ),
				sprintf(
					/* translators: %s: A secret's last known fingerprint. */
					esc_html__( 'fingerprint: %s' ),
					esc_html( $entry['fingerprint'] )
				)
			);
		}

		return $this->get_secrets_api_test_result(
			'secrets_api_undecryptable',
			__( 'Some secrets cannot be decrypted' ),
			'critical',
			sprintf(
				'<p>%s</p><ul>%s</ul><p>%s</p>',
				esc_html__( 'The following secrets exist but could not be decrypted with the current key.' ),
				$items,
				sprintf(
					/* translators: %s: WP_SECRETS_KEY */
					__( 'This can happen after %s was changed or removed, after restoring a database backup without its matching key, or after a failed key rotation. Restoring the previous key makes the secrets readable again. Otherwise, each credential must be entered again.' ),
					'<code>WP_SECRETS_KEY</code>'
				)
			)
		);
	}

	/**
	 * Tests whether any secrets are flagged as needing rotation.
	 *
	 * @since 7.2.0
	 *
	 * @return array The test result.
	 *
	 * @phpstan-return array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string}
	 */
	public function get_test_secrets_api_needs_rotation(): array {
		$count = $this->count_secrets_needing_rotation( false );

		if ( ! is_wp_error( $count ) && is_multisite() && is_super_admin() ) {
			$network_count = $this->count_secrets_needing_rotation( true );
			$count         = is_wp_error( $network_count ) ? $network_count : $count + $network_count;
		}

		if ( is_wp_error( $count ) ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_needs_rotation',
				__( 'Secrets could not be checked for pending rotation' ),
				'recommended',
				sprintf( '<p>%s</p>', esc_html( $count->get_error_message() ) )
			);
		}

		if ( 0 === $count ) {
			return $this->get_secrets_api_test_result(
				'secrets_api_needs_rotation',
				__( 'No secrets are pending rotation' ),
				'good',
				sprintf( '<p>%s</p>', esc_html__( 'No secret is flagged as needing rotation.' ) )
			);
		}

		return $this->get_secrets_api_test_result(
			'secrets_api_needs_rotation',
			__( 'Some secrets are pending rotation' ),
			'recommended',
			sprintf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: Number of secrets flagged for rotation. */
						_n(
							'%d secret was imported from an existing option and is flagged for rotation. A credential that sat in a plain option has already been through whatever backups and replication paths that option went through; re-entering it with a new value is recommended.',
							'%d secrets were imported from existing options and are flagged for rotation. A credential that sat in a plain option has already been through whatever backups and replication paths that option went through; re-entering each with a new value is recommended.',
							$count
						),
						$count
					)
				)
			)
		);
	}

	/**
	 * Builds the result of a Secrets API test.
	 *
	 * @since 7.2.0
	 *
	 * @param string $test        The test's identifier, as registered in get_tests().
	 * @param string $label       Short label for the test result.
	 * @param string $status      One of 'good', 'recommended', 'critical'.
	 * @param string $description HTML description, normally one or more <p> elements.
	 * @return array The test result.
	 *
	 * @phpstan-param 'good'|'recommended'|'critical' $status
	 * @phpstan-return array{label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string, actions: string, test: string}
	 */
	private function get_secrets_api_test_result( string $test, string $label, string $status, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => $test,
		);
	}

	/**
	 * Determines whether the active secrets provider protects secrets with the keyring.
	 *
	 * A secrets.php drop-in can replace the whole provider, which then protects secrets
	 * with its own keys. The default keyring, and WP_SECRETS_KEY, are not used at all.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Secrets_Provider $provider The active provider.
	 * @return bool
	 */
	private function secrets_api_uses_keyring( WP_Secrets_Provider $provider ): bool {
		return $provider instanceof WP_Secrets_Libsodium_Provider || $provider instanceof WP_Secrets_Broken_Provider;
	}

	/**
	 * Finds the stored secrets that fail to decrypt, for one scope.
	 *
	 * @since 7.2.0
	 *
	 * @param bool $network Whether to check network-scope secrets.
	 * @return array|WP_Error List of array( 'name' => ..., 'fingerprint' => ... ),
	 *                        or WP_Error when the secrets cannot be listed.
	 *
	 * @phpstan-return list<array{name: string, fingerprint: string}>|WP_Error
	 */
	private function get_undecryptable_secrets( bool $network ) {
		$entries = $network ? wp_list_network_secrets() : wp_list_secrets();

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$broken = array();

		foreach ( $entries as $entry ) {
			$secret = $network ? wp_get_network_secret( $entry['name'] ) : wp_get_secret( $entry['name'] );

			if ( is_wp_error( $secret ) ) {
				$broken[] = array(
					'name'        => $entry['name'],
					'fingerprint' => $entry['fingerprint'],
				);
			}
		}

		return $broken;
	}

	/**
	 * Counts the stored secrets flagged as needing rotation, for one scope.
	 *
	 * @since 7.2.0
	 *
	 * @param bool $network Whether to check network-scope secrets.
	 * @return int|WP_Error The number of secrets, or WP_Error when the secrets cannot be listed.
	 *
	 * @phpstan-return int<0, max>|WP_Error
	 */
	private function count_secrets_needing_rotation( bool $network ) {
		$entries = $network ? wp_list_network_secrets() : wp_list_secrets();

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$count = 0;

		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['needs_rotation'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Gets the Secrets API section of the debug data.
	 *
	 * Counts and class names only – no secret values, and no fingerprints. Network
	 * scope figures are included only for a super admin on a multisite install.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, string|array> The Secrets API debug data.
	 */
	private static function get_wp_secrets_api(): array {
		$key_manager = _wp_secrets_get_key_manager();
		$provider    = _wp_secrets_get_provider();

		// A provider from a secrets.php drop-in protects secrets with its own keys, not the keyring.
		$uses_keyring = $provider instanceof WP_Secrets_Libsodium_Provider || $provider instanceof WP_Secrets_Broken_Provider;

		$fields = array(
			'dropin_active'  => array(
				'label' => __( 'Drop-in active' ),
				'value' => wp_using_secrets_dropin() ? __( 'Yes' ) : __( 'No' ),
			),
			'provider_class' => array(
				'label' => __( 'Provider class' ),
				'value' => get_class( $provider ),
			),
			'protected_by'   => array(
				'label' => __( 'Secrets protected by' ),
				'value' => $provider->get_label(),
			),
			'protection_at'  => array(
				'label' => __( 'Encryption boundary' ),
				'value' => WP_Secrets_Provider::BOUNDARY_WORDPRESS === $provider->get_protection_boundary()
					? __( 'WordPress' )
					: __( 'The provider (outside WordPress)' ),
			),
			'writable'       => array(
				'label' => __( 'Accepts writes' ),
				'value' => $provider->is_writable() ? __( 'Yes' ) : __( 'No' ),
			),
			'keyring_class'  => array(
				'label' => __( 'Keyring class' ),
				'value' => $uses_keyring ? get_class( $key_manager->get_keyring() ) : __( 'Not used by the provider' ),
			),
			'key_source'     => array(
				'label' => __( 'Key source' ),
				'value' => $uses_keyring ? $key_manager->get_keyring()->get_key_source() : __( 'Not used by the provider' ),
			),
			'record_version' => array(
				'label' => __( 'Record format version' ),
				'value' => (string) WP_SECRETS_RECORD_VERSION,
			),
		);

		$site_secrets                = wp_list_secrets();
		$fields['site_secret_count'] = array(
			'label' => __( 'Site secrets' ),
			'value' => is_wp_error( $site_secrets ) ? __( 'Unavailable' ) : (string) count( $site_secrets ),
		);

		if ( is_multisite() && is_super_admin() ) {
			$network_secrets                = wp_list_network_secrets();
			$fields['network_secret_count'] = array(
				'label' => __( 'Network secrets' ),
				'value' => is_wp_error( $network_secrets ) ? __( 'Unavailable' ) : (string) count( $network_secrets ),
			);
		}

		return array(
			'label'  => __( 'Secrets API' ),
			'fields' => $fields,
		);
	}
}
