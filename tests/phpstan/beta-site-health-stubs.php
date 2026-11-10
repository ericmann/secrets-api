<?php
/**
 * The Site Health test functions of the 7.2 betas before r65394, for static
 * analysis. r65394 replaced them with WP_Site_Health methods, but cli/ still
 * calls them on those betas. Never loaded at runtime.
 *
 * @package SecretsAPI
 */

/**
 * @phpstan-return array{test: string, label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string}
 */
function wp_secrets_site_health_test_key_source(): array {}

/**
 * @phpstan-return array{test: string, label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string}
 */
function wp_secrets_site_health_test_undecryptable(): array {}

/**
 * @phpstan-return array{test: string, label: string, status: 'good'|'recommended'|'critical', badge: array{label: string, color: string}, description: string}
 */
function wp_secrets_site_health_test_needs_rotation(): array {}
