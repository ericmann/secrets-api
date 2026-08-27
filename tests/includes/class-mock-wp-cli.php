<?php
/**
 * Minimal WP-CLI test double.
 *
 * The real \WP_CLI and \WP_CLI\Utils classes only exist when running under actual
 * WP-CLI, which this test harness does not. Command classes in cli/ are only ever
 * loaded when `defined( 'WP_CLI' ) && WP_CLI`, so testing them at all requires a
 * stand-in WP_CLI to load against -- this is deliberately not a faithful
 * reimplementation of WP-CLI's runtime, only enough of its surface to drive the
 * command classes' own logic and capture what they did.
 *
 * Real WP-CLI::error() normally terminates the PHP process; this always throws
 * Mock_WP_CLI_Exit_Exception instead, carrying the intended exit code, so a test can
 * catch it and assert without ending the test run.
 *
 * Uses bracketed namespace syntax (global block, then a WP_CLI block) because this
 * file mixes global-namespace classes with a WP_CLI\format_items() function, and
 * PHP does not allow an unbracketed namespace declaration to follow other top-level
 * statements in the same file.
 *
 * @package SecretsAPI
 */

namespace {

	if ( ! class_exists( 'Mock_WP_CLI_Exit_Exception' ) ) {
		class Mock_WP_CLI_Exit_Exception extends \Exception {

			private $wp_cli_exit_code;

			public function __construct( $message, $code ) {
				parent::__construct( $message );

				$this->wp_cli_exit_code = $code;
			}

			public function get_exit_code() {
				return $this->wp_cli_exit_code;
			}
		}
	}

	if ( ! class_exists( 'WP_CLI', false ) ) {
		class WP_CLI {

			public static $log              = array();
			public static $success          = array();
			public static $warning          = array();
			public static $errors           = array();
			public static $confirm_response = true;
			public static $formatted_items  = array();

			public static function reset() {
				self::$log              = array();
				self::$success          = array();
				self::$warning          = array();
				self::$errors           = array();
				self::$confirm_response = true;
				self::$formatted_items  = array();
			}

			public static function log( $message ) {
				self::$log[] = (string) $message;
			}

			public static function line( $message = '' ) {
				self::$log[] = (string) $message;
			}

			public static function success( $message ) {
				self::$success[] = (string) $message;
			}

			public static function warning( $message ) {
				self::$warning[] = (string) $message;
			}

			/**
			 * @param string $message      Error message.
			 * @param bool   $should_exit  Real WP-CLI accepts int|bool here; this
			 *                             codebase only ever passes bool.
			 *
			 * @throws Mock_WP_CLI_Exit_Exception If $should_exit is true.
			 */
			public static function error( $message, $should_exit = true ) {
				self::$errors[] = (string) $message;

				if ( $should_exit ) {
					throw new Mock_WP_CLI_Exit_Exception( (string) $message, 1 );
				}
			}

			/**
			 * @param int $code Exit code.
			 *
			 * @throws Mock_WP_CLI_Exit_Exception Always.
			 */
			public static function halt( $code ) {
				throw new Mock_WP_CLI_Exit_Exception( 'halt', $code );
			}

			/**
			 * @param string $question   Ignored by this test double.
			 * @param array  $assoc_args Checked for 'yes'.
			 *
			 * @throws Mock_WP_CLI_Exit_Exception If declined.
			 */
			public static function confirm( $question, $assoc_args = array() ) {
				if ( ! empty( $assoc_args['yes'] ) ) {
					return true;
				}

				if ( ! self::$confirm_response ) {
					throw new Mock_WP_CLI_Exit_Exception( 'confirmation declined', 1 );
				}

				return true;
			}

			/**
			 * @param string $name          Command name. Ignored by this test double.
			 * @param string $command_class Command class name. Ignored by this test double.
			 */
			public static function add_command( $name, $command_class ) {
				// No-op: this test double never dispatches real subcommands.
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		/**
		 * Simplified stand-in for \WP_CLI\Utils\format_items(). Records exactly what
		 * it was given, and does just enough real formatting for 'ids' and 'json'
		 * that a test asserting on WP_CLI::$log has something meaningful to check.
		 *
		 * @param string $format Output format.
		 * @param array  $items  Items to format.
		 * @param array  $fields Fields to include.
		 */
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$formatted_items[] = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);

			if ( 'ids' === $format ) {
				$ids = array();

				foreach ( $items as $item ) {
					$ids[] = is_array( $item ) ? reset( $item ) : $item;
				}

				\WP_CLI::log( implode( ' ', $ids ) );

				return;
			}

			if ( 'json' === $format ) {
				\WP_CLI::log( wp_json_encode( array_values( $items ) ) );

				return;
			}

			\WP_CLI::log( sprintf( '[%s output, %d item(s)]', $format, count( $items ) ) );
		}
	}
}
