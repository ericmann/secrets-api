---
title: "A KMS keyring"
description: "The first real WP_Secrets_Keyring implementation: what building examples/aws-kms-keyring/ found, fixed, and left out, and what it means for the Trac patch."
date: 2026-09-24
---

# A KMS keyring

[ADR 0008](../decisions/0008-the-trac-ticket-replaces-thread-confirmation.md) named this as one of
the two examples to build before the Trac ticket. This is the first one: a real
`WP_Secrets_Keyring`, backed by AWS KMS, instead of the config-derived default.

## What I built

[`examples/aws-kms-keyring/`](../../examples/aws-kms-keyring/README.md) — `AWS_KMS_Keyring`, one
file, SigV4 by hand, no SDK, the same shape as the AWS Secrets Manager example. `wrap()` is one
`Encrypt` call, `unwrap()` is one `Decrypt` call with the key ID pinned, and a `kms1:` prefix turns
the most likely adoption mistake into a specific error instead of an opaque AWS exception.

Alongside it: `WP_Secrets_Keyring_Conformance`, the keyring equivalent of the provider conformance
suite, checking the properties `implements WP_Secrets_Keyring` cannot — non-determinism, fail-closed
on tampering, a round trip that returns exactly what went in. It runs against the shipped config
keyring, against `Mock_Keyring`, and against the KMS example over
[Moto](https://github.com/getmoto/moto), an AWS emulator, in `make test-examples`. The harness that
runs it — `phpunit-examples.xml.dist`, `tests/bootstrap-examples.php`, and the `examples` CI job —
is shared with the AWS Secrets Manager example, whose conformance run had, until now, only been
described in a README rather than actually run anywhere.

`wp secret rotate` gained `--from=config-previous|config`. The command was hard-coded to one
site-key rotation shape; it now generalises to cover adopting a new keyring over an existing root
key, which is exactly what installing this drop-in on a live site needs. See
[`examples/aws-kms-keyring/tests/test-aws-kms-keyring.php`](../../examples/aws-kms-keyring/tests/test-aws-kms-keyring.php)
for the end-to-end proof: a full round trip with the keyring active, ten secret reads making
exactly one `Decrypt` call, and adoption via `rotate --from=config` leaving every existing secret
readable.

## What it found

The spec for this example opened with three things "already known" from reading the code before
writing any of it, and building the example was there to confirm them and drive the fix, not to
discover them fresh. All three held:

- **`unwrap()` ran on every master-key derivation**, which meant every secret read, write, and
  fingerprint was its own KMS round trip. Fixed in `src/`, not in the example: `WP_Secrets_Key_Manager`
  now caches the unwrapped root key in memory for the rest of the request, keyed on the wrapped
  value it came from, so a rotation mid-request is never served a stale key. See
  [ADR 0009](../decisions/0009-root-key-cached-for-the-request.md).
- **Nothing moved an existing site onto a new keyring.** `rotate` assumed the old and new keyrings
  were always both `WP_Secrets_Config_Key_Provider`. `--from=config` is the fix, in `cli/`, which
  never lands in the Trac patch.
- **`wrap()`'s non-determinism was load-bearing but undocumented.** `rotate_site_key()` depends on
  it — `update_site_option()` reports an unchanged value as a failure — and the interface docblock
  said nothing about it. It does now, and the conformance suite checks it.

What only building the example showed, rather than what was predicted going in: `Mock_Keyring`,
the test double the conformance suite and dozens of other tests lean on, was deterministic and
returned `false` on a failed decode, so it failed the keyring contract the new conformance suite
checks. P0-01 made it non-deterministic with an integrity tag and `WP_Error` on every failure, and
it now passes the suite it stands in for.

## What I left out

Named as out of scope from the start, not discovered as a gap partway through: IAM role and
instance-metadata credentials (static credentials only, as with the Secrets Manager example), KMS
multi-region keys, and moving between two different KMS keys — KMS's own automatic key rotation
keeps the key ID stable, so that case needs no re-wrap at all. The examples suite still runs
single-site only; multisite coverage for examples is a later flight's concern. And the live-AWS
run — a fresh site, adoption with `rotate --from=config`, a count of KMS calls for a ten-secret
read — is verified by hand once, the way the Secrets Manager example was, and has not happened
yet. It is the one thing this entry cannot yet report as done.

## What it means for the patch

The root-key cache and the non-determinism sentence on `WP_Secrets_Keyring::wrap()` are both in
`src/`, so both go into the Trac patch. `cli/`'s `rotate --from` and everything under `examples/`
do not; they are plugin-and-repository-only, same as ever. Neither interface changed shape: three
methods on `WP_Secrets_Keyring` before this, three methods after. What changed is that one of them
now has a real implementation behind it instead of only the shipped default and a test double.
