#!/usr/bin/env bash
#
# Install the WordPress core PHPUnit test suite.
#
# Adapted from the canonical script shipped by `wp scaffold plugin-tests`, with one
# addition: every download honours a configurable mirror so this works on a CI runner
# with no egress to wordpress.org. See docs/ci.md.
#
#   WP_TESTS_ZIP_URL   full URL to a WordPress tarball, overriding version lookup
#   WP_MIRROR_BASE     base URL substituted for https://wordpress.org
#
set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

MIRROR_BASE=${WP_MIRROR_BASE-https://wordpress.org}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		exit 1
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download "${MIRROR_BASE}/wp-includes/version.php" "$TMPDIR/wp-latest.php"
	LATEST_VERSION=$(grep -o "'[0-9]\+\.[0-9]\+\(\.[0-9]\+\)\?'" "$TMPDIR/wp-latest.php" | sed "s/'//g" | head -1)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Could not determine the latest WordPress version." >&2
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		echo "WordPress already present at $WP_CORE_DIR"
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [ -n "${WP_TESTS_ZIP_URL-}" ]; then
		ARCHIVE_URL="$WP_TESTS_ZIP_URL"
	elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		ARCHIVE_URL="${MIRROR_BASE}/nightly-builds/wordpress-latest.zip"
	elif [[ $WP_VERSION == 'latest' ]]; then
		ARCHIVE_URL="${MIRROR_BASE}/latest.tar.gz"
	else
		ARCHIVE_URL="${MIRROR_BASE}/wordpress-${WP_VERSION}.tar.gz"
	fi

	echo "Fetching WordPress from $ARCHIVE_URL"

	case "$ARCHIVE_URL" in
		*.zip)
			download "$ARCHIVE_URL" "$TMPDIR/wordpress.zip"
			unzip -q -o "$TMPDIR/wordpress.zip" -d "$TMPDIR/"
			mv "$TMPDIR"/wordpress/* "$WP_CORE_DIR"
			;;
		*)
			download "$ARCHIVE_URL" "$TMPDIR/wordpress.tar.gz"
			tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
			;;
	esac

	download "${MIRROR_BASE}/wp-content/db.php" "$WP_CORE_DIR/wp-content/db.php" 2>/dev/null || true
}

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		SVN_BASE=${WP_SVN_BASE-https://develop.svn.wordpress.org}
		svn co --quiet "${SVN_BASE}/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn co --quiet "${SVN_BASE}/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		SVN_BASE=${WP_SVN_BASE-https://develop.svn.wordpress.org}
		download "${SVN_BASE}/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

		WP_CORE_DIR_ESC=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return
	fi

	local PARTS
	IFS=':' read -ra PARTS <<< "$DB_HOST"
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]-}
	local EXTRA=""

	if [ -n "$DB_HOSTNAME" ]; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif [ -n "$DB_SOCK_OR_PORT" ]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		else
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# shellcheck disable=SC2086
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_wp
install_test_suite
install_db

echo "WordPress test suite installed at $WP_TESTS_DIR"
