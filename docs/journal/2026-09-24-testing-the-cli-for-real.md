---
title: "Testing the CLI for real"
description: "A bash harness now drives a real wp binary against a throwaway install, closing the last coverage gap marked as needing an answer before the core patch."
date: 2026-09-24
---

# Testing the CLI for real

[`tests/smoke/smoke.sh`][smoke] exists now. It provisions a throwaway WordPress install under
`.smoke/`, with a pinned `wp-cli.phar`, and drives `wp secret` and `wp network-secret` through a
real `wp` process rather than calling `WP_CLI_Secret_Command`'s methods directly. `make ci` and
`bin/ci-local.sh` both run it, and CI now has a `smoke` job on PHP 7.4 and 8.3. [ADR 0008][adr8]
called this out as the one piece of pre-Trac work that tests the surface rather than waiting on
silence to become confirmation.

## What was built

Five cases. A: every subcommand on both `wp secret` and `wp network-secret` is registered, and
each `wp secret` subcommand's synopsis flags match an exact table, in both directions — a new flag
with no row and a row with no flag both fail loudly. B: the exit-code contract (0 found, 1 absent, 2 broken),
masking and `--reveal`, slots, every `list` format, `retire`, `delete`, `generate-key`,
`import-option`, and `migrate-legacy --dry-run`. C: rotation end to end, with the previous key
moved into `wp-config.php` and a fresh key generated, then confirmed still decryptable and
reported healthy afterward. D: drop-in loading through the real loader — a syntax error, a thrown
exception, a wrong-type provider global, and a no-op drop-in, each checked against what
`wp secret dropin` reports and what `wp secret get` does. E: `wp core multisite-convert`, a second
site, and the site/network scope split — a site-2 secret is invisible from site 1, and a network
secret round-trips from both.

Nothing under `src/` or `cli/` changed to make this pass, with one exception worth stating
plainly: `WP_Secrets_Key_Manager` did not preserve the root key across `wp core
multisite-convert` before this work started. Case E's first run failed on the network-secret
health check — every secret written before conversion became silently undecryptable,
contradicting what [`docs/spec/network.md`][network] already claimed. That was diagnosed and
fixed: the wrapped root key is adopted from the pre-conversion site option into `wp_sitemeta` on
the first post-conversion read. `convert_to_multisite` now asserts on it directly — a secret set
immediately before `wp core multisite-convert` is read back with `--reveal --field=value` right
after the network activates, and must still equal what was written.

## What it found

The concrete thing PHPUnit could not catch: on 4 September, `--version=previous` silently
returned the current value. This harness does not reproduce that symptom through a real `wp`.
WP-CLI 2.12.0 hands `--version` after a command through to the subcommand rather than consuming
it itself; what actually dropped the flag was `wp-env run`, before WP-CLI ever saw it. The flag
is `--slot` now regardless, since a subcommand-level flag should not share a name with a global
one WP-CLI does recognise (`wp --version`) even when that global flag is not the one eating it
here. Case A's synopsis check and case B's `get --slot=previous` assertion both pin the rename.
Reintroducing `--version` by hand and running against the real binary shows what that rename
actually guards against: `get --version=previous` now reaches `get()`, which does not declare
`--version`, and WP-CLI rejects it — exit 1, `unknown --version parameter` on stderr. Case A
asserts both of those directly. Diagnosing this also turned up a second defect: case E's first
run failed on the network-secret health check after `wp core multisite-convert`, traced to
`WP_Secrets_Key_Manager` not preserving the root key across the conversion. That is fixed, and
`convert_to_multisite` now asserts directly that a secret set before conversion still decrypts
after it.

Reintroducing the other two historical bugs by hand — deleting a `--format` description line,
dropping an `@subcommand` tag — still makes the suite fail for the reason each was supposed to.
That reintroduce-and-revert cycle is recorded in `tests/smoke/smoke.sh`'s own header comment and
in the commit that added it.

Past that: every subcommand dispatches the way its docblock says, `list --format=*` behaves the
same across json, csv, and ids, and the multisite pass, once the root-key fix landed, round-trips
correctly on both sides of the site/network split.

## What was deliberately left out

`rotate --from` waits for `build/kms-keyring`, which is adding the flag; this suite does not test
a flag that does not exist yet. Byte-for-byte output comparison was skipped in favor of
exit-code and content assertions, because WP-CLI's table formatting is not part of this project's
contract. The AWS Secrets Manager and (forthcoming) Vault and KMS examples are exercised by their
own READMEs, not by this harness, since `examples/` is explicitly excluded from `make ci`. And the
uncatchable fatal — a drop-in class that implements an interface but omits a method — stays a
recorded, not desired, outcome: case D confirms the process exits non-zero with a PHP fatal on
stderr, but there is no userland way to turn that into a passing assertion instead of a documented
limit.

## What it means for the Trac patch

The CLI surface that ships with 7.2 now has an end-to-end test that `make ci` runs on every push,
on the PHP floor and the newest supported version. `docs/journal/test-coverage-gaps.md`'s only
🟡 entry — CLI dispatch untested — is gone; what is left there is 🟢 tracking only. That was the
condition ADR 0008 set before the Trac ticket opens.

[smoke]: ../../tests/smoke/smoke.sh
[adr8]: ../decisions/0008-the-trac-ticket-replaces-thread-confirmation.md
[network]: ../spec/network.md
