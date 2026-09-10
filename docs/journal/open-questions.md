---
title: "Open questions"
description: "What this implementation deliberately did not decide, with the conservative choice made in the meantime."
date: 2026-09-04
---

# Open questions

Things this implementation deliberately did **not** decide. Each entry states the conservative
choice that was made, where the relevant code is, and who needs to resolve it.

**This file holds only what is still open.** Closed questions get deleted rather than archived.
The reasoning behind a decision belongs next to the code that implements it, where someone reading
that code will find it, and a long list of answered questions just makes the unanswered ones
harder to spot. Entries are referenced by heading rather than number, so removing one never leaves
a dangling reference.

Status legend: 🔴 blocks a release · 🟡 needs an answer before the core patch · 🟢 tracking only

---

## 🟢 Host and platform providers — implemented, awaiting real implementations

Hosts independently reported that the published extension contract could not express what they
need: Chris Reynolds (Pantheon), Ryan McCue and Rafael Meneses (Altis), and the control-panel
model in which a platform dashboard is the system of record and an HSM does the protecting.
Rafael Meneses supplied the reframe that resolved it:

> A provider can be stronger than the default, never weaker. Plaintext storage stays banned… and
> the setups Ryan and Chris describe stop being banned with it.

**Done.** `WP_Secrets_Provider` is the outermost extension point and the public functions route
through it. `WP_Secrets_Libsodium_Provider` — the shipped default — is one implementation of that
interface rather than a privileged case, composed from a `WP_Secrets_Store` and a
`WP_Secrets_Keyring` so that a host wanting only their own key custody still swaps only the
keyring. A drop-in installs a platform provider by setting `$GLOBALS['wp_secrets_provider']`.
`supports()` is gone, replaced by `get_label()`, `get_protection_boundary()`, and `is_writable()`.
`reveal()` returns `string|WP_Error` and `WP_Secret::withheld()` exists for credentials a provider
will not release to PHP. Site Health and `wp secret dropin` report all of it.

**What hasn't happened:** nobody has written a real platform provider against this yet. The
interface is shaped by hosts describing what they need rather than by anyone building against it,
and the first real implementation will turn something up. That is what to ask for in the comments
thread: not "does this look right" but "build against it and tell us what broke."

See [ADR 0001](../decisions/0001-provider-as-outermost-extension-point.md) for the reasoning, including why a provider
declaration is documentation rather than enforcement.

---

## 🟢 Testability smells

If something is hard to test, that is usually a design smell, so it gets written down here
rather than skipped.

- `var_export()` of a `WP_Secret` cannot be masked from userland — it ignores `__debugInfo()` and
  `__toString()` and emits private properties directly. Mitigated by not storing the plaintext as
  an object property at all. Documented as a known limitation regardless.
- The `options.php` all-settings screen reads the options table directly with no filter, so a
  plugin cannot exclude secrets from it. Surfaced as a Site Health warning and documented as a
  core-patch-only fix. There is an existing core ticket and pull request on plaintext display in
  `options.php` to reference.

