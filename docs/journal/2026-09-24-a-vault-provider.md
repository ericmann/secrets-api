---
title: "A Vault provider"
description: "Building a HashiCorp Vault KV v2 provider example, the first backend whose versioning does not already match the API's two slots, and the AWS site-scope bug it turned up along the way."
date: 2026-09-24
---

# A Vault provider

[ADR 0008](../decisions/0008-the-trac-ticket-replaces-thread-confirmation.md) named this as one of
three pieces of work to finish before the Trac ticket opens: a Vault provider is the first backend
whose version model does not already share `WP_Secret_Version::CURRENT`/`PREVIOUS`'s shape, which
makes it the test of the design most likely to be wrong in a way nobody had pointed out yet.

## What was built

`examples/vault-provider/secrets.php` — a single-file `WP_Secrets_Provider` drop-in against
Vault's KV v2 HTTP API, no Composer, no SDK. Alongside it, the harness half of the work: a
`Vault_Test_Server` test helper, a `WP_Secrets_Provider_Conformance` subclass run against a real
Vault dev server, and provider-specific tests covering retirement, the two-slot cap, the rotation
flag, multisite isolation, and sealed/unreachable Vault behaviour. CI gained an `examples` job
running that suite against a pinned `hashicorp/vault` dev-mode service container, single site and
multisite. And a third, separate commit: `examples/aws-secrets-manager/`'s site-scope naming fix
(below), found while working out Vault's own paths.

## What it found

**A real defect, in the AWS example rather than the interface.**
`AWS_Secrets_Manager_Provider::aws_name()` mapped site scope to `wp/<name>` with no blog ID, so on
a network every site read and wrote the *same* AWS secret for a given name. The shipped provider
keeps site scope per site; the example was wrong, not the interface. Fixed to
`wp/site/<blog_id>/<name>`, matching what Vault does, with a README note that this is a rename on
AWS's side for anyone running an earlier copy.

**The conformance suite passed against Vault unchanged.** No new skip, no adapted assertion beyond
what the suite already allows for a read-only provider. That is decent evidence the interface
itself does not assume a two-slot backend, only that a provider can present one.

**The interface leaves "previous" undefined past two versions.** KV v2 keeps up to 10 versions by
default; this API exposes exactly two. The provider answers by setting `max_versions: 2` on every
secret it creates and defining `PREVIOUS` as strictly version N-1 — never the newest surviving
version below N, since promoting an older survivor into that slot would let
`wp_retire_secret_version()` bring back a version it was supposed to make unreachable. Recorded as
[ADR 0010](../decisions/0010-cap-a-many-version-backend-to-two-slots.md) and as an open question
for the Trac ticket, since `get()` and `retire_previous()`'s own docblocks don't say this.
`test_previous_is_strictly_n_minus_1_even_when_older_versions_survive` is the test that pins it
down.

**A provider outside the WordPress boundary still needs local key material.** Fingerprints derive
from the site master key regardless of where the value itself lives, so a `BOUNDARY_PROVIDER`
provider still depends on a working keyring and root key for one feature. This was already true of
the AWS example; Vault inherits it rather than introduces it, and it stays as a written-down
question rather than a promise.

**A small, deliberate inconsistency left alone.** The AWS example fingerprints network secrets
under the `'site'` master key, while the shipped provider — and now the Vault example — use
`'network'`. Pre-existing, outside this work's scope, and noted here rather than silently
diverging further.

## What was left out

Vault auth methods other than a static token — AppRole and Kubernetes auth are named in the README
as the production path, not implemented. Check-and-set (`cas`) on writes, the answer to two
writers racing on the same secret, named but not built. KV v1 and the dynamic-secret engines,
which don't fit a stored-secret API at all. A compatibility read for the AWS rename — before 1.0,
a read that fell back to the old flat name would silently share secrets across blogs again, which
is the exact bug this work just fixed. And no change to anything under `src/`: everything here is
interface-level evidence, recorded in the journal, not a signature change.

## What it means for the Trac patch

Two items for the ticket description: `get()` and `retire_previous()` should document what
"previous" means on a backend that keeps more than two versions (the strict-N-1 answer, now
proven against a real one), and the provider contract should note that a `BOUNDARY_PROVIDER`
implementation may still depend on local key material for fingerprinting. Neither changes a
function signature.

See [`examples/vault-provider/README.md`](../../examples/vault-provider/README.md) for the
operator-facing detail and the four questions in full.
