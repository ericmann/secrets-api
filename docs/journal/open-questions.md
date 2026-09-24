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
AWS for set, masked read, rotation, and `--slot=previous`. Building it turned up four defects,
none of them in the interface: a provider global of the wrong type fell through to the default
provider instead of failing closed, `wp secret dropin` reported internals rather than the provider,
and three WP-CLI dispatch bugs surfaced on the first end-to-end run. The two-slot version model
mapped onto `AWSCURRENT`/`AWSPREVIOUS` with no emulation.

**What is still open:** that is one provider, written by the same hands as the interface. The
conformance suite has not been run against it in an automated test, only described in its README,
and no host has built against `WP_Secrets_Provider` independently. A keyring backed by a
key-management service, which `examples/README.md` recommends as the first integration to write,
has no example at all.

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

