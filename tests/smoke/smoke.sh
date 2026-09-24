#!/usr/bin/env bash
#
# WP-CLI smoke test: drives a real `wp` binary against the throwaway install
# bin/smoke-install.sh provisions, to cover subcommand and flag registration,
# the 0/1/2 exit-code contract, rotation end to end, drop-in loading through
# the real loader, and a multisite pass. See tests/smoke/SPEC.md.
#
# Requires bin/smoke-install.sh to have already provisioned .smoke/wordpress/.
# This script never touches wp-env's own wp-content: it only ever reaches
# into .smoke/, the install the install script owns.
#
set -euo pipefail
cd "$(dirname "$0")/../.."

# --- configuration ---

SMOKE_DIR="$PWD/.smoke"
SMOKE_URL="${SMOKE_URL:-http://smoke.test}"
export WP_CLI_CACHE_DIR="$SMOKE_DIR/cache" PAGER=cat WP_CLI_PAGER=cat

WP=( php -d display_errors=stderr -d log_errors=0 "$SMOKE_DIR/wp-cli.phar" --path="$SMOKE_DIR/wordpress" --allow-root )

NS="smoke-$$"
DROPIN="$SMOKE_DIR/wordpress/wp-content/secrets.php"

# One row per subcommand, "sub:flags". A new flag without a row fails the
# run, and so does a flag that vanishes from the synopsis: the comparison
# below is an exact set match in both directions, not a subset check.
EXPECTED_FLAGS="
set:--stdin --porcelain
get:--slot --reveal --field --format
delete:--yes
list:--namespace --fields --field --format
retire:--yes
import-option:
migrate-legacy:--dry-run --name --map --namespace --format
rotate:--yes
generate-key:
health:--format
dropin:--verbose
"

N=0
PASS=0
FAIL=0
ERR_FILE="$(mktemp)"

if [ ! -f "$SMOKE_DIR/wp-cli.phar" ] || [ ! -f "$SMOKE_DIR/wordpress/wp-config.php" ]; then
	echo "No smoke install found at $SMOKE_DIR. Run bin/smoke-install.sh first." >&2
	exit 1
fi

# --- helpers ---

# ok "<desc>": record and print a passing TAP line.
ok() {
	N=$((N + 1))
	PASS=$((PASS + 1))
	printf 'ok %d - %s\n' "$N" "$1"
}

# not_ok "<desc>" "<diag>": record and print a failing TAP line, with an
# optional one-line diagnostic. Never prints OUT, a value, a key, or a
# command line.
not_ok() {
	N=$((N + 1))
	FAIL=$((FAIL + 1))
	printf 'not ok %d - %s\n' "$N" "$1"
	if [ -n "${2:-}" ]; then
		printf '# %s\n' "$2"
	fi
}

# diag "<text>": print a single-line comment, for use inside a diagnostic.
diag() {
	printf '# %s\n' "$1"
}

# run <cmd...>: execute a command, capturing stdout into OUT (trailing
# newlines stripped), stderr into ERR (via ERR_FILE), and the exit status
# into STATUS. Never prints anything itself.
run() {
	STATUS=0
	OUT="$("$@" 2>"$ERR_FILE")" || STATUS=$?
	ERR="$(cat "$ERR_FILE")"
}

# assert_status <n> "<desc>": pass when the last run()'s STATUS equals <n>.
assert_status() {
	local expected="$1" desc="$2"
	if [ "$STATUS" -eq "$expected" ]; then
		ok "$desc"
	else
		not_ok "$desc" "exit status was $STATUS, expected $expected: $desc"
	fi
}

# assert_out_eq "<expected>" "<desc>": pass when the last run()'s OUT equals
# <expected>. The diagnostic reports only the two lengths, never the values.
assert_out_eq() {
	local expected="$1" desc="$2"
	if [ "$OUT" = "$expected" ]; then
		ok "$desc"
	else
		not_ok "$desc" "output length ${#OUT} did not match expected length ${#expected}: $desc"
	fi
}

# assert_out_contains "<needle>" "<desc>": pass when OUT contains <needle>.
assert_out_contains() {
	local needle="$1" desc="$2"
	case "$OUT" in
		*"$needle"*) ok "$desc" ;;
		*) not_ok "$desc" "output did not contain expected text: $desc" ;;
	esac
}

# assert_out_not_contains "<needle>" "<desc>": pass when OUT does not
# contain <needle>.
assert_out_not_contains() {
	local needle="$1" desc="$2"
	case "$OUT" in
		*"$needle"*) not_ok "$desc" "output unexpectedly contained the given text: $desc" ;;
		*) ok "$desc" ;;
	esac
}

# assert_err_contains "<needle>" "<desc>": pass when ERR contains <needle>.
# A diagnostic may print the exit status, the description, and at most the
# first three lines of ERR.
assert_err_contains() {
	local needle="$1" desc="$2"
	case "$ERR" in
		*"$needle"*) ok "$desc" ;;
		*)
			not_ok "$desc" "status=$STATUS desc=$desc$(printf '\n'; printf '%s\n' "$ERR" | head -n 3)"
			;;
	esac
}

# normalize_flags "<flags>": one space-separated set of --flag tokens,
# sorted and de-duplicated, for an exact-set comparison.
normalize_flags() {
	printf '%s' "$1" | tr ' ' '\n' | sed '/^$/d' | sort -u | tr '\n' ' ' | sed 's/ $//'
}

# synopsis_flags <sub>: runs `wp help secret <sub>`, keeps only the lines
# between the SYNOPSIS header and the next header line (a line starting
# with an upper-case letter in column 1 -- WP-CLI word-wraps long
# synopses onto indented continuation lines, so every line in the section
# is taken), extracts --flag tokens, and prints them normalised.
synopsis_flags() {
	local sub="$1" section
	run "${WP[@]}" help secret "$sub"
	section=$(printf '%s\n' "$OUT" | awk '
		/^SYNOPSIS/ { in_section = 1; next }
		in_section && /^[A-Z]/ { exit }
		in_section { print }
	')
	printf '%s' "$(printf '%s\n' "$section" | grep -o -- '--[a-zA-Z][a-zA-Z-]*' | sort -u | tr '\n' ' ' | sed 's/ $//' || true)"
}

# finish: EXIT trap. Prints the TAP plan and summary, and exits non-zero if
# anything failed. P4-02 extends this same trap to remove any drop-in left
# behind by a failed case D run.
finish() {
	local exit_status=$?
	rm -f "$ERR_FILE"
	printf '1..%d\n' "$N"
	printf '# passed %d, failed %d\n' "$PASS" "$FAIL"
	if [ "$FAIL" -ne 0 ] || [ "$exit_status" -ne 0 ]; then
		exit 1
	fi
	exit 0
}
trap finish EXIT

# --- case A: registration ---

case_a_registration() {
	# 11 subcommands, one row each: import-option, migrate-legacy, and
	# generate-key by their hyphenated names.
	local subcommands="set get delete list retire import-option migrate-legacy rotate generate-key health dropin"
	local cmd sub

	for cmd in secret network-secret; do
		for sub in $subcommands; do
			run "${WP[@]}" cli has-command "$cmd $sub"
			assert_status 0 "$cmd $sub is registered"
		done
	done

	# Flag table: checked for `secret` only, as the detailed spec words it.
	# Exact-set comparison in both directions, so a new flag without a row
	# fails loudly and a flag that vanishes from the synopsis fails loudly
	# too.
	local row expected_sub expected_flags actual_flags
	local saved_ifs="$IFS"
	IFS='
'
	for row in $EXPECTED_FLAGS; do
		[ -n "$row" ] || continue
		expected_sub="${row%%:*}"
		expected_flags="${row#*:}"
		actual_flags=$(synopsis_flags "$expected_sub")
		if [ "$(normalize_flags "$actual_flags")" = "$(normalize_flags "$expected_flags")" ]; then
			ok "secret $expected_sub synopsis flags match the table"
		else
			not_ok "secret $expected_sub synopsis flags match the table" \
				"expected [$(normalize_flags "$expected_flags")] got [$(normalize_flags "$actual_flags")]"
		fi
	done
	IFS="$saved_ifs"

	# Pin bug 1's cause, not only its fix: `--version=previous` is not
	# accepted as the slot selector, because WP-CLI's own global --version
	# flag swallows it before the subcommand ever sees it. This is already
	# implied by the exact-set check above (`get`'s row has no --version),
	# stated here explicitly and end to end.
	run "${WP[@]}" secret set "${NS}/slotpin" "smoke-value-a-$$"
	assert_status 0 "set ${NS}/slotpin to value a"
	run "${WP[@]}" secret set "${NS}/slotpin" "smoke-value-b-$$"
	assert_status 0 "set ${NS}/slotpin to value b"
	run "${WP[@]}" secret get "${NS}/slotpin" --version=previous --reveal --field=value
	assert_out_not_contains "smoke-value-a-$$" "--version=previous does not select the previous slot"
	run "${WP[@]}" secret delete "${NS}/slotpin" --yes
	assert_status 0 "delete ${NS}/slotpin"
}

# --- case B: behaviour and exit codes ---

case_b_behaviour() {
	:
}

# --- case C: rotation ---

case_c_rotation() {
	:
}

# --- case D: drop-in loading ---

case_d_dropin() {
	:
}

# --- multisite conversion ---

convert_to_multisite() {
	:
}

# --- case E: multisite ---

case_e_multisite() {
	:
}

# --- main ---

main() {
	case_a_registration
	case_b_behaviour
	case_c_rotation
	case_d_dropin
	convert_to_multisite
	case_e_multisite
}

main
