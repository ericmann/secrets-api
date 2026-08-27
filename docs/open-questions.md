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

**Status: deferred by the operator (2026-08-27), who has a position forming.** Not blocked on
implementation input. Nothing here should be built, and the seam should not be widened
speculatively, until that position lands.

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
| `secret_invalid_argument` (`WP_SECRETS_ERROR_INVALID_ARGUMENT`) | Added when #12 converted caller-error throws to WP_Error; the proposal's error list predates that decision | A genuinely new error *string*, not just a new constant name — the only one on this list that is |
| `WP_SECRETS_CAP_MANAGE` / `WP_SECRETS_CAP_MANAGE_NETWORK` | Wrappers over the two published capability strings | Strings match the proposal exactly (`manage_secrets`, `manage_network_secrets`); only the constant names are new |
| `WP_SECRETS_MAX_NAME_LENGTH` (172), `WP_SECRETS_RECORD_VERSION` (1) | A cap and a format version both have to exist | 172 is derived, not published — see the note under §5.5 in the build brief about subtracting the option prefix from 191 |
| Accepting unnamespaced names at all | Adoption path for prototype-era code; see #17 | Contradicts the brief's §5.5 outright. The proposal never discusses naming, so this is unpublished either way -- but it is the one place the implementation deliberately loosened a stated rule rather than adding to it |
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

**Status: deliberately deferred by the operator (2026-08-27) — a future problem, not an
oversight.** What matters is that the seam exists and fails safe today: `'v' => 1` is written on
every record, an unrecognised value returns `secret_record_unsupported_version` rather than being
guessed at, and that behaviour is tested. Designing the v1→v2 path before there is a v2 would be
inventing constraints for a format nobody has specified.

---

## 8. 🟢 Where CI actually runs — CLOSED

**Resolved: github.com, private for now.** `.github/workflows/ci.yml` rewritten accordingly.

The original entry was a list of unknowns about `github.a8c.com`. Two of them, once checked,
settled the question:

1. **GHES provides no GitHub-hosted runners in any edition** — "to enable GitHub Actions for your
   GitHub Enterprise Server instance, you must host at least one machine to execute jobs" — and
   the instance had none configured. No job could have executed there. Fixing that meant owning
   and patching a VM carrying three PHP versions and a reachable MySQL, indefinitely.
2. **"Allow local actions only" was never the blocker it looked like.** Official GitHub-authored
   actions are bundled into GHES instances with no GitHub Connect required. The workflow had been
   written defensively around a constraint that did not bind — which is worth remembering as a
   general lesson: the defensive shape cost real complexity (hand-rolled PHP switching, no
   dependency caching, a whole fallback job) to avoid a problem nobody had confirmed existed.

The deciding argument was not CI convenience. This is a feature plugin for a proposal published
on make.wordpress.org that asks the community five questions and invites hosts to build drop-ins
against it, and `src/` is a `wordpress-develop` patch candidate. None of those people can see an
internal instance.

**Still open, but not blocking and not a CI question:** the repository is private, and at some
point before Beta 1 the people the proposal is addressed to need to be able to see it. Whether
that means making this repository public or moving it under a WordPress-owned org is a project
decision. Related: the `Plugin URI` header still points at `https://github.com/WordPress/secrets-api`,
which does not exist yet — aspirational rather than accurate, and worth correcting whenever the
final home is settled rather than pointing it at a private URL that 404s for everyone.

---

## 9. 🟢 `sodium_compat` coverage of the KDF

The proposal commits to `sodium_compat` as the fallback where the libsodium extension is disabled
at build time. Fingerprints (§4.4) and all multisite key derivation depend on
`sodium_crypto_kdf_derive_from_key()`, which is a later addition to `sodium_compat` than the AEAD
primitives.

**Verified against the version core actually bundles (2026-08-27): it is present, and it is a
real implementation.** WordPress trunk ships `sodium_compat` `polyfill-1.0.8`, which declares
`sodium_crypto_kdf_derive_from_key()` in `lib/php72compat.php` behind an `is_callable()` guard
and implements it in `ParagonIE_Sodium_Compat::crypto_kdf_derive_from_key()` — bounds-checking
the subkey length rather than throwing "not implemented".

So no HKDF-over-`generichash` fallback is needed, and hosts without the libsodium extension —
exactly the population the polyfill exists for — get working key derivation. Re-check if core ever
downgrades the bundled polyfill, since every master key, every per-secret data key, and every
fingerprint in this API depends on that one function.

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

## 12. 🟢 Throwing versus WP_Error for programming errors — CLOSED

**Resolved by the operator: WordPress functions return `WP_Error` or `false`. They do not
throw.** Applied.

Previously, values that could only be wrong because a *caller* got them wrong — an unrecognised
`$version`, an invalid scope or slot, a non-string namespace — threw `InvalidArgumentException`,
on the reasoning that failing loudly beats returning something a caller might not check. That is
a defensible position in general and the wrong one for WordPress, where a thrown exception from
`wp_get_secret()` is a fatal on a site whose author had no reason to wrap the call in a
`try`/`catch` nobody told them about.

Every one of those sites now calls `_doing_it_wrong()` and returns
`WP_Error( WP_SECRETS_ERROR_INVALID_ARGUMENT )` — loud in development, recoverable in production,
and canonical. Seven sites converted, across `_wp_secrets_get()`, `_wp_secrets_list()`,
`WP_Secrets_Cipher::validate_common()`, and `WP_Secrets_Key_Manager::get_master_key()`.

This adds no fourth state to `wp_get_secret()`. `WP_Secret|null|WP_Error` already covers it: a
bad `$version` is now simply one more reason the `WP_Error` branch happens.

**What still throws, and why it is not an oversight:** `WP_Secret`'s constructor, and its
`__sleep()`/`__serialize()`/`__wakeup()`/`__unserialize()`/`__clone()` refusals. Neither has a
return channel — a constructor cannot hand back a `WP_Error`, and a magic method cannot either.
The alternatives are throwing or constructing a half-valid `WP_Secret` that fails somewhere less
obvious, and for the serialization refusals specifically, silently permitting the operation would
leak a plaintext. The build brief mandates those throws for that reason. Nothing outside this API
constructs a `WP_Secret` in normal use.

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

The mapping is exact: `wp_get_secret( 'api_key' )` inherits the prototype's
`api_key`, and a namespaced name inherits nothing, because the prototype had no
namespaces and so never owned that name. An earlier revision dropped the namespace
instead, letting any namespace claim any prototype row; that was removed in favour
of accepting the unnamespaced form outright — see #17.

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

## 17. 🟢 Unnamespaced secret names are accepted — CLOSED

**Operator decision: `wp_get_secret( 'api_key' )` works, and reports through
`_doing_it_wrong()`.** This contradicts the build brief's §5.5 ("There is no unnamespaced form
and no escape hatch for one"), deliberately.

The reasoning is adoption, the same as everything else in #15. Code written against the Displace
prototype uses a flat keyspace. Refusing those names means every call site has to be rewritten
before *any* of it can be ported, which makes the move all-or-nothing for exactly the team we are
trying not to break. Accepting them makes it incremental: swap `get_secret()` for
`wp_get_secret()`, keep the key, add the namespace later — and the notice is a running list of
which call sites still need one.

What this replaced is worth recording, because it was worse. The prototype fallback store used to
map `namespace/key` onto the prototype's `key` by dropping the namespace, so
`wp_get_secret( 'anything/api_key' )` inherited it. That worked and was not a new exposure (the
prototype's keyspace was already global), but any namespace could silently claim any prototype
row, and a caller had no way to see which of its names were wired to prototype data. Making the
unnamespaced form legal removed the need for the rewrite entirely.

**The relaxation is narrow.** It drops the "exactly one slash" requirement and nothing else: an
unnamespaced name is still held to the same character rules, the same length cap, and the same
rejection of anything with uppercase, spaces, or dots. Two or more slashes are still invalid.

**Consequences that are easy to miss:** an unnamespaced name has no namespace for a future
cross-namespace access check to check against (#6), and two plugins both choosing `api-key`
collide silently. Namespaced names remain the documented default everywhere, and
`wp secret migrate-legacy` keeps `--namespace` and `--map` for putting a migrated secret
somewhere specific.

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
