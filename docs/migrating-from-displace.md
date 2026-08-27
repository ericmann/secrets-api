# Coexisting with the Displace prototype

This plugin does not provide a compatibility layer for the vibe-coded Displace prototype some
plugins — including the WordPress AI plugin — were built against. There is no `get_secret()` /
`set_secret()` shim, no reimplemented filter, nothing that lets prototype-era code keep running
unchanged. That was tried, and deliberately removed: a shim that lets old code run forever is
exactly the kind of "temporary" surface that never actually goes away, and it does not serve the
one goal that matters here, which is that adopting sites do not break.

What exists instead is narrower and does not need anyone to run anything.

## What happens automatically

When code calls `wp_get_secret( 'your-namespace/some-key' )` and no current-format record exists
yet, the default store falls through to the Displace-format option row for `some-key` (dropping
the namespace — see below), decrypts it, and writes a proper current-format record before
returning. The next read hits that new record directly and never touches the prototype row
again. Nothing about this requires a migration command, a flag, or advance notice — a plugin
switching from calling Displace's functions directly to calling `wp_get_secret()` will simply
start working, one credential at a time, as each one is first read under its new name.

The upgraded secret is flagged `needs_rotation`. It has been sitting in the prototype's format,
and moving it into a new envelope does not undo wherever it has already been — the same reasoning
`wp_import_option_as_secret()` uses for a value pulled out of a plain option.

**The prototype's own rows are never modified or deleted**, by this or by anything else in this
plugin. Both systems keep working, indefinitely, on the same site — enforced, not just intended:
see `test_never_writes_to_a_prototype_owned_option()`.

## The one thing to get right: namespace

A Displace key like `api_key` is unnamespaced. The upgrade path is not fussy about which
namespace claims it — `wp_get_secret( 'anything/api_key' )` inherits the same row. This is
deliberate, not an oversight: Displace's own keyspace was already global, so any plugin could
already read any Displace secret by its bare key. Requiring an exact namespace match here would
just mean the adopting plugin has to guess right, and guessing wrong reintroduces the broken-site
outcome this exists to prevent. Pick whatever namespace makes sense for your plugin and use it
consistently; the first read under that name is what does the upgrading.

## If you want it done in bulk instead of lazily

`wp secret migrate-legacy` copies every (or one named) Displace secret into the current format
up front, without waiting for a first read. It is strictly additive — it writes new-format
records and never touches, and cannot delete, a Displace-owned row. `--dry-run` reports what it
would do without writing anything; `--map=<old>:<new>` gives an explicit new name to a key whose
derived name (`legacy/<key>`, or `--namespace=<ns>/<key>`) would not pass validation, most often
because Displace allowed keys this API's naming rules do not.

If the WordPress AI plugin's own vendored copy of the prototype's code is detected on the site,
the command warns loudly: that plugin is still reading its own copy of the row you just copied,
and will keep doing so until it moves onto this API itself.

There is no delete step, in the command or anywhere else. If you genuinely want a Displace
option gone after confirming it migrated, use `wp option delete` — that is an explicit,
reversible-until-you-do-it action for an operator to take, not something this plugin will do on
your behalf to another plugin's data.
