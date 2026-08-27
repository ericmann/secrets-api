# Open questions

Things this implementation deliberately did **not** decide. Each entry states the conservative
choice that was made, where the seam is, and who needs to resolve it.

Maintained from commit 1 onward. When you hit one of these, do not resolve it silently — add to
the entry.

Status legend: 🔴 blocks a release · 🟡 needs an answer before the core patch · 🟢 tracking only

---

## 1. 🟢 Plugin slug and display name — CLOSED

**Confirmed by the operator: `secrets-api` stays, with the display name "Secrets API."**

This entry was written at commit 1 as a 🔴 on the grounds that the slug "must not be
Displace-branded" — but `secrets-api` never was, so the stated constraint was already satisfied
and the entry should have been closed by inspection long before anyone was asked about it. It was
briefly changed to `secrets-manager` and reverted; the name describes an API, and that is what
this ships.

The one thing here that is genuinely not settled is whether the slug is *available* on
WordPress.org, which no amount of local reasoning resolves — `wordpress.org/plugins/secrets-api/`
should be checked at submission time. That is a registration question, not a naming decision, and
it does not block anything before then.

Also unchanged, and worth knowing rather than rediscovering: the repository directory is
`secrets-management`, which differs from the slug. That is fine for local development (wp-env
mounts `.` wherever it sits) but a .org checkout would use the slug as its directory name.

---

## 2. 🔴 Plaintext-boundary stores

From Chris Reynolds (Pantheon) in the proposal comments:

> For a platform store that's inverted — the store *is* the encryption boundary, and it *needs*
> the plaintext over an authenticated channel so the same secret is usable from other
> environments on the same account. If the drop-in only ever sees WP's ciphertext, we'd be
> double-encrypting and the value would be opaque outside WordPress.

This directly contradicts the proposal's own commitment that "Neither is ever handed a plaintext
secret, and neither can turn encryption off."

**Current state:** not implemented, and deliberately so. `WP_Secrets_Store` is never handed a
plaintext. A clearly-commented seam marks where such a store would have to hook in. Resolving
this requires a decision about whether the published guarantee is being amended, which is not a
decision the implementation gets to make.

**Do not** implement a plaintext passthrough without that decision.

---

## 3. 🟡 Read-only stores — `supports()` is beyond the published surface

The other half of the Pantheon feedback:

> A storage drop-in for us would need to serve `wp_get_secret()` and reject `wp_set_secret()` —
> but the API has no way to express "readable, but not writable here," and every plugin settings
> screen assumes `set()` works.

**Current state:** implemented as `WP_Secrets_Store::supports( $capability )` with capabilities
`write`, `list`, `delete`, plus a `wp_secrets_store_supports()` helper so a settings screen can
disable its save button before the user types a credential. A read-only store makes
`wp_set_secret()` return `secret_store_read_only`.

Needs a note in the proposal comments thread, because it is an addition to a published design.

---

## 4. 🟡 API surface that was never published

The proposal published exactly four functions and two `WP_Secret` methods. Everything below is
this implementation's invention and should be confirmed in the comments thread **before it
hardens**, because names are the hardest thing to change after adoption.

| Added | Justification | Risk |
|---|---|---|
| `wp_list_secrets()` / `wp_list_network_secrets()` | "The hooks and accessors an admin screen would need are in scope now" | Name and return shape unpublished |
| `wp_retire_secret_version()` / `wp_retire_network_secret_version()` | "Retiring the previous slot is an explicit operator action — no timers, no cron" | Function implied but never named |
| `wp_set_network_secret()`, `wp_get_network_secret()`, `wp_delete_network_secret()` | "Site secrets and network secrets are separate functions with separate capabilities" | **Names never published.** Highest-risk group — easy to assume these were committed to |
| `WP_Secret::get_name()` | Needed for the `[secret:{name}]` mask | Only `reveal()` and `fingerprint()` were published |
| `WP_Secrets_Store`, `WP_Secrets_Keyring` and every method on them | The proposal describes two extension points and names neither | Hosts will build against these. Largest unpublished surface in the project |
| `wp_secrets_memzero()`, `wp_secrets_validate_name()`, `wp_secrets_store_supports()`, `wp_using_secrets_dropin()` | Implementation necessities | New globals in core-bound code |
| `wp_secret_changed` hook name and argument order | Post commits to "actor, timestamp, and old and new fingerprints"; `$action` is an addition | Unpublished |
| `WP_Secrets_Cipher`, `WP_Secrets_Key_Manager`, `WP_Secrets_Option_Store`, `WP_Secrets_Config_Key_Provider`, `WP_Secrets_Broken_Store`, `WP_Secrets_Broken_Keyring` | The envelope described in the proposal has to be built out of *something* | Six permanent class names in `wp-includes`. The proposal names none of them, and the `Broken_*` pair in particular encodes a fail-closed design decision in a class name |
| `WP_SECRETS_ERROR_*` constants | The proposal publishes error *strings* (`secret_decryption_failed` et al.) but no constant names | Callers will `use` whichever spelling ships first |
| `WP_SECRETS_CAP_MANAGE` / `WP_SECRETS_CAP_MANAGE_NETWORK` | Wrappers over the two published capability strings | Strings match the proposal exactly (`manage_secrets`, `manage_network_secrets`); only the constant names are new |
| `WP_SECRETS_MAX_NAME_LENGTH` (172), `WP_SECRETS_RECORD_VERSION` (1) | A cap and a format version both have to exist | 172 is derived, not published — see the note under §5.5 in the build brief about subtracting the option prefix from 191 |
| The `wp-content/secrets.php` drop-in filename, and `$GLOBALS['wp_secrets_store']` / `$GLOBALS['wp_secrets_keyring']` | The proposal describes drop-in-style replacement without naming the file or the mechanism | A drop-in filename is effectively permanent once hosts ship against it, in the same way `object-cache.php` is |

Not on this list, deliberately: `Secrets_API_Legacy_Reader`, `Secrets_API_Migrator`, and
`Secrets_API_Prototype_Fallback_Store` live in `plugin/`, are never copied into core, and are not
proposed for it — see #15. They need no comments-thread note, only deletion when the window
closes.

---

## 5. 🟢 The five questions the proposal asked the community

These have a home here so answers from the comments thread land somewhere rather than being
absorbed into an assumption.

1. **Is the no-filter decision on retrieval sufficient, with providers as the substitution?**
   — no answers recorded yet.
2. **Are two version slots (`CURRENT`/`PREVIOUS`) adequate, or is a different rotation pattern
   necessary?** — no answers recorded yet. Note that `'v' => 1` in the record format leaves room
   to change this, but see #7.
3. **Does `wp_import_option_as_secret()` fit actual plugin migration workflows?**
   — no answers recorded yet.
4. **Which WP-CLI commands most need this surface, and in what priority order?** — the command
   set implemented here is a starting set, not a settled one. Track real answers rather than
   assuming.
5. **For hosts running secret stores or key backends: what is missing from the drop-in surface?**
   — one answer so far, from Pantheon, split across #2 and #3 above.

---

## 6. 🟡 Access control language

The proposal says namespacing exists "so a future admin screen can group by owner and
cross-namespace access has something to check against" — which leaves the door open to a future
check without specifying one.

The prior proof-of-concept's README described namespace-based access control in a way that led
people to believe one plugin's secret was inaccessible to another. Darin Kotter raised this in
the comments. It was not true then and is not true now.

**Documentation must state plainly:** there is no per-plugin isolation in 7.2. Masking is
hygiene against shoulder-surfing and accidental logging, not a privilege boundary. Any plugin
that can run PHP can read any secret.

Phrase this as "no isolation *in 7.2*" rather than "cannot be" — the proposal deliberately left
room for a future check, and the docs should not close a door the proposal held open.

---

## 7. 🟡 Record format version bump policy

`'v' => 1` exists so a future format change is detectable rather than presenting as a decryption
failure. The upgrade path for `v2` is **not designed**. Open sub-questions:

- Is `v2` read-only-compatible with `v1`, or is there a migration pass?
- `v` sits outside the AAD, so it is unauthenticated metadata. It must be treated as a routing
  hint validated *before* decryption, and an unknown `v` must be rejected outright rather than
  attempted.

---

## 8. 🟡 GitHub Enterprise specifics

Unknown until a maintainer confirms them on `github.a8c.com`. See `docs/ci.md`.

- Which runner labels exist. The workflow parameterises `runs-on` and defaults to `self-hosted`;
  this is a guess.
- Whether Marketplace Actions are available (requires GitHub Connect / action bundling). The
  workflow assumes only `actions/checkout` and otherwise plain `run:` steps.
- Whether runners have egress to wordpress.org and packagist. `install-wp-tests.sh` honours
  `WP_MIRROR_BASE` and `WP_TESTS_ZIP_URL` so an internal mirror can be substituted.
- Whether an internal WordPress tarball mirror exists.

`make ci` locally is the always-works fallback and does not depend on any of this.

---

## 9. 🟢 `sodium_compat` coverage of the KDF

The proposal commits to `sodium_compat` as the fallback where the libsodium extension is disabled
at build time. Fingerprints (§4.4) and all multisite key derivation depend on
`sodium_crypto_kdf_derive_from_key()`, which is a later addition to `sodium_compat` than the AEAD
primitives.

**Must be verified against the version core actually bundles.** If it is absent, the options are
a documented HKDF-over-`generichash` fallback or a hard `secret_crypto_unavailable` — and the
choice affects whether the plugin works at all on the hosts the fallback exists to serve.

Related: `sodium_memzero()` is a no-op under `sodium_compat`, because PHP strings cannot actually
be zeroed from userland. Documentation must not overclaim memory hygiene.

---

## 10. 🟢 Community requests not in scope

- **Two Factor plugin integration** (Brian Haas). Reasonable, out of scope for the API itself.
  Worth a note about whether the Two Factor plugin should be an early consumer.
- **Iterating UX/DX inside the AI plugin's Key Encryption experiment before core merge**
  (Jeffrey Paul). This plugin currently touches the AI plugin only as a vendored-copy hazard
  during migration. Whether the two efforts should coordinate is a project question.

---

## 11. 🟢 Testability smells

Per the build brief: if something is hard to test, that is usually a design smell, and it gets
written down here rather than skipped.

- `var_export()` of a `WP_Secret` cannot be masked from userland — it ignores `__debugInfo()` and
  `__toString()` and emits private properties directly. Mitigated by not storing the plaintext
  as an object property at all. Documented as a known limitation regardless.
- The `options.php` all-settings screen reads the options table directly with no filter, so a
  plugin cannot exclude secrets from it. Surfaced as a Site Health warning and documented as a
  core-patch-only fix. There is an existing core ticket and pull request on plaintext display in
  `options.php` to reference.

---

## 12. 🟡 Throwing versus WP_Error for programming errors

The public functions mix two error strategies. Values that vary legitimately at
runtime -- a bad secret name, an unavailable key, a corrupt record -- return `WP_Error`.
Values that can only be wrong because a *caller* got them wrong -- an unrecognized
`$version` passed to `wp_get_secret()`, an invalid scope or slot inside
`WP_Secrets_Cipher` -- throw `InvalidArgumentException`.

The published contract for `wp_get_secret()` is `WP_Secret|null|WP_Error`, and throwing
is arguably a fourth state outside it. Core's own convention in this situation leans
toward `_doing_it_wrong()` plus a `WP_Error` return rather than an exception.

**Current state:** throws, on the reasoning that a bad version constant is never
recoverable at runtime and failing loudly beats returning something a caller may not
check. Worth an explicit decision before the API freezes, since it changes what a
plugin author has to defend against.

---

## 13. 🟢 Drop-in file loading is not directly covered by an automated test

`wp_secrets_api_load_dropin()` (in `secrets-api.php`) runs once, during
`wp_secrets_api_bootstrap()`, which itself runs once per PHP process via
`muplugins_loaded`. Both that function and `_wp_secrets_get_store()` /
`_wp_secrets_get_key_manager()` (in `src/wp-includes/secrets.php`) cache their result
in a function-local `static` on first call, with no reset hook. By the time any test
method's body runs -- even in a `@runInSeparateProcess` test -- the process's one
bootstrap pass has already completed, so a drop-in file placed on disk from within a
test body arrives too late to affect that process's `wp_secrets_api_load_dropin()`
call.

**What is covered:** the consumption side -- `_wp_secrets_get_store()` and
`_wp_secrets_get_key_manager()` correctly using `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']`, and falling back to `WP_Secrets_Broken_Store` /
`WP_Secrets_Broken_Keyring` when `$GLOBALS['wp_secrets_dropin_broken']` is set -- is
tested directly in `tests/phpunit/test-secrets-extension-points.php` by setting those
same globals in an isolated process, exactly as a real drop-in would, before the
first call that would cache a default.

**What is not covered by an automated test:** `wp_secrets_api_load_dropin()`'s own
`require`-and-`try`/`catch` around an actual drop-in file. That behavior was verified
empirically instead, once, directly against the PHP engine on both PHP 7.4.33 and
8.5.7, before being relied on:

- A syntax error in the required file *is* caught as a `ParseError` by
  `catch ( \Throwable $e )` around the `require` -- confirmed on both versions.
- A class that `implements` an interface but omits a required method is an
  **uncatchable fatal error**, even inside that same `try`/`catch` -- also confirmed
  on both versions. This is a genuine PHP-engine limitation, not a bug in the
  catching code: there is no userland way to intercept it.

So a malformed drop-in fails safely (`WP_Error` from every operation, per the design
in issue resolved below) for syntax errors and thrown exceptions, but a drop-in whose
class silently fails to fully implement `WP_Secrets_Store` or `WP_Secrets_Keyring`
can still produce a fatal error page. Worth another look if a more thorough,
process-spawning integration test (driving `wp_secrets_api_load_dropin()` in a true
fresh process with a real file on disk, prepared before that process's bootstrap
starts) is judged worth the complexity later.

---

## 14. 🟢 `wp secret set --stdin`'s own code path is not covered by an automated test

`WP_CLI_Secret_Command::set()` reads `--stdin` via
`file_get_contents( 'php://stdin' )`. Faking that stream meaningfully from inside a
PHPUnit process would need a real pipe (`proc_open`), and getting it wrong risks
hanging the whole test run waiting on a stream nothing is writing to -- not worth it
for one branch. Every other branch of `set()` (positional value, missing value, the
shell-history warning, success/porcelain/error reporting) is covered directly.

---

## 15. 🟢 Prototype compatibility: what we do and do not provide — CLOSED

Resolved by the operator after Checkpoint F, superseding the earlier flag-semantics
question and the compat-shim question that used to sit at #16.

**The actual goal**, stated directly: the AI team built on top of the vibe-coded
Displace prototype, will adopt this API once it ships, and the number of sites
broken or needing a rebuild in that transition should be as close to zero as
possible. Not "provide a compatibility layer" -- explicitly the opposite. We do not
now and will never need one.

**What that ruled out.** `Secrets_API_Compat_Shim` and its four global functions
(`get_secret()`, `set_secret()`, `delete_secret()`, `secret_exists()`) were built,
then deleted. They were a true compatibility layer: they let prototype-era code keep
running unchanged, permanently, against a collapsed `string|null` contract that
reintroduced the absent-versus-broken ambiguity the three-state return exists to
eliminate -- and, as Checkpoint F found, made the standard create-if-missing idiom
destroy a recoverable credential. Nothing in the plugin reimplements the prototype's
API surface any more, and the legacy filters (`secrets_pre_get`, `secrets_pre_set`,
`secrets_access`, `secrets_provider`) remain unimplemented under any flag.

**What replaced it.** `Secrets_API_Prototype_Fallback_Store`, a decorator around the
default store. A read whose current-format record does not exist falls through to
the prototype's option row, and the value is re-encrypted into a proper
current-format record on the way back. The next read hits the new record and never
returns here. So an AI-plugin site upgrades one secret at a time, on first use, with
nobody running anything -- which is the outcome that actually minimises broken
sites. It is one-way, one-time, and flagged `needs_rotation`.

The mapping is namespace-agnostic: `wp_get_secret( 'ai/api_key' )` inherits the
prototype's `api_key`. Requiring an exact namespace match would mean guessing which
namespace the adopting plugin picks, and guessing wrong is the broken site we are
trying to avoid. This is not a new exposure -- the prototype's keyspace is already
global, so any plugin could read any prototype secret by bare key -- but it is worth
knowing, and it is why the inherited copy is always a new row rather than a move.

**Non-interference is the load-bearing promise.** The prototype's rows are never
written to or deleted, by anything, ever. The two option namespaces do not overlap
(`_secret_` vs `_wp_secret_`), so both systems run on one site indefinitely and the
AI plugin keeps working throughout, reading its own copy until it moves over.
Enforced statically by `test_never_writes_to_a_prototype_owned_option()`.

That is also why `wp secret migrate-legacy` lost `--delete-source` and `--yes`, and
why the earlier flag-semantics debate is moot: the migrator is now strictly
additive, and there is no destructive path left to guard. It survives as a bulk,
proactive version of what the fallback store does lazily, plus a `--dry-run` report
of what is still in the old format. An operator who genuinely wants the old rows
gone can `wp option delete` them explicitly, knowing what they are doing.

**Deletion seam.** When the window closes, deleting
`plugin/class-secrets-api-{legacy-reader,migrator,prototype-fallback-store}.php`,
`WP_CLI_Secret_Command::migrate_legacy()`, the store installation in
`secrets-api.php`, and the matching test files removes the entire surface. Nothing
under `src/` references any of it.

---

## 16. 🟢 `make coverage`'s numbers cannot be trusted inside wp-env

`make coverage` runs and produces a report, but the percentages it reports from the wp-env
container (PHP 7.4, no coverage driver by default) are not reliable, and should not be read as a
statement about test thoroughness. This is a tooling gap, not a test-coverage gap: the suite is
395 tests / 836 assertions single-site, 848 multisite, and the classes affected here are
extensively exercised elsewhere in this repository's own review history (Checkpoint C, the
demotion AAD bug, Checkpoint F) by tests that pass.

**What was verified, empirically, before writing this down:** the wp-env tests-cli image ships
no coverage driver (`php -m` shows neither `xdebug` nor `pcov`). Installing `pcov` via `pecl`
works and produces a report, but every class in `plugin/` and `cli/` reports exactly 0% line and
method coverage — `Secrets_API_Legacy_Reader`, `Secrets_API_Migrator`,
`Secrets_API_Prototype_Fallback_Store`, `WP_CLI_Secret_Command` — while every class in `src/`
reports partial-to-full coverage in the same run, despite dozens of passing, assertion-bearing
tests calling methods on the "0%" classes directly (e.g. `test-secrets-api-prototype-fallback-
store.php` alone is 14 tests / 0 of them process-isolated, all green, directly calling `get()`,
`set()`, `delete()`, `list_names()`, `supports()`).

Three explanations were checked and ruled out rather than assumed: not a symlink/realpath
mismatch between `plugin/`/`cli/` and `src/` (both resolve identically inside the container); not
`@runInSeparateProcess` coverage failing to merge back (the fallback-store tests use zero
isolated processes and still show 0%); not pcov's initial table sizing (`pcov.initial.files`
raised 8x with no change — though bumping it also revealed that `--filter` degrades coverage
collection further, dropping even `src/`'s numbers to near-zero, which is a second, related
symptom of the same unresolved root cause). What is not yet known is why the split falls exactly
along the `plugin/`+`cli/` vs `src/` boundary when both are required through the identical
`WP_SECRETS_API_PLUGIN_DIR`-prefixed path from the same one-time bootstrap call.

**Current state:** `make coverage` is left as-is -- a real, working target -- since fixing this
would mean debugging a Docker-image/pcov/PHPUnit interaction with no clear next hypothesis, for a
number that gates nothing (no coverage threshold is enforced anywhere in `make ci`). Trustworthy
coverage numbers, if wanted, should come from the non-Docker path (`make install && make
coverage` against a host PHP with Xdebug or pcov installed normally, not via a fresh `pecl
install` inside an already-running container) rather than this one.

---

## Resolved

Decisions that were open and are now closed, kept so the reasoning is not lost.

### Key hierarchy on multisite — resolved 2026-08-26

The build brief's original wording derived "per-site master keys **for network scope**," which
would have made a network secret written on one blog unreadable on every other blog.

**Resolved:** network secrets are readable across all blogs; site secrets are not readable across
blogs. One random root key per install, wrapped by the site key, stored via `update_site_option()`
— the only wrapped value, so rotating the site key on a 500-site network re-wraps exactly one
thing, which is what the proposal promises. Site-scope master for blog N derives at subkey
`$blog_id` with context `wpsecsit`; network-scope master derives at reserved subkey `0` with
context `wpsecnet`. Masters are derived on demand and never stored, which also removes the
contradiction between the brief's §4.1 and §4.6.

### No-op mechanism — resolved 2026-08-26

The brief originally required `function_exists()` guards around every core-bound function. That
conflicted with keeping `src/` a clean file copy into `wordpress-develop` (core files carry no
such guards) and, more seriously, a per-function guard on a credential retrieval function is an
overloading surface: an mu-plugin declaring `wp_get_secret()` first would silently intercept
every secret read on the site.

**Resolved:** the entire decision lives in `secrets-api.php`. Two `function_exists()` calls in
the whole codebase, both in the bootstrap, all-or-nothing. `src/` contains none. The version gate
is ANDed with a positive probe rather than used alone, because the proposal's timeline allows the
API to be deferred to 7.3 and a bare `>= 7.2` check would strand sites on a 7.2 that shipped
without it. A symbol collision on an older WordPress refuses to load and says so, rather than
silently deferring to an unknown implementation.

The architectural test at commit 9 inverts accordingly: assert `src/` contains **no**
`function_exists(` or `class_exists(`.
