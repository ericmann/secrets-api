---
title: "ADR 0008: The Trac ticket replaces thread confirmation"
description: "Additions beyond the proposal are reviewed on the Trac ticket rather than waiting for confirmation on the make/core thread. Before the ticket opens, the extension points get two more implementations and the CLI gets a real end-to-end test."
---

# ADR 0008: The Trac ticket replaces thread confirmation

| | |
|---|---|
| **Number** | 0008 |
| **Date** | 2026-09-24 |
| **Status** | Accepted. Amends [ADR 0002](0002-plugin-before-core-patch.md). |

## Context

[ADR 0002](0002-plugin-before-core-patch.md) says the plugin is done when every addition beyond
the [proposal][proposal] has been "recorded and confirmed on the thread". Those additions are
`wp_retire_secret_version()`, `wp_list_secrets()`, the network functions, the provider and keyring
interfaces, and the rest listed on the [scope](../spec/scope.md) page.

Since 0.1.0 was tagged on 4 September, the proposal, the plugin, and a documentation site
listing each addition with its rationale have been put in front of contributors on the make/core
thread, in Core Slack, and in core dev chat. The response has been consistent support and no
critical feedback. No public issue has been opened, and no one has objected to a name.

That is silence rather than confirmation, and waiting longer will not change it. A make/core post
gets read, not reviewed. Committers review Trac tickets. 7.2 Beta 1 is scheduled for 20 to 22
October, and a patch that opens in mid-October leaves no time for that review to change anything.

## Decision

Opening the Trac ticket replaces thread confirmation as the review step for additions beyond
the proposal.

- The ticket description lists every addition by name, each with a link to the spec page that
  explains it, so review can reject a name specifically rather than approve the patch as a whole.
- The rounds of iteration that were planned for the thread happen as patch revisions on the
  ticket. The plugin follows every revision to its surface, so the plugin and the patch stay the
  same code.
- ADR 0002's first "plugin is done" criterion now reads: the public surface matches the proposal,
  and every addition beyond it is recorded and listed on the Trac ticket.

Before the ticket opens, three pieces of work test the surface in ways silence cannot:

1. **A KMS-backed keyring example.** The documentation recommends it as the first integration a
   host should write, and none exists. It is the first real test of `WP_Secrets_Keyring`, and of
   how an existing site moves onto a new keyring.
2. **A HashiCorp Vault provider example.** Vault numbers versions with integers rather than
   keeping two slots. It is the first backend that does not already share the
   `CURRENT`/`PREVIOUS` shape, which is the design most likely to be wrong in a way nobody has
   pointed out.
3. **A WP-CLI smoke test against a real `wp` binary.** This is the only coverage gap marked as
   needing an answer before the core patch. The WP-CLI surface is part of what 7.2 ships, and
   three dispatch bugs have already got past a green suite.

ADR 0002's third criterion, one real platform provider, is already met by the AWS Secrets Manager
example, which was verified against live AWS on 4 September.

## Consequences

- A name can still change after the ticket opens. A rename then costs a patch revision and a
  plugin release rather than an edit to a proposal, which is still cheap before Beta 1.
- Silence could mean no one read the additions closely. Listing each addition in the ticket
  description, rather than leaving reviewers to diff the patch against the proposal, is the
  mitigation.
- The three examples and the smoke test come before the ticket. If one of them changes an
  interface, the ticket opens with that change already made.
- There is less time for the ticket itself. Work on the examples is limited to what tests the
  interfaces, and anything beyond that waits until after Beta 1.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
