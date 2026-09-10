---
title: "ADR 0006: Record format v2 will not be read-compatible with v1"
description: "The stored record carries a format version so a future change is detectable. A future v2 bumps that version rather than widening v1, and an unknown version is rejected before decryption."
---

# ADR 0006: Record format v2 will not be read-compatible with v1

| | |
|---|---|
| **Number** | 0006 |
| **Date** | 2026-08-27 |
| **Status** | Accepted |

## Context

Every stored record carries `'v' => 1`, the value of `WP_SECRETS_RECORD_VERSION`. It exists so
that a future change to the record's shape is detectable as such, rather than presenting as a
decryption failure an operator would misread as a lost key.

The field sits outside the additional authenticated data, so it is unauthenticated metadata.
Anyone who can write to the store can set it to anything without disturbing the ciphertext.

## Decision

A future v2 record format will not be read-compatible with v1. Nobody should design v2 assuming a
v1 reader can make sense of it, and no change should quietly widen v1's shape instead of bumping
the version.

Because `v` is unauthenticated, it is treated as a routing hint that is validated before any
decryption is attempted. A record whose version is not one this code understands is rejected
outright with `WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION`, distinct from
`WP_SECRETS_ERROR_RECORD_MALFORMED`, so Site Health can tell a record written by a newer plugin
apart from a corrupt one. That behaviour is tested.

How sites move from v1 to v2 is deferred until v2 exists. The two options are a migration pass
over existing records or a version-switched read path that keeps the v1 decoder alongside the v2
one. They differ mainly in whether sites take a one-time cost or the code carries two decoders
indefinitely, and that trade-off cannot be judged before there is a v2 to weigh it against.

## Consequences

- A version bump is a real event with a migration story, not a routine change. The bar for one
  is correspondingly high.
- Any future format work starts by reading this record and choosing between the two upgrade
  paths, which remains an open question in the journal.
- An attacker who rewrites `v` gains nothing but a refusal: the record fails closed before any
  key material is used.
