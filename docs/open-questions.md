# Open questions

Things this implementation deliberately did **not** decide. Each entry states the conservative
choice that was made, where the seam is, and who needs to resolve it.

**This file holds only what is still open.** Closed questions are deleted rather than archived —
the reasoning for a decision belongs next to the code that implements it, where someone reading
that code will actually find it, and a file of answered questions makes the unanswered ones harder
to see. Entries are referenced by heading rather than by number, so removing one never dangles a
reference elsewhere.

Status legend: 🔴 blocks a release · 🟡 needs an answer before the core patch · 🟢 tracking only

---

## 🟡 Host and platform providers

Three platforms independently reported that the published extension contract cannot express what
they need: Chris Reynolds (Pantheon), Ryan McCue and Rafael Meneses (Altis), and the VIP
dashboard/HSM model this project wants to support directly.

> For a platform store that's inverted — the store *is* the encryption boundary, and it *needs*
> the plaintext over an authenticated channel so the same secret is usable from other environments
> on the same account. If the drop-in only ever sees WP's ciphertext, we'd be double-encrypting
> and the value would be opaque outside WordPress.
> — Chris Reynolds

> A storage drop-in for us would need to serve `wp_get_secret()` and reject `wp_set_secret()` —
> but the API has no way to express "readable, but not writable here," and every plugin settings
> screen assumes `set()` works.
> — Chris Reynolds

> A provider can be stronger than the default, never weaker. Plaintext storage stays banned… and
> the setups Ryan and Chris describe stop being banned with it.
> — Rafael Meneses

**Where this stands.** The reframe is accepted and [`host-provider-model.md`](host-provider-model.md)
works it through. `WP_Secrets_Provider` is written (`src/wp-includes/interface-wp-secrets-provider.php`),
`WP_Secret::withheld()` exists, and `reveal()` returns `string|WP_Error`.

**What is left:** routing the public functions through a provider, composing the shipped one out
of the existing store/cipher/keyring, and removing `supports()` from the store contract. Until
that lands, the store is still the seam and platform providers cannot actually be installed.

Still true regardless: **do not** implement a plaintext passthrough on `WP_Secrets_Store`. The
whole point of a separate interface is that a store cannot become a plaintext sink by flag.

---

## 🟡 API surface that was never published

The proposal published exactly four functions and two `WP_Secret` methods. Everything below is
this implementation's invention and should be confirmed in the comments thread **before it
hardens**, because names are the hardest thing to change after adoption.

| Added | Justification | Risk |
|---|---|---|
| `wp_list_secrets()` / `wp_list_network_secrets()` | "The hooks and accessors an admin screen would need are in scope now" | Name and return shape unpublished |
| `wp_retire_secret_version()` / `wp_retire_network_secret_version()` | "Retiring the previous slot is an explicit operator action — no timers, no cron" | Function implied but never named |
| `wp_set_network_secret()`, `wp_get_network_secret()`, `wp_delete_network_secret()` | "Site secrets and network secrets are separate functions with separate capabilities" | **Names never published.** Highest-risk group — easy to assume these were committed to |
| `WP_Secret::get_name()` | Needed for the `[secret:{name}]` mask | Only `reveal()` and `fingerprint()` were published |
| `WP_Secret::withheld()`, and `reveal()` returning `string\|WP_Error` | Broker-held and use-only credentials, per Rafael Meneses | A *widened published return type*. Cannot be done after adoption, which is why it was done now |
| `WP_Secrets_Provider`, `WP_Secrets_Store`, `WP_Secrets_Keyring` and every method on them | The proposal describes extension points and names none of them | Hosts will build against these. Largest unpublished surface in the project |
| `wp_secrets_memzero()`, `wp_secrets_validate_name()`, `wp_secrets_store_supports()`, `wp_using_secrets_dropin()` | Implementation necessities | New globals in core-bound code |
| `wp_secret_changed` hook name and argument order | Post commits to "actor, timestamp, and old and new fingerprints"; `$action` is an addition | Unpublished |
| `WP_Secrets_Cipher`, `WP_Secrets_Key_Manager`, `WP_Secrets_Option_Store`, `WP_Secrets_Config_Key_Provider`, `WP_Secrets_Broken_Store`, `WP_Secrets_Broken_Keyring` | The envelope described in the proposal has to be built out of *something* | Six permanent class names in `wp-includes`. The proposal names none of them, and the `Broken_*` pair in particular encodes a fail-closed design decision in a class name |
| `WP_SECRETS_ERROR_*` constants | The proposal publishes error *strings* (`secret_decryption_failed` et al.) but no constant names | Callers will `use` whichever spelling ships first |
| `secret_invalid_argument`, `secret_provider_read_only` | Added for caller-error reporting and read-only providers; the proposal's error list predates both | Genuinely new error *strings*, not just new constant names over published ones |
| `WP_SECRETS_CAP_MANAGE` / `WP_SECRETS_CAP_MANAGE_NETWORK` | Wrappers over the two published capability strings | Strings match the proposal exactly (`manage_secrets`, `manage_network_secrets`); only the constant names are new |
| `WP_SECRETS_MAX_NAME_LENGTH` (172), `WP_SECRETS_RECORD_VERSION` (1) | A cap and a format version both have to exist | 172 is derived, not published — 191 minus the longest option prefix |
| Accepting unnamespaced names at all | Adoption path for prototype-era code | The one place the implementation deliberately *loosened* a stated rule rather than adding to one. See "Access control language" below for the consequence |
| The `wp-content/secrets.php` drop-in filename, and `$GLOBALS['wp_secrets_store']` / `$GLOBALS['wp_secrets_keyring']` | The proposal describes drop-in-style replacement without naming the file or the mechanism | A drop-in filename is effectively permanent once hosts ship against it, in the same way `object-cache.php` is |

Not on this list, deliberately: `Secrets_API_Legacy_Reader`, `Secrets_API_Migrator`, and
`Secrets_API_Prototype_Fallback_Store` live in `plugin/`, are never copied into core, and are not
proposed for it. They need no comments-thread note, only deletion when the compatibility window
closes — see the deletion seam documented in `secrets-api.php`.

---

## 🟡 Access control language

The proposal says namespacing exists "so a future admin screen can group by owner and
cross-namespace access has something to check against" — which leaves the door open to a future
check without specifying one.

The prior proof-of-concept's README described namespace-based access control in a way that led
people to believe one plugin's secret was inaccessible to another. Darin Kotter raised this in the
comments. It was not true then and is not true now.

**Documentation must state plainly:** there is no per-plugin isolation in 7.2. Masking is hygiene
against shoulder-surfing and accidental logging, not a privilege boundary. Any plugin that can run
PHP can read any secret.

Phrase this as "no isolation *in 7.2*" rather than "cannot be" — the proposal deliberately left
room for a future check, and the docs should not close a door the proposal held open.

**Sharpened by unnamespaced names.** Accepting `wp_get_secret( 'api_key' )` means a secret can now
exist with no namespace at all, which gives a future cross-namespace check nothing whatsoever to
check against, and lets two plugins that both pick `api-key` collide silently. That is the cost of
the adoption path and it is worth stating in the same breath as the isolation language, because
anyone designing that future check needs to know unnamespaced names are in the corpus.

---

## 🟡 Record format version bump policy

`'v' => 1` exists so a future format change is detectable rather than presenting as a decryption
failure. The upgrade path for `v2` is **not designed**. Open sub-questions:

- Is `v2` read-only-compatible with `v1`, or is there a migration pass?
- `v` sits outside the AAD, so it is unauthenticated metadata. It must be treated as a routing
  hint validated *before* decryption, and an unknown `v` must be rejected outright rather than
  attempted.

**Deliberately deferred by the operator — a future problem, not an oversight.** What matters is
that the seam exists and fails safe today: `'v' => 1` is written on every record, an unrecognised
value returns `secret_record_unsupported_version` rather than being guessed at, and that behaviour
is tested. Designing the v1→v2 path before there is a v2 would be inventing constraints for a
format nobody has specified.

---

## 🟢 The five questions the proposal asked the community

These have a home here so answers from the comments thread land somewhere rather than being
absorbed into an assumption.

1. **Is the no-filter decision on retrieval sufficient, with providers as the substitution?**
   — **Answered, and the answer was "not as published."** Three hosts independently found the
   provider contract could not express their deployments. The no-filter decision itself was never
   challenged; what failed was the contract's shape. See "Host and platform providers" above.
2. **Are two version slots (`CURRENT`/`PREVIOUS`) adequate, or is a different rotation pattern
   necessary?** — no answers recorded yet. `'v' => 1` leaves room to change this, but see "Record
   format version bump policy".
3. **Does `wp_import_option_as_secret()` fit actual plugin migration workflows?**
   — no answers recorded yet.
4. **Which WP-CLI commands most need this surface, and in what priority order?** — the command set
   implemented here is a starting set, not a settled one. Track real answers rather than assuming.
5. **For hosts running secret stores or key backends: what is missing from the drop-in surface?**
   — answered at length by Pantheon and Altis; see "Host and platform providers".

---

## 🟢 `sodium_compat` is never exercised by the test suite

Core's bundled `sodium_compat` (`polyfill-1.0.8`) does implement
`sodium_crypto_kdf_derive_from_key()` — verified against the source in WordPress trunk, in
`lib/php72compat.php` behind an `is_callable()` guard, backed by a real implementation in
`ParagonIE_Sodium_Compat::crypto_kdf_derive_from_key()` rather than a "not implemented" stub. That
was the original worry and it is settled.

What remains is that **nothing in CI ever runs that path.** Every environment the suite runs in —
wp-env locally, `shivammathur/setup-php` in CI — has the libsodium extension loaded, so the
polyfill is never reached. A regression there, or a core downgrade of the bundled polyfill, would
be invisible to this project's tests while breaking exactly the hosts the fallback exists for.

Worth a CI leg with the extension disabled if that turns out to be arrangeable; `setup-php` can
build without it, but the WordPress test suite's own requirements have not been checked against
that.

Related and unchanged: `sodium_memzero()` really is a no-op under `sodium_compat`, because a
userland polyfill cannot reach a PHP string's memory. `wp_secrets_memzero()`'s docblock says so
rather than overclaiming.

---

## 🟢 Community requests not in scope

- **Two Factor plugin integration** (Brian Haas). Reasonable, out of scope for the API itself.
  Worth a note about whether the Two Factor plugin should be an early consumer.
- **Iterating UX/DX inside the AI plugin's Key Encryption experiment before core merge**
  (Jeffrey Paul). This plugin touches the AI plugin only through the prototype-format upgrade
  path. Whether the two efforts should coordinate is a project question.

---

## 🟢 Testability smells

Per the build brief: if something is hard to test, that is usually a design smell, and it gets
written down here rather than skipped.

- `var_export()` of a `WP_Secret` cannot be masked from userland — it ignores `__debugInfo()` and
  `__toString()` and emits private properties directly. Mitigated by not storing the plaintext as
  an object property at all. Documented as a known limitation regardless.
- The `options.php` all-settings screen reads the options table directly with no filter, so a
  plugin cannot exclude secrets from it. Surfaced as a Site Health warning and documented as a
  core-patch-only fix. There is an existing core ticket and pull request on plaintext display in
  `options.php` to reference.

---

## 🟢 Drop-in file loading is not directly covered by an automated test

`wp_secrets_api_load_dropin()` (in `secrets-api.php`) runs once, during
`wp_secrets_api_bootstrap()`, which itself runs once per PHP process via `muplugins_loaded`. Both
that function and `_wp_secrets_get_store()` / `_wp_secrets_get_key_manager()` cache their result
in a function-local `static` on first call, with no reset hook. By the time any test method's body
runs — even in a `@runInSeparateProcess` test — the process's one bootstrap pass has already
completed, so a drop-in file placed on disk from within a test body arrives too late to affect
that process's `wp_secrets_api_load_dropin()` call.

**What is covered:** the consumption side — `_wp_secrets_get_store()` and
`_wp_secrets_get_key_manager()` correctly using `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']`, and falling back to `WP_Secrets_Broken_Store` /
`WP_Secrets_Broken_Keyring` when `$GLOBALS['wp_secrets_dropin_broken']` is set — is tested
directly in `tests/phpunit/test-secrets-extension-points.php` by setting those same globals in an
isolated process, exactly as a real drop-in would, before the first call that would cache a
default.

**What is not covered:** `wp_secrets_api_load_dropin()`'s own `require`-and-`try`/`catch` around
an actual drop-in file. That behaviour was verified empirically instead, once, directly against
the PHP engine on both 7.4.33 and 8.5.7:

- A syntax error in the required file *is* caught as a `ParseError` by `catch ( \Throwable $e )`
  around the `require` — confirmed on both versions.
- A class that `implements` an interface but omits a required method is an **uncatchable fatal
  error**, even inside that same `try`/`catch` — also confirmed on both versions. This is a
  PHP-engine limitation, not a bug in the catching code: there is no userland way to intercept it.

So a malformed drop-in fails safely for syntax errors and thrown exceptions, but a drop-in whose
class silently fails to fully implement its interface can still produce a fatal error page. Worth
another look if a process-spawning integration test is judged worth the complexity later.

---

## 🟢 `wp secret set --stdin`'s own code path is not covered by an automated test

`WP_CLI_Secret_Command::set()` reads `--stdin` via `file_get_contents( 'php://stdin' )`. Faking
that stream meaningfully from inside a PHPUnit process would need a real pipe (`proc_open`), and
getting it wrong risks hanging the whole test run waiting on a stream nothing is writing to — not
worth it for one branch. Every other branch of `set()` (positional value, missing value, the
shell-history warning, success/porcelain/error reporting) is covered directly.

---

## 🟢 `make coverage`'s numbers cannot be trusted inside wp-env

`make coverage` runs and produces a report, but the percentages it reports from the wp-env
container are not reliable and should not be read as a statement about test thoroughness. This is
a tooling gap, not a test-coverage gap.

**What was verified before writing this down:** the wp-env tests-cli image ships no coverage
driver. Installing `pcov` via `pecl` works and produces a report, but every class in `plugin/` and
`cli/` reports exactly 0% line and method coverage, while every class in `src/` reports
partial-to-full coverage in the same run — despite dozens of passing, assertion-bearing,
non-isolated tests calling methods on the "0%" classes directly.

Three explanations were checked and ruled out rather than assumed: not a symlink/realpath mismatch
(both resolve identically inside the container); not `@runInSeparateProcess` coverage failing to
merge (the affected tests use no isolation); not pcov's initial table sizing (raised 8×, no
change — though that also revealed `--filter` degrading collection further, a second symptom of
the same unexplained cause). What is not known is why the split falls exactly along the
`plugin/`+`cli/` vs `src/` boundary when both are required through the same bootstrap.

Left as-is: no coverage threshold gates anything in `make ci`. Trustworthy numbers, if wanted,
should come from the non-Docker path against a host PHP with a coverage driver installed normally,
not via a `pecl install` into an already-running container.
