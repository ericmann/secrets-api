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

# Where the version-check API and the wordpress-develop tarballs come from. Split
# out from MIRROR_BASE because they are genuinely different hosts, and an
# air-gapped mirror of wordpress.org downloads is not automatically a mirror of
# either. Both are overridable for the same reason MIRROR_BASE is.
API_BASE=${WP_API_BASE-https://api.wordpress.org}
DEVELOP_BASE=${WP_DEVELOP_BASE-https://github.com/WordPress/wordpress-develop}

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
	# The version-check API, not wordpress.org/wp-includes/version.php: that path
	# is a PHP file on a PHP server, so requesting it executes it and returns 200
	# with an empty body. Combined with `set -o pipefail`, the parse below then
	# failed with no output at all, which is a genuinely miserable thing to debug.
	download "${API_BASE}/core/version-check/1.7/" "$TMPDIR/wp-latest.json"

	# Parsed with bash's own regex rather than grep|sed|head. Under `pipefail`, a
	# pipeline ending in `head` can be killed by SIGPIPE and take the script down
	# silently even when the parse succeeded -- the same class of failure this
	# block already caused once.
	WP_VERSION_CHECK_JSON=$(cat "$TMPDIR/wp-latest.json")

	if [[ $WP_VERSION_CHECK_JSON =~ \"version\":\"([0-9][0-9.]*)\" ]]; then
		LATEST_VERSION="${BASH_REMATCH[1]}"
	else
		echo "Could not determine the latest WordPress version from ${API_BASE}/core/version-check/1.7/." >&2
		echo "Response was ${#WP_VERSION_CHECK_JSON} bytes." >&2
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

	if [ ! -d "$WP_TESTS_DIR/includes" ] || [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		mkdir -p "$WP_TESTS_DIR"

		# Fetched as a wordpress-develop tarball rather than checked out with svn.
		# svn is not installed on GitHub's ubuntu-24.04 runners and has not shipped
		# with macOS since the Xcode command line tools dropped it, so requiring it
		# meant this script could not run unmodified in CI or on a stock Mac -- and
		# `make install` is the documented no-Docker path for contributors.
		# curl-or-wget plus tar is a dependency both already have.
		case "$WP_TESTS_TAG" in
			trunk)
				DEVELOP_REF="refs/heads/trunk"
				;;
			branches/*)
				DEVELOP_REF="refs/heads/${WP_TESTS_TAG#branches/}"
				;;
			tags/*)
				DEVELOP_TAG="${WP_TESTS_TAG#tags/}"

				# svn and git disagree about what an x.y release is called: svn
				# tags it "7.1", the git repository tags it "7.1.0". Only x.y
				# needs the ".0" -- an x.y.z release is spelled the same in both.
				if [[ $DEVELOP_TAG =~ ^[0-9]+\.[0-9]+$ ]]; then
					DEVELOP_TAG="${DEVELOP_TAG}.0"
				fi

				DEVELOP_REF="refs/tags/${DEVELOP_TAG}"
				;;
			*)
				echo "Unrecognised test suite ref: $WP_TESTS_TAG" >&2
				exit 1
				;;
		esac

		echo "Fetching the test suite from ${DEVELOP_BASE}/archive/${DEVELOP_REF}.tar.gz"

		rm -rf "$TMPDIR/wp-develop"
		mkdir -p "$TMPDIR/wp-develop"
		download "${DEVELOP_BASE}/archive/${DEVELOP_REF}.tar.gz" "$TMPDIR/wp-develop.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wp-develop.tar.gz" -C "$TMPDIR/wp-develop"

		rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
		cp -R "$TMPDIR/wp-develop/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$TMPDIR/wp-develop/tests/phpunit/data" "$WP_TESTS_DIR/data"
		cp "$TMPDIR/wp-develop/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	fi

	if [ ! -f "$WP_TESTS_DIR/.config-rewritten" ]; then
		WP_CORE_DIR_ESC=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"

		# Marker rather than re-testing the config file's contents: the rewrites
		# above are not idempotent (the second run has no 'yourusernamehere' left
		# to replace, but "s|localhost|...|" would happily rewrite a hostname that
		# merely contains it), so they must run exactly once per fetched config.
		touch "$WP_TESTS_DIR/.config-rewritten"
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

	# CREATE DATABASE IF NOT EXISTS, rather than `mysqladmin create`, so that an
	# already-present database is not a failure. Two ordinary situations hit this:
	# a CI service container that pre-creates the database itself (ours does), and
	# a contributor running `make install` a second time. Neither should require
	# dropping the database first, and neither is what this script is guarding
	# against -- a genuine connection or permission problem still fails loudly,
	# because only the "already exists" case is being tolerated, not every error.
	# shellcheck disable=SC2086
	mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA \
		--execute="CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
}

install_wp
install_test_suite
install_db

echo "WordPress test suite installed at $WP_TESTS_DIR"
