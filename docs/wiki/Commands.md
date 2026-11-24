Written against v0.5.2.

The add-on registers three Artisan commands.

## `redirects:edge-manifest`

Writes the edge redirect manifest for a site, or for redirect domains. Steps and the manifest's contents are in [Publishing the edge manifest](Publishing-the-edge-manifest.md).

```bash
php artisan redirects:edge-manifest {site?} {--global=redirects} {--host=} {--hosts} {--collection=redirect_domains} {--output=}
```

| Argument or option | Effect |
| --- | --- |
| `site` | The handle of the site to write the manifest for. |
| `--global` | The handle of the redirects global set. Default `redirects`. |
| `--host` | Write the manifest for this domain, from the redirect domains collection. |
| `--hosts` | Write one manifest per domain, into the directory `--output` names. |
| `--collection` | The handle of the redirect domains collection. Default `redirect_domains`. |
| `--output` | Write to this path instead of standard output. With `--hosts`, the directory to write into, one `<host>.json` per domain. |

Exactly one of `site`, `--host` and `--hosts` is required. The exit status is `0` on success and `1` on a refusal.

## `redirects:migrate-domain-hosts`

Moves each `redirect_domains` entry from a host typed as text to a reference to a record of the domain list.

```bash
php artisan redirects:migrate-domain-hosts [--dry-run]
```

It creates one published record for each distinct valid host, unless a published record already names it, and rewrites each entry to the record's id. A rule serves the same redirect before and after. An entry that already holds a record id is skipped, so running it again changes nothing. An entry whose host is not a valid domain is reported with `Entry <id> has a host that is not a valid domain, so it was left as it is.` and left alone.

`--dry-run` reports what would change without creating records or saving entries. The command ends with a summary such as `3 domain record(s) created, 5 entries rewritten, 0 skipped.`

## `redirects:report-case-duplicates`

Reports the redirect domain rules whose sources differ only in case.

```bash
php artisan redirects:report-case-duplicates
```

A request on a redirect domain matches a source without regard to case, so two rules whose sources differ only in case claim the same request, and the first in order wins. Run the command before relying on case-insensitive back-halves, and resolve each pair it names. It reads only and changes nothing.

Each pair is printed as `<host>: <spelling>, <spelling>`. A disabled rule, a rejected request and an unpublished entry are not counted, and neither is a pattern source (a regular expression or a source ending in `*`). Pending rules and rules outside their dates are counted. It exits `0` with `No two sources differ only in case.` when there are none, and `1` with a count when there are, so a script can stop on it.

## Reference

- [Setting up redirect domains](Setting-up-redirect-domains.md)
- [Redirect rules](Redirect-rules.md)
