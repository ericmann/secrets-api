#!/usr/bin/env bash
#
# Provision a throwaway single-site WordPress install for the WP-CLI smoke test.
#
# This script owns everything under .smoke/: a pinned wp-cli.phar, its download
# cache, and a full WordPress checkout with its own "wordpress_smoke" database.
# It never touches wp-env's own dev or test environment, because that one shares
# wp-content with PHPUnit -- and a drop-in the smoke test writes would sit in
# front of the PHPUnit suite. See tests/smoke/SPEC.md.
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Pinned wp-cli release. Resolved from
# https://api.github.com/repos/wp-cli/wp-cli/releases/latest on 2026-09-24 and
# verified against that release's own published
# wp-cli-2.12.0.phar.sha256 before computing this digest -- the same discipline
# ci.yml applies to action SHAs.
WP_CLI_VERSION="2.12.0"
WP_CLI_SHA256="ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c"
WP_CLI_URL="https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"

SMOKE_DIR="$PWD/.smoke"
SMOKE_DB_NAME="${SMOKE_DB_NAME:-wordpress_smoke}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
WP_VERSION="${WP_VERSION:-latest}"
SMOKE_URL="${SMOKE_URL:-http://smoke.test}"

# The PHPUnit suite drops and recreates wordpress_test on every run; this
# script drops and recreates whatever SMOKE_DB_NAME names. Refusing when they
# collide protects the PHPUnit suite's database from the smoke test, and vice
# versa.
if [ "$SMOKE_DB_NAME" = "wordpress_test" ]; then
	echo "SMOKE_DB_NAME must not be wordpress_test: that database belongs to the PHPUnit suite." >&2
	exit 1
fi

export WP_CLI_CACHE_DIR="$SMOKE_DIR/cache"

WP=( php -d display_errors=stderr -d log_errors=0 "$SMOKE_DIR/wp-cli.phar" --path="$SMOKE_DIR/wordpress" --allow-root )

download() {
	# $1 = URL, $2 = destination path.
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		php -r 'copy($argv[1], $argv[2]);' -- "$1" "$2"
	fi
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	else
		shasum -a 256 "$1" | awk '{print $1}'
	fi
}

fetch_wp_cli() {
	mkdir -p "$SMOKE_DIR" "$WP_CLI_CACHE_DIR"

	if [ -f "$SMOKE_DIR/wp-cli.phar" ]; then
		ACTUAL=$(sha256_of "$SMOKE_DIR/wp-cli.phar")
		if [ "$ACTUAL" = "$WP_CLI_SHA256" ]; then
			return
		fi
	fi

	download "$WP_CLI_URL" "$SMOKE_DIR/wp-cli.phar"

	ACTUAL=$(sha256_of "$SMOKE_DIR/wp-cli.phar")
	if [ "$ACTUAL" != "$WP_CLI_SHA256" ]; then
		rm -f "$SMOKE_DIR/wp-cli.phar"
		echo "wp-cli.phar checksum mismatch." >&2
		echo "expected: $WP_CLI_SHA256" >&2
		echo "actual:   $ACTUAL" >&2
		exit 1
	fi
}

fetch_wp_cli

if [ ! -f "$SMOKE_DIR/wordpress/wp-load.php" ]; then
	mkdir -p "$SMOKE_DIR/wordpress"
	"${WP[@]}" core download --version="$WP_VERSION"
fi

# Every run starts single-site with no drop-in: a leftover secrets.php or
# multisite wp-config would make this script's own idempotence lie about what
# state a fresh run leaves behind.
rm -f "$SMOKE_DIR/wordpress/wp-config.php" "$SMOKE_DIR/wordpress/wp-content/secrets.php"

"${WP[@]}" config create \
	--dbname="$SMOKE_DB_NAME" \
	--dbuser="$DB_USER" \
	--dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" \
	--skip-check \
	--force

# Drop and recreate the database with mysqli directly, rather than a `wp db`
# subcommand or the `mysql` client binary, so this script stays dependency-free
# beyond wp and php. Host/port/socket parsing mirrors install_db() in
# bin/install-wp-tests.sh. Arguments are passed as $argv, never interpolated
# into the PHP source.
IFS=':' read -r DB_HOSTNAME DB_SOCK_OR_PORT <<< "$DB_HOST"
DB_PORT=""
DB_SOCKET=""
if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
	DB_PORT="$DB_SOCK_OR_PORT"
elif [ -n "${DB_SOCK_OR_PORT:-}" ]; then
	DB_SOCKET="$DB_SOCK_OR_PORT"
fi

php -r '
	list( $host, $user, $pass, $name, $port, $socket ) = array_slice( $argv, 1 );

	$port   = $port !== "" ? (int) $port : null;
	$socket = $socket !== "" ? $socket : null;

	mysqli_report( MYSQLI_REPORT_OFF );
	$link = mysqli_init();

	if ( ! $link || ! @mysqli_real_connect( $link, $host, $user, $pass, "", $port, $socket ) ) {
		fwrite( STDERR, "mysqli connect error: " . mysqli_connect_error() . "\n" );
		exit( 1 );
	}

	if ( ! mysqli_query( $link, "DROP DATABASE IF EXISTS `" . $name . "`" ) ) {
		fwrite( STDERR, "mysqli error: " . mysqli_error( $link ) . "\n" );
		exit( 1 );
	}

	if ( ! mysqli_query( $link, "CREATE DATABASE `" . $name . "`" ) ) {
		fwrite( STDERR, "mysqli error: " . mysqli_error( $link ) . "\n" );
		exit( 1 );
	}
' -- "$DB_HOSTNAME" "$DB_USER" "$DB_PASS" "$SMOKE_DB_NAME" "$DB_PORT" "$DB_SOCKET"

"${WP[@]}" core install \
	--url="$SMOKE_URL" \
	--title="Secrets API smoke" \
	--admin_user=smoke \
	--admin_password="$(php -r 'echo bin2hex(random_bytes(16));')" \
	--admin_email=smoke@example.com \
	--skip-email

# Relative on purpose: the same link resolves on the host and inside the
# wp-env container.
ln -sfn ../../../.. "$SMOKE_DIR/wordpress/wp-content/plugins/secrets-api"
"${WP[@]}" plugin activate secrets-api

KEY="$("${WP[@]}" secret generate-key)"
# --quiet: `wp config set` otherwise echoes the constant's value in its
# "Success" line, which would put the generated key in plain sight.
"${WP[@]}" config set WP_SECRETS_KEY "$KEY" --type=constant --quiet

echo "Smoke install ready: $SMOKE_DIR/wordpress (database $SMOKE_DB_NAME)"
