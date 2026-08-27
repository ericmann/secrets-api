# Open questions

Things this implementation deliberately did **not** decide. Each entry states the conservative
choice that was made, where the seam is, and who needs to resolve it.

Maintained from commit 1 onward. When you hit one of these, do not resolve it silently — add to
the entry.

Status legend: 🔴 blocks a release · 🟡 needs an answer before the core patch · 🟢 tracking only

---

## 1. 🔴 Plugin slug and display name

The plugin is currently `secrets-api`, with the display name "Secrets API". This must not be a
Displace-branded slug. Needs a human decision before any WordPress.org submission, and the
decision changes the text domain in every plugin-only file.

**Current state:** `secrets-api` used throughout as a placeholder, flagged in `secrets-api.php`.

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

## 15. 🟡 `wp secret migrate-legacy` flag semantics resolved without an operator round-trip

Flagged at Checkpoint A: the brief's §9.5 says the default is "`--dry-run`-like
safety: report what would move, move nothing destructive," immediately followed by
a `--dry-run` flag in the command's own signature -- taken literally, there is no
way to make the command write anything, ever.

**Resolved during commit 19, on Sonnet:** the two statements describe different
kinds of "destructive." Writing a new-format secret is never destructive (nothing is
touched or removed, and the whole migrator must be idempotent), so it happens by
default with no flag needed. Deleting the legacy source is the actually destructive
action, and already has its own explicit opt-in (`--delete-source`) that never
fires by default, with or without `--dry-run`. `--dry-run` means "write nothing at
all," including the ordinarily-safe new-format write -- it reports what would
happen (migrate, skip, need `--map`) without touching the database.

So: no flags migrates everything into the new format and leaves every legacy
option in place; `--delete-source` additionally deletes each source, but only
after this run's own fingerprint verification passes, and never for a name the
vendored-copy check has flagged without `--yes`; `--dry-run` writes nothing.

**Needs Checkpoint F (or the operator) to confirm or override.** The alternative
the brief itself half-suggests -- an explicit `--execute`/`--apply` flag gating
every write, dry-run or not, as the default -- is a straightforward change to make
on top of the same underlying migrate-verify-delete logic if preferred; nothing
about the verification or vendor-detection behavior would need to change.

---

## 16. 🟡 Compat shim assumes the migrator's default namespace

`Secrets_API_Compat_Shim` (commit 20) reads and writes through a fixed `legacy/`
namespace, since the brief's §9.6 gives the shim's four global functions no
per-call configuration surface (a legacy caller's `get_secret( 'api_key' )` takes
no namespace argument to pass one through, even if it wanted to).

This matches `wp secret migrate-legacy`'s own default namespace exactly, so a site
that migrated with no `--namespace` or `--map` override keeps every legacy caller
working unmodified once the shim is enabled. A site that migrated with a custom
`--namespace` or a `--map` entry will have shim calls silently miss: `get_secret()`
will return `null` for a secret that does, in fact, exist under a different name --
indistinguishable, from the shim's own collapsed return, from one that was never
migrated at all.

Not resolved further: adding a filter to reconfigure the shim's namespace would
reintroduce exactly the class of hook this build refuses to put on the retrieval
path (see §9.6's explicit refusal to reimplement `secrets_pre_get` et al.), and a
constant is the only alternative, which is really the same shape as
`WP_SECRETS_LEGACY_SHIM` itself and adds a second flag for a fairly narrow case.
Left as documented behavior. A site with a non-default migration should not enable
the shim, or should re-migrate the affected keys into `legacy/` specifically before
doing so.

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
