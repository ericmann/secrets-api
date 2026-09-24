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

**What the Vault example added:** a second provider, `examples/vault-provider/`, this time against
a backend whose versioning does not already match — Vault's KV v2 engine numbers versions 1, 2, 3,
and so on, rather than keeping two named slots. The conformance suite now runs against it
automatically in `make test-examples`, on a real Vault dev server, rather than being described
only in a README. It found one real defect, in the AWS example rather than the interface:
`AWS_Secrets_Manager_Provider::aws_name()` mapped site scope to a flat name with no blog ID, so
every site on a network shared one AWS secret for a given name. Past that, the conformance suite
passed against Vault unchanged, and the two-slot model held once the provider capped Vault at
`max_versions: 2` — evidence for question 2 in `proposal-questions.md`.

---

## What "previous" means on a backend with more than two versions

🟡 Needs an answer before the core patch.

The Vault example made a conservative choice rather than waiting for one: `PREVIOUS` is strictly
version N-1, and `null` when N-1 is missing, soft-deleted, or destroyed — never the newest
surviving version below N. The code is `Vault_KV2_Provider::previous_version()` in
`examples/vault-provider/secrets.php`. The interface docblocks for `get()` and `retire_previous()`
do not themselves define what "previous" means once a backend keeps more than two versions, which
is fine for the shipped provider and the AWS example (neither has this problem) but was undefined
before Vault. Resolution belongs on the Trac ticket description as a docblock clarification. See
[ADR 0009](../decisions/0009-cap-a-many-version-backend-to-two-slots.md).

## A provider outside the WordPress boundary still needs a root key

🟢 Tracking only.

Fingerprints derive from the site master key, so a site whose values live entirely in a provider
reporting `BOUNDARY_PROVIDER` still depends on a working keyring and root key for one feature. This
is inherited from the AWS example rather than introduced by Vault, and stays as-is. Where the code
is: `AWS_Secrets_Manager_Provider::build_secret()` in `examples/aws-secrets-manager/secrets.php`,
and `Vault_KV2_Provider::build_secret()` in `examples/vault-provider/secrets.php`.

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

