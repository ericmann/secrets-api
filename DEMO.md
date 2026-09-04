# Demo script

A ~15 minute walkthrough. **Part 1** uses the provider WordPress ships — libsodium, ciphertext in
the options tables. **Part 2** drops in AWS Secrets Manager and runs the *same commands* against
it, which is the point: the API does not change when the platform does.

Every command below has been run against a live environment. Expected output is shown so you can
tell a demo hiccup from a real failure.

---

## Setup (do this before anyone is watching)

```sh
npx @wordpress/env start
```

Get a shell inside the container and stay there — prefixing every command with
`npx @wordpress/env run cli` costs ~2s of dead air each time and clutters the screen:

```sh
docker exec -it $(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests) bash
```

You land in `/var/www/html`. Everything below is typed at that prompt.

Start from a clean slate:

```sh
for n in $(wp secret list --field=name 2>/dev/null); do wp secret delete "$n" --yes; done
rm -f /var/www/html/wp-content/secrets.php     # ensure Part 1 uses the default provider
```

---

## Part 0 — the problem (30 seconds, no terminal)

> Today, a plugin that needs an API key puts it in `wp_options` in plaintext. Any other plugin can
> read it, it's in every database backup, it shows up on `options.php`, and it's in your staging
> copy. There is no first-party way to do better — so everyone invents their own, badly.

---

## Part 1 — the default provider

### 1.1 Store a credential

```sh
echo -n 'sk_live_51H8xK2abcdef' | wp secret set acme/stripe-key --stdin
```
```
Success: Set secret "acme/stripe-key".
```

Now show what happens if you do it the lazy way:

```sh
wp secret set acme/other-key 'sk_live_oops'
```
```
Warning: Passing a secret value as a command argument leaks it into shell history. Use --stdin instead.
Success: Set secret "acme/other-key".
```

> It still works — we don't block you — but the API makes the safe path the documented one and
> tells you when you've taken the other one.

### 1.2 Reads are masked by default

```sh
wp secret get acme/stripe-key
```
```
+-----------------+----------------------------------+--------------+
| name            | fingerprint                      | value        |
+-----------------+----------------------------------+--------------+
| acme/stripe-key | 2e3196fc27748e9aad638b5c7af6b00f | sk_l******** |
+-----------------+----------------------------------+--------------+
```

> Masked unless you ask otherwise. The mask is a **fixed width** — eight asterisks whatever the
> real length — so the mask itself doesn't leak how long the secret is. The fingerprint is a
> keyed, per-site digest: you can compare two secrets without either of them being revealed.

### 1.3 Revealing is deliberate and loud

```sh
wp secret get acme/stripe-key --reveal --field=value
```
```
Warning: Revealing a secret value. Make sure this output does not end up somewhere logged.
sk_live_51H8xK2abcdef
```

> Nothing reveals by accident. Not `print_r`, not `var_dump`, not `json_encode`, not writing the
> object to a log — all of those produce `[secret:acme/stripe-key]`. Serializing one throws.

### 1.4 Rotation without downtime — *the headline*

```sh
echo -n 'sk_live_ROTATED_9f3a' | wp secret set acme/stripe-key --stdin
wp secret get acme/stripe-key --reveal --field=value
wp secret get acme/stripe-key --slot=previous --reveal --field=value
```
```
sk_live_ROTATED_9f3a      <- current
sk_live_51H8xK2abcdef     <- previous, still readable
```

> Both versions live at once. So you rotate the credential at the provider, deploy, let in-flight
> requests drain against the old one, and retire it when you're ready. That's the whole reason
> this exists rather than "encrypt an option."

Then retire it explicitly:

```sh
wp secret retire acme/stripe-key --yes
wp secret get acme/stripe-key --slot=previous ; echo "exit=$?"
```
```
Success: Retired the previous version of "acme/stripe-key".
exit=1
```

> No timers, no cron. Retiring is an operator action.

### 1.5 Absent is not broken — *the design argument*

```sh
wp secret get acme/never-existed ; echo "exit=$?"
```
```
exit=1
```

Then show the other case (optional — needs the corrupt step below):

```sh
echo -n 'v' | wp secret set acme/corrupt --stdin
wp eval '$r = get_option( "_wp_secret_acme/corrupt" ); $r["current"]["ct"] = base64_encode( "broken" ); update_option( "_wp_secret_acme/corrupt", $r, false );'
wp secret get acme/corrupt ; echo "exit=$?"
```
```
Error: The secret value could not be decrypted.
exit=2
```

> **Exit 1 means "doesn't exist." Exit 2 means "exists and I couldn't give it to you."** Those are
> different, and the API never collapses them — `wp_get_secret()` returns a `WP_Secret`, `null`, or
> a `WP_Error`, never a bare `false`. A network blip or a wrong key must never look like a deleted
> credential, because that's how someone "helpfully" regenerates a credential they still had.

Clean up: `wp secret delete acme/corrupt --yes`

### 1.6 Listing never shows values

```sh
wp secret list
```

> Name, fingerprint, created, whether it has a previous version, whether it needs rotation. Never
> a value — there is no flag that makes this print one.

### 1.7 Importing what's already there

```sh
wp option update legacy_api_key 'sk_live_from_an_option'
wp secret import-option legacy_api_key acme/imported
```
```
Success: Imported option "legacy_api_key" as secret "acme/imported". Flagged for rotation.
```

> Note *"flagged for rotation."* That credential sat in a plain option, so it's in every backup
> taken until now. Re-encrypting it doesn't undo that, and the API says so rather than pretending
> the problem is solved. The source option is left alone — this reads it, it doesn't move it.

### 1.8 Site Health

```sh
wp secret health
```
```
check                                   status
Secrets API is using a fallback key     recommended
All secrets can be decrypted            good
Some secrets are pending rotation       recommended
```

> The fallback-key line is the useful one: this site derives its root key from `wp-config.php`
> salts, because that's the only thing guaranteed to exist everywhere. A host would point it at a
> KMS instead — which is Part 2.

### 1.9 What's protecting these secrets

```sh
wp secret dropin
```
```
Drop-in active: no
Provider: WP_Secrets_Libsodium_Provider
Protected by: WordPress (libsodium), key source: derived from LOGGED_IN_KEY and LOGGED_IN_SALT
Encryption boundary: WordPress
Accepts writes: yes
```

> Every line answers a question a hosting platform asked us. Note **`Encryption boundary:
> WordPress`** — remember it, because it's about to change.

---

## Part 2 — swap in AWS Secrets Manager

### 2.1 Credentials

In `.wp-env.override.json` at the repo root (git-ignored — real keys can't be committed):

```json
{
	"config": {
		"WP_SECRETS_AWS_REGION": "us-west-2",
		"WP_SECRETS_AWS_KEY": "AKIA...",
		"WP_SECRETS_AWS_SECRET": "..."
	}
}
```

Then, **from the host**: `npx @wordpress/env start` to rewrite `wp-config.php`.

### 2.2 Install the drop-in

From the host:

```sh
CID=$(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests)
docker cp examples/aws-secrets-manager/secrets.php "$CID":/var/www/html/wp-content/secrets.php
```

### 2.3 The boundary moves

```sh
wp secret dropin
```
```
Drop-in active: yes
Provider: AWS_Secrets_Manager_Provider
Protected by: AWS Secrets Manager (us-west-2)
Encryption boundary: the provider (outside WordPress)
Accepts writes: yes
```

> One file in `wp-content`, no plugin change, no code change in anything that *uses* secrets.
> WordPress is now a consumer of credentials it doesn't protect.

Worth showing the file itself — it's ~300 lines, **no Composer, no AWS SDK**, one SigV4 signature
and `wp_remote_post()`.

### 2.4 The same commands, against AWS

```sh
echo -n 'sk_live_FIRST' | wp secret set eric/demo --stdin
wp secret get eric/demo
echo -n 'sk_live_SECOND' | wp secret set eric/demo --stdin
wp secret get eric/demo --reveal --field=value
wp secret get eric/demo --slot=previous --reveal --field=value
```
```
sk_live_SECOND     <- current
sk_live_FIRST      <- previous
```

> Identical commands. Identical behaviour. The secret is in AWS.

If you can, have the AWS console open on Secrets Manager — `wp/eric/demo` will be sitting there
with two versions.

### 2.5 The bit worth pausing on

> Secrets Manager tracks versions with **staging labels**, and two of them are `AWSCURRENT` and
> `AWSPREVIOUS`. That is exactly `WP_Secret_Version::CURRENT` and `::PREVIOUS`.
>
> So `--slot=previous` is a `GetSecretValue` with `VersionStage: AWSPREVIOUS`, and writing rotates
> the labels server-side. **The two-slot model needed no emulation** — it's the shape the problem
> already has. That's reasonable evidence the rotation design isn't a WordPress-ism we invented.

### 2.6 Take it back out

```sh
rm -f /var/www/html/wp-content/secrets.php
wp secret dropin
```

> Back to the built-in provider, instantly. That's also your escape hatch if AWS misbehaves
> mid-demo.

---

## Closing

> 453 tests, CI green on PHP 7.4 / 8.0 / 8.3 against WordPress latest *and* trunk, plus multisite.
> Everything under `src/` is written to be copied verbatim into `wordpress-develop` — same paths,
> same coding standard, same `default` text domain.

**Say these before someone asks:**

1. **No admin screen.** CLI and code only; the proposal defers UI to 7.3.
2. **No per-plugin isolation.** Namespacing is organisational — it groups secrets by owner. Any
   plugin that can run PHP can read any secret. Masking is hygiene, not a privilege boundary.
3. **The AWS provider is a demonstration, not a product.** Static credentials, no pagination past
   100 secrets, empty fingerprints in `list`. It exists to prove the seam works.

**The ask for the room:** the provider interface is shaped by three hosts *describing* what they
need, not three working implementations. Build against it and tell us what broke.

---

## Troubleshooting

**Every AWS command fails with `cURL error 6: Could not resolve host: secretsmanager..amazonaws.com`**
— empty region. Check `.wp-env.override.json` and re-run `npx @wordpress/env start` from the host.

**`wp secret dropin` still says `WP_Secrets_Libsodium_Provider` after installing the drop-in** —
one of the three AWS constants is blank. That's the guard doing its job: rather than install a
provider that fails every call, it falls back. Fix the config, restart, re-copy.

**The test suite goes red after a demo** — wp-env's dev and tests environments share `wp-content`,
so an installed drop-in sits in front of PHPUnit too. Remove it from *all* containers:

```sh
for c in $(docker ps --format '{{.Names}}' | grep -E 'cli-1|wordpress-1'); do
  docker exec "$c" rm -f /var/www/html/wp-content/secrets.php
done
```

**A command "doesn't exist"** — check `wp help secret`, which prints the subcommands WP-CLI
actually registered. WP-CLI derives them from method names, so an underscore in PHP is an
underscore on the command line unless the docblock says `@subcommand`. All twelve are hyphenated
as of this writing; that was not always true.

**A flag is rejected as "unknown"** — WP-CLI will not register a parameter whose docblock block
lacks a `: description` line. `wp help secret <subcommand>` shows the synopsis it built; if the
flag is missing there, it is missing everywhere.

**`--version` doesn't work for picking a slot** — it's `--slot`. WP-CLI consumes `--version`
itself before a subcommand sees it, which silently returned the *current* value.

---

## Reset between runs

```sh
for n in $(wp secret list --field=name 2>/dev/null); do wp secret delete "$n" --yes; done
wp option delete legacy_api_key
rm -f /var/www/html/wp-content/secrets.php
```

Note that with the AWS drop-in active, `wp secret delete` deletes from **AWS**, immediately and
without recovery.
