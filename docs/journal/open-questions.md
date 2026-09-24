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

## Host and platform providers

🟢 Tracking only.

Hosting platforms on the proposal thread showed that the published two-point contract could not
express deployments they already run, and one commenter supplied the reframe that resolved it: a
provider can be stronger than the default, never weaker.
[ADR 0001](../decisions/0001-provider-as-outermost-extension-point.md) records the discussion,
the decision, and what shipped in 0.1.0.

**What has been built:** one real provider, `examples/aws-secrets-manager/`, verified against live
AWS for set, masked read, rotation, and `--slot=previous`, and its conformance suite is now
automated against Moto in `make test-examples` rather than only described in its README. Building
it turned up four defects, none of them in the interface: a provider global of the wrong type fell
through to the default provider instead of failing closed, `wp secret dropin` reported internals
rather than the provider, and three WP-CLI dispatch bugs surfaced on the first end-to-end run. The
two-slot version model mapped onto `AWSCURRENT`/`AWSPREVIOUS` with no emulation. A KMS keyring
example, `examples/aws-kms-keyring/`, now exists too, and building it changed `src/` once
(request-scoped root-key caching, [ADR 0009](../decisions/0009-root-key-cached-for-the-request.md))
and the CLI once (`wp secret rotate --from` generalised to cover adoption, not only a site-key
change).

**What is still open:** those are two providers and one keyring, written by the same hands as the
interfaces, and no host has built against `WP_Secrets_Provider` or `WP_Secrets_Keyring`
independently.

---

## Testability smells

🟢 Tracking only.

If something is hard to test, that is usually a design smell, so it gets written down here
rather than skipped.

- `var_export()` of a `WP_Secret` cannot be masked from userland — it ignores `__debugInfo()` and
  `__toString()` and emits private properties directly. Mitigated by not storing the plaintext as
  an object property at all. Documented as a known limitation regardless.
- The `options.php` all-settings screen reads the options table directly with no filter, so a
  plugin cannot exclude secrets from it. Surfaced as a Site Health warning and documented as a
  core-patch-only fix. There is an existing core ticket and pull request on plaintext display in
  `options.php` to reference.

