#!/usr/bin/env bash
#
# Prints the WordPress version to install for a requested version.
#
# A branch with a trailing ".x", such as 7.1.x, means the newest release on that
# branch. The version-check API lists the newest release of every supported branch
# among its offers, so a CI matrix pinned to 7.1.x follows 7.1 security releases and
# never moves to 7.2. Anything else, such as latest, trunk, nightly, 6.8 or 7.1.4,
# is printed unchanged.
#
#   WP_API_BASE   substituted for https://api.wordpress.org (see docs/reference/ci.md)
#
set -euo pipefail

if [ $# -ne 1 ]; then
	echo "usage: $0 <wp-version>" >&2
	exit 1
fi

REQUESTED=$1

if [[ ! $REQUESTED =~ ^([0-9]+)\.([0-9]+)\.x$ ]]; then
	echo "$REQUESTED"
	exit 0
fi

BRANCH_PATTERN="\"version\":\"(${BASH_REMATCH[1]}\\.${BASH_REMATCH[2]}\\.[0-9]+)\""
API_URL="${WP_API_BASE-https://api.wordpress.org}/core/version-check/1.7/"

if command -v curl >/dev/null 2>&1; then
	OFFERS=$(curl -fsSL "$API_URL")
elif command -v wget >/dev/null 2>&1; then
	OFFERS=$(wget -qO- "$API_URL")
else
	echo "Neither curl nor wget is available." >&2
	exit 1
fi

# Matched with bash's own regex rather than grep|head, which under pipefail can be
# killed by SIGPIPE (see bin/install-wp-tests.sh). The offers are newest first, so
# the first match is the newest release on the branch.
if [[ $OFFERS =~ $BRANCH_PATTERN ]]; then
	echo "${BASH_REMATCH[1]}"
else
	echo "The version-check API at $API_URL lists no release for $REQUESTED." >&2
	exit 1
fi
