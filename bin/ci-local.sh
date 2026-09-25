#!/usr/bin/env bash
#
# Clone to green in two commands:
#
#   composer install
#   bin/ci-local.sh
#
# Brings up wp-env (Docker), runs the same targets CI runs, and tears down.
# Pass --keep to leave the environment running between iterations.
#
# wp-env ships the WordPress core PHPUnit suite in its tests container and points
# WP_TESTS_DIR at it, so bin/install-wp-tests.sh is not needed on this path. That
# script exists for the no-Docker route -- see docs/reference/ci.md.
#
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIR_NAME=$(basename "$PWD")
CONTAINER_CWD="/var/www/html/wp-content/plugins/${PLUGIN_DIR_NAME}"

KEEP=false
for arg in "$@"; do
	case "$arg" in
		--keep) KEEP=true ;;
		*) echo "unknown option: $arg" >&2; exit 1 ;;
	esac
done

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required. See docs/reference/ci.md for the no-Docker path." >&2
	exit 1
fi

if ! docker info >/dev/null 2>&1; then
	echo "The Docker daemon is not running." >&2
	exit 1
fi

WP_ENV=(npx --yes @wordpress/env)

cleanup() {
	if [ "$KEEP" = false ]; then
		echo "==> Stopping wp-env"
		"${WP_ENV[@]}" stop >/dev/null 2>&1 || true
	else
		echo "==> Leaving wp-env running (--keep). Stop it with: npx @wordpress/env stop"
	fi
}
trap cleanup EXIT

echo "==> Starting wp-env"
"${WP_ENV[@]}" start --update

# Static analysis runs on the host: it needs no database and no WordPress.
echo "==> lint"
composer exec -- phpcs

echo "==> compat"
composer exec -- phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- \
	--extensions=php --ignore='vendor/*,node_modules/*' src plugin cli secrets-api.php

echo "==> analyse"
composer exec -- phpstan analyse --memory-limit=1G --no-progress

echo "==> test (single site)"
"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" tests-cli \
	vendor/bin/phpunit

echo "==> test (multisite)"
"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" tests-cli \
	env WP_MULTISITE=1 vendor/bin/phpunit -c phpunit-multisite.xml.dist

# The smoke test provisions its own throwaway install under .smoke/, with its
# own database (wordpress_smoke), inside wp-env's *development* `cli`
# container -- never the `tests-cli` container or its wordpress_test
# database, which the PHPUnit suite above owns. The two scripts run directly,
# rather than through `make smoke`, because the `cli` container has no `make`.
echo "==> smoke (WP-CLI against a throwaway install)"
"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" cli \
	env DB_HOST=mysql DB_USER=root DB_PASS=password bash bin/smoke-install.sh
"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" cli \
	bash tests/smoke/smoke.sh

echo
echo "All green."
