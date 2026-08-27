# The host-provider model

Working document for [`open-questions.md`](open-questions.md) #2. Nothing here is implemented.
It exists to answer a question the proposal asked and three hosts answered "not quite":

> Providers are meant to cover what a filter would normally give you — is that substitution
> sufficient for the cases you'd actually need to hook?

The honest answer from the comments thread is **no, not yet** — and the reason is narrow and
fixable.

## What three platforms actually asked for

| | Who holds the credential | Who encrypts it | Writes | Can WordPress read the value? |
|---|---|---|---|---|
| **Default (self-hosted)** | WordPress, in `wp_options` | WordPress | `wp_set_secret()` | Yes |
| **Pantheon** (Chris Reynolds) | The platform | The platform | Platform tooling | Yes, over an authenticated channel |
| **Altis** (Ryan McCue / Rafael Meneses) | KMS-backed store | The KMS | Host tooling; store is read-only to WP | Yes |
| **VIP** (intended) | VIP dashboard | The platform / HSM | Dashboard only | Yes, at runtime |

Every one of these is *stronger* at rest than the default. Not one of them can be expressed today.

## Why the current contract blocks them

The proposal says of the two drop-in extension points:

> Neither is ever handed a plaintext secret, and neither can turn encryption off.

That sentence does two jobs, and only one of them is load-bearing:

1. **Encryption cannot be turned off.** Non-negotiable. This is what stops the API degrading into
   the plaintext-in-`wp_options` situation it exists to end.
2. **The store never sees plaintext.** This is *one implementation* of (1), not (1) itself.

Rafael Meneses (Altis) put the correction better than I can paraphrase it:

> A provider can be stronger than the default, never weaker. Plaintext storage stays banned… and
> the setups Ryan and Chris describe stop being banned with it.

That is the whole fix. Replace "never handed plaintext" — a mechanism — with **"never weaker than
the default"** — the actual property. Plaintext-at-rest stays banned. An HSM that receives a value
over an authenticated channel and never writes it to disk is not weaker than AES-256 in
`wp_options`; it is the thing `wp_options` was a poor substitute for.

## What must not flex

Stating these first, because "we relaxed the guarantee" is a sentence that should come with fences:

- **The default path is unchanged.** No drop-in, no platform: encryption is unconditional, there
  is no constant to disable it, and nothing is handed a plaintext.
- **Plaintext at rest in the database stays impossible.** The shipped `WP_Secrets_Option_Store`
  never sees a plaintext and never will.
- **No filter on the retrieval path.** A provider is a *swap*, not a hook. This distinction is the
  proposal's own and it survives intact: you replace the component, you do not intercept the value
  on its way past.
- **Fail closed.** A provider that cannot answer returns `WP_Error`. It never falls through to a
  weaker source, and WordPress never "helpfully" answers from somewhere else.
- **The three-state contract.** `WP_Secret|null|WP_Error`, never collapsed, regardless of who
  answered.

## Being honest about what WordPress can verify

Almost nothing, and the design should say so rather than implying otherwise.

A drop-in is already fully trusted code — it runs before plugins and can implement the keyring. A
drop-in that wanted to exfiltrate secrets can do that today, with or without this change. So the
"never handed plaintext" rule was never a sandbox around a hostile drop-in. It was a guard rail
against a *careless* one, and against the API's shape encouraging bad patterns.

That means a `supports( 'plaintext_boundary' )`-style flag is **not a security control**. Its
value is entirely in being explicit and visible:

- a human reviewing a drop-in can see what it claims,
- Site Health can tell an operator where their credentials are actually protected,
- and a plugin author can find out *before* writing a credential that this site's store will
  refuse the write.

Design for that, and do not dress it up as enforcement.

## Proposed shape

### 1. A distinct interface, not an overloaded store

`WP_Secrets_Store` traffics in **records** — the ciphertext structures `WP_Secrets_Cipher`
produces. A platform that is the encryption boundary traffics in **values**. Making one interface
return either, depending on a flag, is precisely the kind of polymorphism that produces silent
bugs, and this codebase's whole posture is that absent and broken must never share a
representation.

So: a second interface, provisionally `WP_Secrets_Provider`, whose implementations are
authoritative for a value rather than custodians of our ciphertext. The *type* then carries the
security model — a store cannot accidentally become a plaintext sink, because it has no method
that accepts one.

```php
interface WP_Secrets_Provider {
    // WP_Secret on success, null for "not mine — ask the store",
    // WP_Error for "mine, and I cannot answer" (which never falls through).
    public function get( $name, $version, $network = false );

    public function set( $name, $value, $network = false );
    public function delete( $name, $network = false );
    public function list_names( $network = false );
    public function supports( $capability );

    // Human-readable, for Site Health. Never key material.
    public function describe_protection();
}
```

### 2. Routing is declared, never inferred

A provider is consulted first. `null` means "not mine" and falls through to the store; `WP_Error`
means "mine, and broken" and stops there. Mixed estates work — a VIP site can have
dashboard-managed credentials alongside plugin-set ones — without WordPress guessing which is
which.

This mirrors a rule already learned the hard way in this build: the prototype fallback store
originally inferred a mapping by dropping namespaces, and that was replaced with an exact,
declared correspondence for exactly this reason. Routing that a caller cannot predict is routing
that will surprise someone holding a credential.

### 3. Capabilities cover writability *and* where encryption happens

Per Altis, `supports()` needs to answer both. `supports( 'write' )` already exists and already
makes `wp_set_secret()` return `secret_store_read_only`; `wp_secrets_store_supports()` already
lets a settings screen disable its save button before an operator types a credential. That
machinery extends to providers unchanged — this part is built and tested.

What is missing is the encryption-locus declaration that lets an admin screen and Site Health say
*where* a secret is protected, which is the "degrade gracefully" half of the request.

## The decisions I cannot make

**1. Does the published guarantee get amended?** The sentence "Neither is ever handed a plaintext
secret" is published. Adopting the "stronger, never weaker" framing means restating it. That is a
proposal-level edit and a comments-thread conversation, not an implementation detail.
Recommendation: yes, and lead with the reframe rather than the exception — the rule gets *more*
precise, not weaker.

**2. Does `WP_Secret::reveal()` become `string|WP_Error`?**

This is the one that cannot be deferred. `reveal(): string` is published, and a return type cannot
be widened after adoption without breaking callers.

Today `reveal()` genuinely cannot fail: `_wp_secrets_get()` decrypts eagerly and there is exactly
one construction site, so the plaintext is in hand before a `WP_Secret` exists. The case Altis is
protecting is a broker-held or use-only secret — an HSM that will sign with a key but never export
it — where the value never enters PHP at all.

Arguments to change it: it is impossible to add later; a lazy or brokered provider makes failure
real; and `reveal()` is currently the *one* place this API pretends failure cannot happen, which
sits oddly against everything else in it.

Argument against: it costs every caller an `is_wp_error()` check for a case that does not exist
yet, and a secret you cannot reveal is arguably not a `WP_Secret` at all but a handle — which
would be a different type rather than a widened return.

Recommendation: **change it**, on the grounds that the cost is one check at a call site that
already has to check `wp_get_secret()`, and the alternative is permanent. But flag it in the
thread explicitly rather than slipping it in — it is a published-signature change.

**3. Is a provider allowed to persist anything locally?** For the VIP model the answer should be
no: the dashboard is the system of record and a copy in `wp_options` defeats the point. Worth
stating in the contract rather than leaving to each implementer.

## One implementation hazard worth recording now

A provider that calls a platform API on every `wp_get_secret()` will be tempted to cache. It must
not cache into the persistent object cache: this suite already asserts that a `WP_Secret` cannot
round-trip a plaintext through `wp_cache_set()`, and a provider caching the raw value behind
WordPress's back would quietly undo that. Request-scoped memoisation only.
