<?php
/**
 * Secrets API: migrator (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

/**
 * Migrates secrets from displace-secrets-manager's legacy format into the new one.
 *
 * Flag semantics resolved during this build -- see docs/open-questions.md #15,
 * flagged for Checkpoint F -- since the build brief's own text ("default is
 * dry-run-like safety" alongside a separate --dry-run flag) does not compose as
 * written. As implemented: writing the new-format secret is never destructive and
 * happens by default (idempotent, safe to re-run); deleting the legacy source is
 * the actually destructive action and always requires the separate $delete_source
 * flag, verified before it happens either way; $dry_run means "write nothing at
 * all," including the ordinarily-safe new-format write.
 *
 * Read-only failures (a legacy record that will not decrypt) are reported per key
 * and never abort the run -- one bad key must not block migrating the rest.
 */
final class Secrets_API_Migrator {

	/**
	 * If this class exists, the AI plugin's own vendored copy of the legacy code is
	 * writing to the same option rows this migrator reads from. Per the build
	 * brief's §9.5: warn loudly, and refuse to delete a source option out from
	 * under it without an explicit override.
	 *
	 * @var string
	 */
	const VENDORED_AI_PLUGIN_CLASS = 'WordPress\\AI\\Vendor\\Secrets\\Secrets_Manager';

	/**
	 * Migrates legacy secrets into the new format.
	 *
	 * $args accepts:
	 *
	 * - bool   $dry_run                       Write nothing; report only. Default false.
	 * - string $name                          Migrate only this legacy key. Default null (all).
	 * - bool   $delete_source                 Delete each legacy option after this
	 *                                         run's own verification passes. Default false.
	 * - array  $map                           Legacy key => explicit new name, for
	 *                                         keys whose derived name would not validate.
	 * - string $namespace                     Namespace prefixed onto a legacy key
	 *                                         with no entry in $map. Default 'legacy'.
	 * - bool   $confirm_delete_despite_vendor Delete a source option even though the
	 *                                         vendored AI plugin class was detected.
	 *                                         Corresponds to --yes. Default false.
	 *
	 * @param array $args Migration options, as described above.
	 *
	 * @return array {
	 *     @type bool  $vendor_detected Whether the vendored AI plugin class exists.
	 *     @type array $entries         One report entry per legacy key, each with
	 *                                  'legacy_key', 'new_name', 'status', 'message',
	 *                                  and, once migrated, 'fingerprint' and
	 *                                  'source_deleted'. Never a value.
	 * }
	 */
	public function migrate( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'                       => false,
				'name'                          => null,
				'delete_source'                 => false,
				'map'                           => array(),
				'namespace'                     => 'legacy',
				'confirm_delete_despite_vendor' => false,
			)
		);

		$reader = new Secrets_API_Legacy_Reader();

		if ( null !== $args['name'] ) {
			$keys = array( $args['name'] );
		} else {
			$keys = $reader->list_keys();

			if ( is_wp_error( $keys ) ) {
				return array(
					'vendor_detected' => class_exists( self::VENDORED_AI_PLUGIN_CLASS ),
					'entries'         => array(
						array(
							'legacy_key' => null,
							'new_name'   => null,
							'status'     => 'error',
							'message'    => $keys->get_error_message(),
						),
					),
				);
			}
		}

		$vendor_detected = class_exists( self::VENDORED_AI_PLUGIN_CLASS );
		$entries         = array();

		foreach ( $keys as $key ) {
			$entries[] = $this->migrate_one( $reader, $key, $args, $vendor_detected );
		}

		return array(
			'vendor_detected' => $vendor_detected,
			'entries'         => $entries,
		);
	}

	/**
	 * Resolves a single legacy key's new name, migrates it if not already done,
	 * and optionally deletes the source.
	 *
	 * @param Secrets_API_Legacy_Reader $reader          The legacy reader.
	 * @param string                    $key             Legacy key.
	 * @param array                     $args            Resolved args from migrate().
	 * @param bool                      $vendor_detected Whether the vendored AI
	 *                                                    plugin class exists.
	 *
	 * @return array One report entry.
	 */
	private function migrate_one( $reader, $key, array $args, $vendor_detected ) {
		$new_name = isset( $args['map'][ $key ] ) ? $args['map'][ $key ] : $args['namespace'] . '/' . $key;

		$entry = array(
			'legacy_key' => $key,
			'new_name'   => $new_name,
			'status'     => null,
			'message'    => '',
		);

		$name_check = wp_secrets_validate_name( $new_name );

		if ( is_wp_error( $name_check ) ) {
			$entry['status']  = 'needs_mapping';
			$entry['message'] = sprintf(
				/* translators: 1: Derived name. 2: Validation error message. 3: Legacy key. */
				__( 'Derived name "%1$s" is invalid (%2$s). Use --map=%3$s:<new-name> to specify one explicitly.', 'secrets-api' ),
				$new_name,
				$name_check->get_error_message(),
				$key
			);

			return $entry;
		}

		$existing_secret = wp_get_secret( $new_name );

		if ( is_wp_error( $existing_secret ) ) {
			$entry['status']  = 'error';
			$entry['message'] = $existing_secret->get_error_message();

			return $entry;
		}

		$already_migrated = ( $existing_secret instanceof WP_Secret );

		if ( $args['dry_run'] ) {
			$entry['status'] = $already_migrated
				? 'skipped'
				: 'would_migrate';

			return $entry;
		}

		if ( $already_migrated ) {
			$entry['status']      = 'skipped';
			$entry['fingerprint'] = $existing_secret->fingerprint();
		} else {
			$write_result = $this->write_new_secret( $reader, $key, $new_name );

			if ( is_wp_error( $write_result ) ) {
				$entry['status']  = 'error';
				$entry['message'] = $write_result->get_error_message();

				return $entry;
			}

			$entry['status']      = 'migrated';
			$entry['fingerprint'] = $write_result;
		}

		if ( $args['delete_source'] ) {
			$this->maybe_delete_source( $reader, $key, $entry, $args, $vendor_detected );
		}

		return $entry;
	}

	/**
	 * Reads, writes, and independently verifies a single key, in that order. The
	 * plaintext is held only as long as writing and verifying require, then zeroed.
	 *
	 * @param Secrets_API_Legacy_Reader $reader   The legacy reader.
	 * @param string                    $key      Legacy key.
	 * @param string                    $new_name Validated new-format name.
	 *
	 * @return string|WP_Error The new secret's fingerprint on success. WP_Error on
	 *                         failure -- including if verification fails, in which
	 *                         case the new-format write may still have happened,
	 *                         but is reported as an error rather than a success.
	 */
	private function write_new_secret( $reader, $key, $new_name ) {
		$plaintext = $reader->get( $key );

		if ( is_wp_error( $plaintext ) ) {
			return $plaintext;
		}

		$master_key = _wp_secrets_get_key_manager()->get_master_key( 'site' );

		if ( is_wp_error( $master_key ) ) {
			wp_secrets_memzero( $plaintext );

			return $master_key;
		}

		// Fingerprint of the OLD value, computed before any write, so the
		// comparison below is against something recorded independently of
		// whatever the write path itself might report.
		$expected_fingerprint = ( new WP_Secrets_Cipher() )->fingerprint( $master_key, $plaintext );

		wp_secrets_memzero( $master_key );

		if ( is_wp_error( $expected_fingerprint ) ) {
			wp_secrets_memzero( $plaintext );

			return $expected_fingerprint;
		}

		$write_result = _wp_secrets_set( $new_name, $plaintext, false, true, 'imported' );

		wp_secrets_memzero( $plaintext );

		if ( is_wp_error( $write_result ) ) {
			return $write_result;
		}

		$new_secret = wp_get_secret( $new_name );

		if ( ! ( $new_secret instanceof WP_Secret ) ) {
			return new WP_Error(
				'legacy_migration_verify_failed',
				__( 'The migrated secret could not be read back for verification.', 'secrets-api' )
			);
		}

		if ( $expected_fingerprint !== $new_secret->fingerprint() ) {
			return new WP_Error(
				'legacy_migration_verify_failed',
				__( 'The migrated value does not match the legacy source.', 'secrets-api' )
			);
		}

		return $new_secret->fingerprint();
	}

	/**
	 * Verifies a legacy key against an already-migrated new secret, then deletes
	 * the legacy option only if that verification passes -- and only if the
	 * vendored-copy check allows it.
	 *
	 * @param Secrets_API_Legacy_Reader $reader          The legacy reader.
	 * @param string                    $key             Legacy key.
	 * @param array                     $entry           Report entry, by reference.
	 * @param array                     $args            Resolved args from migrate().
	 * @param bool                      $vendor_detected Whether the vendored AI
	 *                                                    plugin class exists.
	 */
	private function maybe_delete_source( $reader, $key, array &$entry, array $args, $vendor_detected ) {
		$entry['source_deleted'] = false;

		if ( $vendor_detected && ! $args['confirm_delete_despite_vendor'] ) {
			$entry['message'] = trim(
				$entry['message'] . ' ' . __(
					"Source NOT deleted: the AI plugin's vendored Secrets_Manager writes to the same option rows. Pass --yes to delete anyway.",
					'secrets-api'
				)
			);

			return;
		}

		$plaintext = $reader->get( $key );

		if ( is_wp_error( $plaintext ) ) {
			$entry['message'] = trim( $entry['message'] . ' ' . __( 'Source NOT deleted: the legacy value could not be re-read.', 'secrets-api' ) );

			return;
		}

		$master_key = _wp_secrets_get_key_manager()->get_master_key( 'site' );

		if ( is_wp_error( $master_key ) ) {
			wp_secrets_memzero( $plaintext );
			$entry['message'] = trim( $entry['message'] . ' ' . __( 'Source NOT deleted: the current master key is unavailable.', 'secrets-api' ) );

			return;
		}

		$current_fingerprint = ( new WP_Secrets_Cipher() )->fingerprint( $master_key, $plaintext );

		wp_secrets_memzero( $master_key );
		wp_secrets_memzero( $plaintext );

		if ( is_wp_error( $current_fingerprint ) || $current_fingerprint !== $entry['fingerprint'] ) {
			$entry['message'] = trim( $entry['message'] . ' ' . __( 'Source NOT deleted: verification failed.', 'secrets-api' ) );

			return;
		}

		delete_option( '_secret_' . $key );
		$entry['source_deleted'] = true;
	}
}
