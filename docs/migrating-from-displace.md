# Coexisting with the Displace prototype

This plugin does not provide a compatibility layer for the Displace prototype that some plugins
were built against. There is no `get_secret()` / `set_secret()` shim, no reimplemented filter,
nothing that lets prototype-era code keep running unchanged. An earlier revision had one, and it
was removed on purpose. A shim that lets old code run indefinitely is the kind of "temporary"
surface that never goes away, and it doesn't serve the goal here, which is that adopting sites
don't break.

What's there instead is narrower, and nobody has to run anything for it to work.

## What happens automatically

When code calls `wp_get_secret( 'your-namespace/some-key' )` and no current-format record exists
yet, the default store falls through to the Displace-format option row for `some-key` (dropping
the namespace — see below), decrypts it, and writes a proper current-format record before
returning. The next read hits that new record directly and never touches the prototype row
again. Nothing about this requires a migration command, a flag, or advance notice — a plugin
switching from calling Displace's functions directly to calling `wp_get_secret()` will simply
start working, one credential at a time, as each one is first read under its new name.

The upgraded secret is flagged `needs_rotation`. It's been sitting in the prototype's format, and
re-encrypting it doesn't undo wherever it has already been. `wp_import_option_as_secret()` flags
values pulled out of plain options for the same reason.

**The prototype's own rows are never modified or deleted**, by this or by anything else in this
plugin. Both systems keep working, indefinitely, on the same site — enforced, not just intended:
see `test_never_writes_to_a_prototype_owned_option()`.

## Keep calling it what you always called it

Displace keys are unnamespaced (`api_key`). The Secrets API prefers namespaced names
(`plugin-slug/secret-name`), but it **accepts the unnamespaced form** precisely so that porting
does not have to be all-or-nothing: `wp_get_secret( 'api_key' )` is a legal call that returns the
value Displace's `get_secret( 'api_key' )` returned. It reports through `_doing_it_wrong()`, so
the notice tells you which call sites still need a namespace, and nothing breaks while you work
through them.

The correspondence is exact, in both directions:

| Call | Reads Displace's `_secret_api_key`? |
|---|---|
| `wp_get_secret( 'api_key' )` | Yes — same name, same secret |
| `wp_get_secret( 'myplugin/api_key' )` | No — a namespaced name never maps to a bare prototype row |

Nothing gets rewritten or guessed on your behalf. An earlier revision dropped the namespace
instead, so `wp_get_secret( 'anything/api_key' )` would inherit Displace's `api_key`. That went
away, because it let any namespace claim any Displace row and gave callers no way to tell which of
their names were quietly wired to prototype data.

### If your prototype keys were already namespaced

Displace's own documentation used namespaced keys (`my-plugin/api_key`), and a site that followed
that convention has option rows like `_secret_my-plugin/api_key`.

**Read-time upgrade does not pick those up.** The fallback only maps unnamespaced names, so
`wp_get_secret( 'my-plugin/api_key' )` returns `null` rather than reaching into a prototype row —
the same answer it gives for a secret that was never set.

Use `wp secret migrate-legacy` for these. It lists every `_secret_*` row regardless of shape,
keeps each key exactly as spelled, and writes a current-format record under the same name. After
that the name resolves normally. `--dry-run` shows you what it would do first.

**So: adopt the API first, namespace second.** Change `get_secret( 'api_key' )` to
`wp_get_secret( 'api_key' )` and it works immediately. When you are ready to take a namespace,
`wp secret migrate-legacy --map=api_key:myplugin/api-key` moves it, or set the new value
explicitly under the name you want.

## If you want it done in bulk instead of lazily

`wp secret migrate-legacy` copies every (or one named) Displace secret into the current format
up front, without waiting for a first read. By default it keeps each key exactly as Displace
spelled it — deliberately, since that is the same name a plain `wp_get_secret()` would upgrade it
to, and a different default would leave the same secret in two current-format records that diverge
the moment either is rotated. It is strictly additive: it writes new-format records and never
touches, and cannot delete, a Displace-owned row. `--dry-run` reports what it would do without
writing anything. `--namespace=<ns>` and `--map=<old>:<new>` both put the migrated secret somewhere
specific, which is also how you resolve a key whose name Displace allowed but this API's rules do
not (anything with uppercase, spaces, or dots).

If the WordPress AI plugin's own vendored copy of the prototype's code is detected on the site,
the command warns loudly: that plugin is still reading its own copy of the row you just copied,
and will keep doing so until it moves onto this API itself.

There's no delete step, in the command or anywhere else. If you want a Displace option gone after
confirming it migrated, run `wp option delete` yourself. Deleting another plugin's data isn't
something this one will do on your behalf.
