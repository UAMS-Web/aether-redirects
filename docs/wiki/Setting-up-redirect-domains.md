Written against v0.5.2.

A redirect domain is a host that is not a Statamic site, such as `uams.info`, whose requests this application answers. Two collections hold the data: the domain list, `redirect_hosts`, with one record per trusted domain, and `redirect_domains`, with the rules. This guide sets both up. It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-redirects#installation)).

## Why there is a list

Creating a domain record needs its own permission, so publishing a redirect rule cannot add a trusted address. A rule can only name a domain that has a published record. The same records decide which hosts the add-on answers, and which destinations a short link may reach without review.

## 1. Create the domain list

1. In the Control Panel, create the collection `redirect_hosts`. Copy [`redirect_hosts_collection.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_hosts_collection.yaml) for its settings.
2. Copy [`redirect_host.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_host.yaml) into the site as `collections/redirect_hosts/redirect_host`.
3. Add one record for each domain: the domain without `https://` or a path, such as `agec.org`. It is saved lowercased and without a trailing dot. The vanity domains `uams.info`, `uams.health` and `uamsbaptist.health`, and `go.uams.edu` for short links, each need a record to be offered on the request screen.

Saving a record refuses a value that is not a valid domain (`Enter a valid domain, such as example.org.`) and a domain another record already names (`<host> is already in the domain list.`). A signed-in user needs *Manage redirect domains*, or the save is refused with `You may not manage redirect domains.` A super user holds it. A save with no signed-in user, such as an import or the migration, is not blocked.

## 2. Create the rules collection

1. Create the collection `redirect_domains` and its `redirect_domain` blueprint. The site owns both, because Statamic cannot load a collection blueprint from an add-on.
2. Copy the workflow fields from [`redirect_domain_workflow.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_domain_workflow.yaml) into that blueprint, so the entry editor shows the approval status, the dates, the requester and the rest. The request screen stores them whether or not the blueprint shows them.
3. Replace the blueprint's `host` field with [`redirect_domain_host_field.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_domain_host_field.yaml), an entries field limited to `redirect_hosts`, and set the collection's `title_format` to `{{ host:host }}...`.
4. Save a rule. A rule is refused unless its host is a published domain record.

## 3. Move existing rules to records

Rules saved before the list existed hold their host as text. They keep serving while a published record names that domain. To convert them, run once:

```bash
php artisan redirects:migrate-domain-hosts --dry-run
php artisan redirects:migrate-domain-hosts
```

See [Commands](Commands.md) for what it reports.

## What a request for the domain does

The add-on answers a request on the global middleware stack when the host is not the host of any Statamic site and the host has a published domain record and at least one published entry that resolves to it. The rules come from that host's entries alone, selected by the same engine as the main site (see [Redirect rules](Redirect-rules.md)). A request no rule answers is a bare `404`, and the request never reaches Statamic's routes or renders site content. Any other host passes through untouched.

An entry is one of two kinds:

- a source entry, which is a rule like one row of the `redirects` global set;
- a whole-domain entry (`whole_domain` on), which becomes a single `/*` rule with a fixed target, tried after every source rule on the host. Everything on the host goes to one URL with nothing appended.

An entry target resolves to the entry's absolute URL when the rules are read, so a domain follows its destination when that entry moves.

## Reference

- [Blueprints and collections](Blueprints-and-collections.md)
- [Commands](Commands.md)
- [Permissions](Permissions.md)
