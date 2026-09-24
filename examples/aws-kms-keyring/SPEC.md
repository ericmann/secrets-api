# Spec: AWS KMS keyring example

Status: planned. Part of the pre-Trac work in
[ADR 0008](../../docs/decisions/0008-the-trac-ticket-replaces-thread-confirmation.md).

## Why this example

`examples/README.md` tells hosts to start with a KMS keyring: three methods, key custody moves to
the KMS, nothing else changes. No such example exists, so that advice has never been tested. This
is the first real implementation of `WP_Secrets_Keyring` other than the config keyring shipped in
`src/`.

The interface is small, so the value is not in the three methods. It is in the three questions
around them, which nobody has had to answer yet:

1. **How often does WordPress call `unwrap()`?**
2. **How does an existing site move onto a new keyring?**
3. **What does a keyring have to guarantee that the interface does not say?**

## What is already known

These came from reading the code while writing this spec. The example exists to confirm them and
drive the fix, not to discover them.

- **`unwrap()` runs on every master-key derivation.** `WP_Secrets_Key_Manager::get_root_key()`
  reads the option and calls `$this->keyring->unwrap()` every time, with no cache.
  `get_master_key()` calls it for every secret read, every write, and every fingerprint. With the
  config keyring that is a local libsodium call and costs nothing. With KMS it is one network round
  trip per secret. `examples/README.md` says "the KMS gets called once per request at most", which
  is not what the code does today.
- **There is no command that moves a site onto a new keyring.** `wp secret rotate` hard-codes
  `new WP_Secrets_Config_Key_Provider( true )` as the old keyring and `( false )` as the new one. A
  site that installs a KMS drop-in over an existing root key fails closed, because KMS cannot unwrap
  a config-keyring blob, and nothing in the shipped tooling can re-wrap it.
- **`wrap()` must be non-deterministic.** `rotate_site_key()` stores the re-wrapped value with
  `update_site_option()`, which returns false when the value is unchanged, and treats that as a
  failure. Its own comment says this is safe only because `wrap()` draws a fresh nonce. The
  interface docblock does not say so. KMS `Encrypt` is non-deterministic, so the example is fine,
  but the requirement belongs in the contract.

## Deliverables

### 1. `examples/aws-kms-keyring/secrets.php`

A single-file drop-in, following the conventions of the AWS Secrets Manager example: no Composer,
no SDK, SigV4 by hand, `wp_remote_post()`. The SigV4 signer is copied rather than shared, because
each example has to be one file a reviewer can read from top to bottom.

`final class AWS_KMS_Keyring implements WP_Secrets_Keyring`:

| Method | Behaviour |
|---|---|
| `wrap( $key_material )` | `TrentService.Encrypt` with `KeyId`, `Plaintext` (base64), and `EncryptionContext: { "wp-secrets": "root-key-v1" }`. Returns `'kms1:' . CiphertextBlob`. |
| `unwrap( $wrapped )` | Rejects anything without the `kms1:` prefix with `WP_SECRETS_ERROR_KEY_UNAVAILABLE` and a message that says the root key was wrapped by a different keyring and how to move it (see deliverable 3). Otherwise `Decrypt` with `KeyId` pinned, the same `EncryptionContext`, and the blob. Verifies the result is exactly 32 bytes. |
| `get_key_source()` | `AWS KMS key <key id> in <region>`. The key ID is an identifier, not key material. |

Design points, each explained in the file:

- **The encryption context is fixed, not per-site.** KMS authenticates it the way the cipher
  authenticates AAD. Binding it to `home_url()` would make a domain change unrecoverable. There is
  one root key per install, so there is nothing per-site to bind.
- **`KeyId` is pinned on `Decrypt`.** Without it, KMS decrypts with whichever key the blob names,
  and a swapped blob under a key the IAM role can also use would succeed.
- **The `kms1:` prefix** turns the most likely adoption failure, a config-keyring blob, into a
  specific, actionable error instead of an opaque `InvalidCiphertextException`.
- **Timeouts are short (3 s).** Every secret operation waits on this call. A KMS outage turns
  every read into a `WP_Error`, which is the fail-closed behaviour we want. The README says so.
- **Install guard.** Install only when `WP_SECRETS_KMS_KEY_ID`, `WP_SECRETS_AWS_REGION`,
  `WP_SECRETS_AWS_KEY`, and `WP_SECRETS_AWS_SECRET` are all non-empty, for the reason recorded in
  the AWS Secrets Manager example's guard. An optional `WP_SECRETS_AWS_ENDPOINT` points the
  keyring at an emulator.

Out of scope: IAM role and instance-metadata credentials (named in the README as what to use in
production), KMS multi-region keys, and moving between two different KMS keys. KMS automatic key
rotation keeps the key ID and decrypts old blobs, so that case needs no re-wrap.

### 2. Root-key caching in the key manager (a change to `src/`)

Fix the call volume in the key manager, not in the example. Every remote keyring would otherwise
have to rediscover the problem and cache key material in its own way.

- `WP_Secrets_Key_Manager` keeps the unwrapped root key for the rest of the request, keyed on the
  wrapped value it came from, so a re-wrap or rotation replaces it. Memory only. It must never go
  near the object cache.
- `rotate_site_key()` and `generate_root_key()` update the cached value.
- Tests: unwrap is called once across N `wp_get_secret()` calls (a counting mock keyring); a
  rotation mid-request is not served the stale key; a `WP_Error` from `unwrap()` is not cached, so
  a transient KMS failure is not pinned for the rest of the request.
- The existing `wp_secrets_memzero()` discipline around `$root_key` stays: callers receive a copy
  and zero their copy. What changes is that one copy lives for the request, and the class docblock
  has to say so plainly rather than let the memzero calls imply otherwise.
- Correct `examples/README.md`'s "once per request at most", which becomes true with this change.

Because this is in `src/`, it lands in the Trac patch. It is the example doing what ADR 0008 says
it is for.

### 3. `wp secret rotate --from=<keyring>` (a change to `cli/`)

Generalise the command, not the example. `cli/` is never copied into core, so this costs nothing
on the patch.

- The new keyring becomes whatever keyring is active: the drop-in's if one is set, otherwise
  `WP_Secrets_Config_Key_Provider( false )`. On a site with no drop-in, that is exactly today's
  behaviour.
- `--from=config-previous` (the default) keeps today's behaviour and today's check that
  `WP_SECRETS_KEY_PREVIOUS` is defined.
- `--from=config` unwraps with the current `WP_SECRETS_KEY`. This is the adoption case: the
  site key has not changed, but a new keyring has been installed.
- Refuses if the old and new keyrings resolve to the same configuration, with a message rather
  than a no-op success.
- The KMS README walks through adoption: install the drop-in, run
  `wp secret rotate --from=config`, then check `wp secret health`. Between the first two steps
  every secret read fails closed. The README says so and says to do both steps in one maintenance
  window.

### 4. `WP_Secrets_Keyring_Conformance` (in `tests/includes/`)

The keyring equivalent of the provider conformance suite. It is an abstract test case with a
`keyring()` method to implement, and it checks what `implements` cannot:

- `wrap()` of 32 random bytes returns a non-empty string, and `unwrap()` of that string returns
  the same bytes.
- Two `wrap()` calls on the same bytes return different strings. `rotate_site_key()` depends on
  this, and the interface docblock gains a sentence saying so.
- `unwrap()` of garbage, of a truncated value, and of a value with one flipped byte each returns
  `WP_Error`. It never throws and never returns a string.
- `get_key_source()` returns a non-empty string.

It runs in `make test` against `WP_Secrets_Config_Key_Provider` and `Mock_Keyring`, the same way
the provider suite runs against the shipped provider.

### 5. An examples test harness

This is shared with the Vault example. Building it here, since this example comes first, also
closes the gap where the AWS Secrets Manager README describes a conformance run that nothing
performs.

- Tests live in `examples/<name>/tests/`. `phpunit-examples.xml.dist` bootstraps through the main
  `tests/bootstrap.php` and loads the example's `secrets.php` class file without its install block,
  since tests construct the class directly.
- A `make test-examples` target. It stays out of `make ci`, because it needs service containers
  that `make ci`'s environments do not provide.
- A CI job, `examples`, with a Moto server (`motoserver/moto`, Apache-2.0) as a service container.
  Moto emulates both KMS and Secrets Manager, including `AWSCURRENT`/`AWSPREVIOUS`. The image is
  pinned by digest, in keeping with the pin-by-SHA rule in `ci.yml`.
- The AWS Secrets Manager example gains the same optional `WP_SECRETS_AWS_ENDPOINT` constant so it
  can run against Moto, plus a test class that runs `WP_Secrets_Provider_Conformance` against it.
- KMS tests: the keyring conformance suite; a full `wp_set_secret()`/`wp_get_secret()` round trip
  with the KMS keyring installed as the active keyring; a config-keyring blob gives the specific
  error; and adoption via the rotate path in deliverable 3 leaves every secret readable.

Live AWS is verified by hand once, the way the Secrets Manager example was, and the result goes
in the commit message.

## Done when

- Deliverables 1 to 5 are merged, and `make ci` and the `examples` CI job are green.
- A manual run against live KMS covers: a fresh site, adopting an existing site with
  `rotate --from=config`, and a count of KMS calls for a request that reads ten secrets, which
  should be one.
- `docs/journal/test-coverage-gaps.md` and `docs/journal/open-questions.md` record what changed,
  and the providers-and-keyrings spec page's "As built" section covers root-key caching.
