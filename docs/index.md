---
title: "Secrets API documentation"
description: "Where to start, and what each directory under docs/ holds."
---

# Secrets API documentation

The [README](../README.md) explains what the API is and how to get to a green build. This
directory holds everything longer than that.

| Directory | Holds |
|---|---|
| [`spec/`](spec/) | The contracts an implementation must honour. |
| [`reference/`](reference/) | How-to material: running CI, migrating from the prototype, a drop-in skeleton. |
| [`decisions/`](decisions/) | Why the design is the way it is. One decision per file. |
| [`journal/`](journal/) | Running records: open questions, community answers, known test gaps. Dated. |
| [`changelog/`](changelog/) | Release notes. Empty until the first tagged release. |

## Start here

- **Writing a plugin that stores a credential:** the README, then
  [`decisions/namespaces-are-not-access-control.md`](decisions/namespaces-are-not-access-control.md)
  so you know what a namespace does and does not buy you.
- **Hosting platform integrating a KMS, HSM, or credential store:**
  [`spec/extension-points.md`](spec/extension-points.md), then
  [`decisions/host-provider-model.md`](decisions/host-provider-model.md) for why the provider is
  shaped the way it is, then [`reference/drop-in-example.php`](reference/drop-in-example.php)
  and [`../examples/`](../examples/) for something to copy.
- **Moving off the Displace prototype:**
  [`reference/migrating-from-displace.md`](reference/migrating-from-displace.md).
- **Contributing:** [`reference/ci.md`](reference/ci.md) to get the suite running, then
  [`journal/open-questions.md`](journal/open-questions.md) and
  [`journal/test-coverage-gaps.md`](journal/test-coverage-gaps.md) for what still needs an answer
  or a test.

## Files

### spec/
- [`extension-points.md`](spec/extension-points.md) — the provider, store, and keyring contracts; fail-closed behaviour; error codes; naming rules.
- [`envelope-encryption.md`](spec/envelope-encryption.md) — the master-key envelope as proposed versus the four-layer construction as built.
- [`retrieval.md`](spec/retrieval.md) — the three-state return and the absence of any retrieval filter.
- [`providers-and-keyrings.md`](spec/providers-and-keyrings.md) — the provider as outermost extension point and how the store and keyring relate to it.
- [`versioning.md`](spec/versioning.md) — two slots, `WP_Secret_Version` constants, and the PHP 7.4 floor.
- [`rotation.md`](spec/rotation.md) — value rotation by overwrite, retirement, and site-key rotation.
- [`import.md`](spec/import.md) — `wp_import_option_as_secret()` and why it copies rather than moves.
- [`network.md`](spec/network.md) — network scope and per-site key derivation.
- [`scope.md`](spec/scope.md) — the 7.2 target, the deferred UI, and what 0.1.0 adds beyond the named surface.

### reference/
- [`ci.md`](reference/ci.md) — local, Docker-free, and air-gapped test runs; the hosted matrix; action pinning.
- [`migrating-from-displace.md`](reference/migrating-from-displace.md) — read-time upgrade from the prototype format and the bulk migration command.
- [`drop-in-example.php`](reference/drop-in-example.php) — a runnable `secrets.php` skeleton.
- [`functions.md`](reference/functions.md) — every function, generated from docblocks.
- [`classes.md`](reference/classes.md) — every class and interface with public constants and methods, generated.
- [`hooks.md`](reference/hooks.md) — every action and filter, generated.
- [`wp-cli.md`](reference/wp-cli.md) — every `wp secret` and `wp network-secret` subcommand, generated.

### decisions/
- [`host-provider-model.md`](decisions/host-provider-model.md) — why `WP_Secrets_Provider` is the outermost extension point.
- [`namespaces-are-not-access-control.md`](decisions/namespaces-are-not-access-control.md) — namespacing groups secrets; it does not isolate them.
- [`record-format-v2-not-read-compatible.md`](decisions/record-format-v2-not-read-compatible.md) — a future record format bumps `v` rather than widening v1.
- [`no-compat-shim.md`](decisions/no-compat-shim.md) — no `get_secret()`/`set_secret()` shim for prototype-era code.
- [`out-of-scope-requests.md`](decisions/out-of-scope-requests.md) — community requests judged reasonable but outside the API.

### journal/
- [`open-questions.md`](journal/open-questions.md) — what is still deliberately undecided.
- [`proposal-questions.md`](journal/proposal-questions.md) — the five questions the proposal asked, and the answers so far.
- [`test-coverage-gaps.md`](journal/test-coverage-gaps.md) — paths the suite cannot reach and what was verified by hand.
