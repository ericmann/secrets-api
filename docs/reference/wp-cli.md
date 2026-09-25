---
title: "WP-CLI commands"
description: "Every wp secret and wp network-secret subcommand with its synopsis and options, generated from the command docblocks."
---

<!--
  GENERATED FILE. Do not edit by hand.
  Produced by bin/gen-reference.php from docblocks in the PHP source.
  Edit the docblock, then run `php bin/gen-reference.php`. CI fails when this
  file no longer matches the source.
-->

# WP-CLI commands

Registered only when WP-CLI is running. Nothing under `cli/` is proposed for core.

## `wp network-secret`

Manages network-scope secrets. Every subcommand is inherited from WP_CLI_Secret_Command; this class exists only to flip the scope and to refuse outright on a single-site install, which the parent constructor enforces.

**Class:** `WP_CLI_Secret_Network_Command` extends `WP_CLI_Secret_Command`

**Source:** [`cli/class-wp-cli-secret-network-command.php`](../../cli/class-wp-cli-secret-network-command.php)

### `wp network-secret delete`

Deletes a secret.

```
wp network-secret delete <name> [--yes]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--yes]` | Skip the confirmation prompt. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret dropin`

Reports what is protecting this site's secrets.

```
wp network-secret dropin [--verbose]
```

| Option | Description |
|---|---|
| `[--verbose]` | Also show the storage and keyring classes behind the active provider. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret generate-key`

Emits a base64-encoded 32-byte key, suitable for WP_SECRETS_KEY.

Writes to STDOUT only. Never touches wp-config.php -- adding the constant is
the operator's own step.

```
wp network-secret generate-key
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret get`

Gets a secret. Masks the value by default.

Exit code doubles as an existence check: 0 if found, 1 if absent, 2 on error.
There is no separate `exists` subcommand.

```
wp network-secret get <name> [--slot=<slot>] [--reveal] [--field=<field>] [--format=<format>]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--slot=<slot>]` | Which stored version to read. Named --slot rather than --version because WP-CLI consumes `--version` itself before a subcommand ever sees it: passing --version=previous silently yielded the current value, since the flag was swallowed and the synopsis default filled in behind it. Default: `current`. Options: `current`, `previous`. |
| `[--reveal]` | Show the actual value. Without this, it is masked. |
| `[--field=<field>]` | Print a single field (name, fingerprint, value) instead of a table. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret health`

Reports the Secrets API's Site Health status.

```
wp network-secret health [--format=<format>]
```

| Option | Description |
|---|---|
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret import-option`

Imports an existing option's value as a secret. The source option is left untouched, and the imported secret is flagged for rotation.

```
wp network-secret import-option <option> <name>
```

| Option | Description |
|---|---|
| `<option>` | The existing option's name. |
| `<name>` | The secret's namespaced name to store it under. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret list`

Lists secrets by name and metadata. Never shows a value.

```
wp network-secret list [--namespace=<namespace>] [--fields=<fields>] [--field=<field>] [--format=<format>]
```

| Option | Description |
|---|---|
| `[--namespace=<namespace>]` | Only secrets whose name starts with "\<namespace\>/". |
| `[--fields=<fields>]` | Comma-separated list of fields to show. |
| `[--field=<field>]` | Print one field per line, for scripting. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`, `ids`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret migrate-legacy`

Migrates secrets from the earlier prototype's legacy format.

With no flags, migrates every legacy secret into the new format and leaves
every legacy option in place. Writing a new-format secret is never destructive,
and the migration is idempotent: re-running is safe, and an already-migrated
key is reported as skipped.

There is no delete step. Removing a legacy option once the migration is
verified is left to the operator, using wp option delete.

```
wp network-secret migrate-legacy [--dry-run] [--name=<key>] [--map=<mapping>] [--namespace=<namespace>] [--format=<format>]
```

| Option | Description |
|---|---|
| `[--dry-run]` | Write nothing at all; report what would happen. |
| `[--name=<key>]` | Migrate only this legacy key. |
| `[--map=<mapping>]` | Comma-separated old:new pairs for keys whose derived name would not validate, e.g. --map=api_key:myplugin/api-key,other:myplugin/other-key. |
| `[--namespace=<namespace>]` | Namespace prefixed onto a legacy key with no --map entry. Defaults to none, keeping the key exactly as the prototype spelled it, which is the same name a plain wp_get_secret() would upgrade it to on first read. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret retire`

Clears a secret's previous version.

```
wp network-secret retire <name> [--yes]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--yes]` | Skip the confirmation prompt. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret rotate`

Re-wraps the root key under the active keyring.

There are two cases, chosen with --from. `--from=config-previous` (the
default) is a site key change: the root key, currently wrapped under
WP_SECRETS_KEY_PREVIOUS, is re-wrapped under the current WP_SECRETS_KEY.
`--from=config` is moving the root key onto a new keyring: a secrets.php
drop-in has installed one, and the root key, currently wrapped under the
config keyring's WP_SECRETS_KEY, is re-wrapped under that new keyring. No
secret is ever re-encrypted: rotation only changes what the root key is
wrapped under, not the root key's own bytes.

```
wp network-secret rotate [--from=<keyring>] [--yes]
```

| Option | Description |
|---|---|
| `[--from=<keyring>]` | Which keyring currently wraps the root key. Default: `config-previous`. Options: `config-previous`, `config`. |
| `[--yes]` | Skip the confirmation prompt. |

**Examples**

```
    $ wp secret rotate --yes
    $ wp secret rotate --from=config --yes
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp network-secret set`

Sets a secret's value.

```
wp network-secret set <name> [<value>] [--stdin] [--porcelain]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name ('plugin-slug/secret-name'). |
| `[<value>]` | The plaintext value. Passing this as an argument leaks it into shell history and process listings on shared hosts -- use --stdin instead. |
| `[--stdin]` | Read the value from STDIN. The documented way to pass a value. |
| `[--porcelain]` | Output only the new fingerprint, for scripting. |

**Examples**

```
    $ wp secret set myplugin/api-key --stdin <<< "sk_live_..."
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

## `wp secret`

Manages secrets stored via the Secrets API.

Shared by `wp secret` (site scope) and `wp network-secret` (network scope,
WP_CLI_Secret_Network_Command below): every method here is scope-agnostic,
switching between the site and network public functions via $this-\>network,
which the network subclass overrides to true.

**Class:** `WP_CLI_Secret_Command`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret delete`

Deletes a secret.

```
wp secret delete <name> [--yes]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--yes]` | Skip the confirmation prompt. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret dropin`

Reports what is protecting this site's secrets.

```
wp secret dropin [--verbose]
```

| Option | Description |
|---|---|
| `[--verbose]` | Also show the storage and keyring classes behind the active provider. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret generate-key`

Emits a base64-encoded 32-byte key, suitable for WP_SECRETS_KEY.

Writes to STDOUT only. Never touches wp-config.php -- adding the constant is
the operator's own step.

```
wp secret generate-key
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret get`

Gets a secret. Masks the value by default.

Exit code doubles as an existence check: 0 if found, 1 if absent, 2 on error.
There is no separate `exists` subcommand.

```
wp secret get <name> [--slot=<slot>] [--reveal] [--field=<field>] [--format=<format>]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--slot=<slot>]` | Which stored version to read. Named --slot rather than --version because WP-CLI consumes `--version` itself before a subcommand ever sees it: passing --version=previous silently yielded the current value, since the flag was swallowed and the synopsis default filled in behind it. Default: `current`. Options: `current`, `previous`. |
| `[--reveal]` | Show the actual value. Without this, it is masked. |
| `[--field=<field>]` | Print a single field (name, fingerprint, value) instead of a table. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret health`

Reports the Secrets API's Site Health status.

```
wp secret health [--format=<format>]
```

| Option | Description |
|---|---|
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret import-option`

Imports an existing option's value as a secret. The source option is left untouched, and the imported secret is flagged for rotation.

```
wp secret import-option <option> <name>
```

| Option | Description |
|---|---|
| `<option>` | The existing option's name. |
| `<name>` | The secret's namespaced name to store it under. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret list`

Lists secrets by name and metadata. Never shows a value.

```
wp secret list [--namespace=<namespace>] [--fields=<fields>] [--field=<field>] [--format=<format>]
```

| Option | Description |
|---|---|
| `[--namespace=<namespace>]` | Only secrets whose name starts with "\<namespace\>/". |
| `[--fields=<fields>]` | Comma-separated list of fields to show. |
| `[--field=<field>]` | Print one field per line, for scripting. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`, `ids`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret migrate-legacy`

Migrates secrets from the earlier prototype's legacy format.

With no flags, migrates every legacy secret into the new format and leaves
every legacy option in place. Writing a new-format secret is never destructive,
and the migration is idempotent: re-running is safe, and an already-migrated
key is reported as skipped.

There is no delete step. Removing a legacy option once the migration is
verified is left to the operator, using wp option delete.

```
wp secret migrate-legacy [--dry-run] [--name=<key>] [--map=<mapping>] [--namespace=<namespace>] [--format=<format>]
```

| Option | Description |
|---|---|
| `[--dry-run]` | Write nothing at all; report what would happen. |
| `[--name=<key>]` | Migrate only this legacy key. |
| `[--map=<mapping>]` | Comma-separated old:new pairs for keys whose derived name would not validate, e.g. --map=api_key:myplugin/api-key,other:myplugin/other-key. |
| `[--namespace=<namespace>]` | Namespace prefixed onto a legacy key with no --map entry. Defaults to none, keeping the key exactly as the prototype spelled it, which is the same name a plain wp_get_secret() would upgrade it to on first read. |
| `[--format=<format>]` | Render output in a particular format. Default: `table`. Options: `table`, `csv`, `json`. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret retire`

Clears a secret's previous version.

```
wp secret retire <name> [--yes]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name. |
| `[--yes]` | Skip the confirmation prompt. |

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret rotate`

Re-wraps the root key under the active keyring.

There are two cases, chosen with --from. `--from=config-previous` (the
default) is a site key change: the root key, currently wrapped under
WP_SECRETS_KEY_PREVIOUS, is re-wrapped under the current WP_SECRETS_KEY.
`--from=config` is moving the root key onto a new keyring: a secrets.php
drop-in has installed one, and the root key, currently wrapped under the
config keyring's WP_SECRETS_KEY, is re-wrapped under that new keyring. No
secret is ever re-encrypted: rotation only changes what the root key is
wrapped under, not the root key's own bytes.

```
wp secret rotate [--from=<keyring>] [--yes]
```

| Option | Description |
|---|---|
| `[--from=<keyring>]` | Which keyring currently wraps the root key. Default: `config-previous`. Options: `config-previous`, `config`. |
| `[--yes]` | Skip the confirmation prompt. |

**Examples**

```
    $ wp secret rotate --yes
    $ wp secret rotate --from=config --yes
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)

### `wp secret set`

Sets a secret's value.

```
wp secret set <name> [<value>] [--stdin] [--porcelain]
```

| Option | Description |
|---|---|
| `<name>` | The secret's namespaced name ('plugin-slug/secret-name'). |
| `[<value>]` | The plaintext value. Passing this as an argument leaks it into shell history and process listings on shared hosts -- use --stdin instead. |
| `[--stdin]` | Read the value from STDIN. The documented way to pass a value. |
| `[--porcelain]` | Output only the new fingerprint, for scripting. |

**Examples**

```
    $ wp secret set myplugin/api-key --stdin <<< "sk_live_..."
```

**Runs:** `after_wp_load`

**Source:** [`cli/class-wp-cli-secret-command.php`](../../cli/class-wp-cli-secret-command.php)
