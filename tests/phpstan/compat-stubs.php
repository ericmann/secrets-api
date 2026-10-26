<?php
/**
 * Declarations of the PHP 8 polyfills core defines in wp-includes/compat.php,
 * for static analysis at the plugin's PHP 7.4 floor. The WordPress stubs leave
 * compat.php out, so without these PHPStan reports every polyfilled call in
 * src/ as an unknown function. Core's own PHPStan run analyzes compat.php
 * directly and needs nothing like this.
 *
 * Signatures and docblocks match compat.php. Never loaded at runtime.
 *
 * @package SecretsAPI
 */

/**
 * Polyfill for `str_contains()` function added in PHP 8.0.
 *
 * @since 5.9.0
 *
 * @param string $haystack The string to search in.
 * @param string $needle   The substring to search for in the `$haystack`.
 * @return bool True if `$needle` is in `$haystack`, otherwise false.
 */
function str_contains( $haystack, $needle ) {}

/**
 * Polyfill for `str_starts_with()` function added in PHP 8.0.
 *
 * @since 5.9.0
 *
 * @param string $haystack The string to search in.
 * @param string $needle   The substring to search for in the `$haystack`.
 * @return bool True if `$haystack` starts with `$needle`, otherwise false.
 */
function str_starts_with( $haystack, $needle ) {}
