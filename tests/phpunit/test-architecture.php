<?php
/**
 * Static/architectural tests: read the source and assert structure.
 *
 * These encode promises that are easy to break by accident over a long build and are
 * deliberately placed here, immediately after the first end-to-end commit, so drift
 * fails loudly from the next commit onward instead of being discovered at the end.
 * Never weaken one of these to make a build green -- if one fails, the code is wrong,
 * or the spec needs an operator decision. See docs/open-questions.md.
 *
 * @group secrets
 * @group architecture
 */
class Tests_Secrets_Architecture extends WP_UnitTestCase {

	/**
	 * Every .php file under src/, as absolute paths.
	 *
	 * @return string[]
	 */
	private function src_files() {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WP_SECRETS_API_PLUGIN_DIR . 'src', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$this->assertNotEmpty( $files, 'PROBE: src_files() found nothing -- the plugin directory constant or src/ layout has changed.' );

		return $files;
	}

	/**
	 * No apply_filters() anywhere in src/. Not just the retrieval path: nothing
	 * core-bound filters a secret, its name, or which store/keyring serves it.
	 * Allowlist nothing -- a single exception here is a hook that can intercept a
	 * credential, and a filter that can intercept a credential is a filter that can
	 * steal one.
	 */
	public function test_no_apply_filters_anywhere_in_src() {
		foreach ( $this->src_files() as $file ) {
			$this->assertStringNotContainsString(
				'apply_filters',
				file_get_contents( $file ),
				"apply_filters() found in {$file}. There is no filter anywhere in core-bound code."
			);
		}
	}

	/**
	 * PHPCompatibilityWP is wired into the ruleset at the 7.4 floor. This does not
	 * re-scan src/ for PHP 8 syntax itself -- that is make compat's job, and
	 * duplicating it here would just be a slower, worse copy of the same check. This
	 * exists so the configuration that makes that check happen cannot be silently
	 * removed or loosened without a test noticing.
	 */
	public function test_phpcompatibility_ruleset_is_wired_at_the_7_4_floor() {
		$config = file_get_contents( WP_SECRETS_API_PLUGIN_DIR . 'phpcs.xml.dist' );

		$this->assertStringContainsString( 'PHPCompatibilityWP', $config );
		$this->assertMatchesRegularExpression(
			'/<config\s+name="testVersion"\s+value="7\.4-"\s*\/>/',
			$config,
			'PHPCompatibilityWP testVersion must be pinned to "7.4-" (the trailing hyphen means "7.4 and up", not "exactly 7.4").'
		);
	}

	/**
	 * The src/ directory is destined to be copied verbatim into wordpress-develop.
	 * Nothing in it may reference the CLI-only or plugin-only layers, which are
	 * never copied.
	 */
	public function test_no_wp_cli_or_plugin_only_references_in_src() {
		foreach ( $this->src_files() as $file ) {
			$contents = file_get_contents( $file );

			$this->assertStringNotContainsString( 'WP_CLI', $contents, "WP_CLI reference found in {$file}." );
			$this->assertStringNotContainsString( 'plugin/', $contents, "Reference to the plugin-only plugin/ directory found in {$file}." );
			$this->assertStringNotContainsString( 'cli/', $contents, "Reference to the CLI-only cli/ directory found in {$file}." );
		}
	}

	/**
	 * The src/ directory declares no function_exists()/class_exists() guard on one
	 * of this API's own symbols (wp_* or WP_*). The entire no-op decision lives in
	 * one place -- secrets-api.php -- specifically because a guard here would double as an
	 * overloading surface: an mu-plugin declaring wp_get_secret() first would
	 * silently win, and every secret read on the site would flow through it. This
	 * does not forbid function_exists()/class_exists() outright: probing for a
	 * third-party capability (sodium_crypto_*, sodium_memzero) is unrelated and
	 * expected to keep appearing as the crypto layer grows. See
	 * the bootstrap docblock in secrets-api.php, which is where that decision lives.
	 */
	public function test_no_self_guarding_function_exists_in_src() {
		foreach ( $this->src_files() as $file ) {
			$matches = array();
			preg_match_all(
				'/\b(?:function_exists|class_exists)\s*\(\s*[\'"](wp_[a-zA-Z0-9_]*|WP_[a-zA-Z0-9_]*)[\'"]/',
				file_get_contents( $file ),
				$matches
			);

			$this->assertSame(
				array(),
				$matches[1],
				sprintf( 'Self-guarding function_exists()/class_exists() on %s found in %s.', implode( ', ', $matches[1] ), $file )
			);
		}
	}

	/**
	 * Every core-bound file carries @since 7.2.0, so a file-copy into
	 * wordpress-develop needs no docblock editing.
	 */
	public function test_every_src_file_carries_since_7_2_0() {
		foreach ( $this->src_files() as $file ) {
			$this->assertStringContainsString( '@since 7.2.0', file_get_contents( $file ), "Missing \"@since 7.2.0\" in {$file}." );
		}
	}

	/**
	 * The src/ directory uses only the 'default' text domain, since it ships as core
	 * rather than as a plugin with its own domain.
	 */
	public function test_src_uses_only_the_default_text_domain() {
		foreach ( $this->src_files() as $file ) {
			$matches = array();
			preg_match_all(
				"/\b(?:__|_e|_x|_ex|_n|_nx)\(\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*(?:,\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*)?,\s*'([a-z0-9_-]+)'\s*\)/",
				file_get_contents( $file ),
				$matches
			);

			foreach ( $matches[1] as $domain ) {
				$this->assertSame( 'default', $domain, "Non-default text domain \"{$domain}\" found in {$file}." );
			}
		}
	}

	/**
	 * The src/ directory mirrors wordpress-develop's own directory shape: everything
	 * lives directly under wp-includes or wp-admin/includes, with no additional
	 * subdirectories a core patch would have to flatten.
	 */
	public function test_src_has_no_unexpected_subdirectories() {
		$allowed = array(
			WP_SECRETS_API_PLUGIN_DIR . 'src/wp-includes',
			WP_SECRETS_API_PLUGIN_DIR . 'src/wp-admin',
			WP_SECRETS_API_PLUGIN_DIR . 'src/wp-admin/includes',
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WP_SECRETS_API_PLUGIN_DIR . 'src', FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				$this->assertContains( rtrim( $item->getPathname(), '/' ), $allowed, "Unexpected directory under src/: {$item->getPathname()}" );
			}
		}
	}

	// -- prototype-compatibility containment --------------------------------

	/**
	 * No core-bound file references any prototype-compatibility symbol by name.
	 *
	 * Reference by path is already caught by
	 * test_no_wp_cli_or_plugin_only_references_in_src(); a reference made by class
	 * name would not contain the string 'plugin/' and would otherwise pass
	 * silently.
	 */
	public function test_no_prototype_compat_symbols_in_src() {
		$symbols = array(
			'Secrets_API_Legacy_Reader',
			'Secrets_API_Migrator',
			'Secrets_API_Prototype_Fallback_Store',
		);

		foreach ( $this->src_files() as $file ) {
			$contents = file_get_contents( $file );

			foreach ( $symbols as $symbol ) {
				$this->assertStringNotContainsString(
					$symbol,
					$contents,
					"Prototype-compatibility symbol {$symbol} referenced in core-bound file {$file}."
				);
			}
		}
	}

	/**
	 * The bulk migrator loads only under WP-CLI.
	 *
	 * The read-time fallback store and the reader it depends on legitimately load
	 * on every request -- that is the whole point of them. The migrator does not:
	 * it is a one-shot operator tool, and hoisting it out of the WP-CLI guard
	 * would pass every other test in the suite while quietly loading a class no
	 * web request can reach.
	 */
	public function test_the_migrator_loads_only_under_wp_cli() {
		$bootstrap = file_get_contents( WP_SECRETS_API_PLUGIN_DIR . 'secrets-api.php' );

		$cli_guard = strpos( $bootstrap, "if ( defined( 'WP_CLI' ) && WP_CLI ) {" );

		$this->assertNotFalse( $cli_guard, 'The WP-CLI guard is no longer recognisable in secrets-api.php.' );

		$matches = array();
		preg_match_all(
			'/require_once[^;]*' . preg_quote( 'plugin/class-secrets-api-migrator.php', '/' ) . '/',
			$bootstrap,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		$this->assertNotEmpty( $matches[0], 'No require_once found for the migrator.' );

		foreach ( $matches[0] as $match ) {
			$this->assertGreaterThan(
				$cli_guard,
				$match[1],
				'The migrator is required outside the WP-CLI guard -- it would load on every request.'
			);
		}
	}

	/**
	 * Nothing in this plugin writes to or deletes an option the prototype owns.
	 *
	 * This is the load-bearing promise to the team still running that code: both
	 * systems share a site, and ours is strictly a reader of theirs. It is checked
	 * statically rather than only behaviourally because the dangerous version of
	 * this mistake is a code path nobody thought to write a test for -- a cleanup
	 * step, a "tidy up after migrating" convenience, an uninstall routine.
	 *
	 * The reader is exempt for get_option() specifically: reading is its job.
	 */
	public function test_never_writes_to_a_prototype_owned_option() {
		$forbidden = array( 'update_option', 'delete_option', 'add_option' );

		$files = glob( WP_SECRETS_API_PLUGIN_DIR . 'plugin/*.php' );
		$files = array_merge( $files, glob( WP_SECRETS_API_PLUGIN_DIR . 'cli/*.php' ) );
		$files = array_merge( $files, glob( WP_SECRETS_API_PLUGIN_DIR . 'src/wp-includes/*.php' ) );

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );

			foreach ( $forbidden as $call ) {
				$matches = array();
				preg_match_all( '/' . $call . '\s*\(\s*([^,)]+)/', $contents, $matches );

				foreach ( $matches[1] as $argument ) {
					$this->assertStringNotContainsString(
						"'_secret_",
						$argument,
						"{$call}() targets a prototype-owned option in {$file}."
					);
					$this->assertStringNotContainsString(
						"'_secrets_master_key'",
						$argument,
						"{$call}() targets the prototype's master key in {$file}."
					);
				}
			}
		}
	}
}
