Written against v0.5.2.

The edge manifest is the part of the redirect rules an edge network can serve without PHP, as JSON. A deploy writes it, and a renderer for the chosen edge provider reads it. The add-on emits the manifest and renders nothing for a particular provider. This guide writes one. It assumes the site has rules to publish (see [Writing redirect rules](Writing-redirect-rules.md)).

## For a site

```bash
php artisan redirects:edge-manifest default --output=storage/app/redirects-default.json
```

The argument is a site handle. Without `--output` the JSON goes to standard output. `--global=<handle>` reads another global set than `redirects`. The command refuses an unknown site (`Unknown site [<handle>].`) and an unknown global set.

## For one redirect domain, or all of them

```bash
php artisan redirects:edge-manifest --host=uams.info
php artisan redirects:edge-manifest --hosts --output=storage/app/redirect-manifests
```

`--host` writes one domain's rules, from the `redirect_domains` collection (or `--collection=<handle>`), to standard output or `--output=<file>`. `--hosts` writes one `<host>.json` per domain into the directory `--output` names, creating it if needed. A domain's manifest holds that domain's rules alone.

Pass exactly one of a site handle, `--host` or `--hosts`, or the command stops with `Pass a site handle, --host=<domain>, or --hosts.`

The command warns about two kinds of trouble and carries on: `Skipped entry [<id>]: its domain is not valid.`, and `[<host>]: <n> entry target(s) could not be resolved to a URL, so the manifest sends them to the origin.`

## What the manifest holds

- `version`, the manifest format version, `1`.
- `site` or `host`, which one the manifest is for.
- `semantics`, the rules for reading it, written out so a renderer needs nothing else.
- `reserved_prefixes`, the paths the application routes itself (empty for a domain).
- `exact` and `prefix`, the entries to try, in order.
- `prefix_complete`, false when later pattern rules were held back.
- `withheld`, how many rules were left out, by reason.

The output depends on the stored content and nothing else, with no timestamp, so two runs against the same content write byte-identical files.

## What the edge serves and what it hands on

The edge serves a rule only when the engine would serve it the same way. A request the manifest does not answer goes to the origin, which serves it as it does today. These stay with the origin:

- regular-expression rules, and every pattern rule after the first one the edge cannot evaluate, because the engine serves the first match;
- rules with a validity window;
- a status other than `301`, `302`, `303`, `307` or `308`, so the site's error pages render;
- an entry target, which resolves on every request and follows the entry when it moves (on a domain, the entry's URL is read when the manifest is written).

Disabled and unapproved rules are left out. Both can change in the Control Panel without a deploy, so the manifest is a snapshot: run the command again as part of each deploy.

Requests to a redirect domain that reach the origin are answered by the application (see [Setting up redirect domains](Setting-up-redirect-domains.md)).

## Reference

- [Commands](Commands.md)
- [Redirect rules](Redirect-rules.md)
