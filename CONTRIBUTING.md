# Contributing

The [proposal][proposal] is the design conversation; this repository is the implementation of it.
Both are open, and disagreement with the design is as welcome here as a patch.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/

## The feedback this most needs

In rough order of how much it would change the code:

1. **Implement `WP_Secrets_Provider` against a real platform and tell us what broke.** So far the
   interface is shaped by hosts describing what they need rather than by anyone having built
   against it. The first real implementation will turn up something those descriptions missed, and
   that's worth more than any amount of review. Start with
   [`docs/extending.md`](docs/extending.md) and [`examples/`](examples/). Note that a KMS is
   usually a `WP_Secrets_Keyring` — three methods — rather than a provider.
2. **Run the conformance suite against your implementation** (`WP_Secrets_Provider_Conformance`)
   and report anything it fails to catch, or anything it demands that a reasonable backend cannot
   provide.
3. **Answer one of the open questions.** [`docs/open-questions.md`](docs/open-questions.md) is
   deliberately short and holds only what is still open — 🟡 entries need an answer before the
   core patch.
4. **Adopt the API in a plugin** and report where it got awkward. The surface is settled, which
   isn't the same as it being pleasant to use.

Security issues go through [`SECURITY.md`](SECURITY.md), never a public issue.

## Working on the code

`make ci` is the single source of truth — lint, PHP 7.4 compatibility, phpstan, and both PHPUnit
suites. A green local run means a green pipeline. `bin/ci-local.sh` does the same inside wp-env
if you would rather not set up a test database. See the README for both.

A few conventions that matter more than style:

- **`src/` is written to be copied verbatim into `wordpress-develop`.** Same paths, core coding
  standards, the `default` text domain, `@since 7.2.0`, PHP 7.4 syntax, and no reference to
  anything that only exists in this plugin. Several architectural tests read the source and
  enforce exactly this.
- **Those architectural tests are never weakened to make a build green.** If one fails, either the
  change is wrong or the design needs a decision. Either way it's worth talking about in the pull
  request.
- **Tests land in the same commit as the code they cover**, and commits stay small and logically
  scoped.
- **Absent, present, and broken are three states, and they never collapse into two.** Most of the
  design exists to keep them apart. A patch that makes an error look like an absence will be sent
  back even if the whole suite passes.

## Pull requests

Say what the change is meant to guarantee, not just what it does. If it alters anything the
proposal published, call that out in the description; those need a conversation in the comments
thread as well as here.
