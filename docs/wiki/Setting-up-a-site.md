Written against v0.5.2.

The add-on ships the code and a few blueprints. The collections and global sets that hold the data belong to the site, because Statamic reads a collection's blueprints from the site's own files only. This page lists what the site creates and which part of the add-on needs it.

It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-redirects#installation)).

## What the site creates

| Create | Handle | Needed for | Blueprint |
| --- | --- | --- | --- |
| A global set | `redirects` | Redirects answered when a page is not found, and the main-site requests | Served by the add-on unless the site has `globals/redirects.yaml` |
| A collection | `redirect_domains` | Rules served on a redirect domain | The site's own `redirect_domain`; workflow fields from [`redirect_domain_workflow.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_domain_workflow.yaml) |
| A collection | `redirect_hosts` | The domain list | [`redirect_hosts_collection.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_hosts_collection.yaml) and [`redirect_host.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/redirect_host.yaml) |
| A collection | `tagged_url_requests` | Tagged-only requests | [`tagged_url_requests_collection.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/tagged_url_requests_collection.yaml) and [`tagged_url_request.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/tagged_url_request.yaml) |
| Two collections | `lookup_lists`, `lookup_options` | The tagged URL cascade | [`lookup_list.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/lookup_list.yaml) and [`lookup_option.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/lookup_option.yaml) |
| A global set | `tagged_url_parameters` | Tagged URLs | Served by the add-on unless the site has its own |
| An asset container | the handle in `qr.container` | QR code files | None |

[Blueprints and collections](Blueprints-and-collections.md) says which blueprint is served and which is copied, and why.

## Steps

1. Publish the config file if a setting needs changing: `php artisan vendor:publish --tag=aether-redirects-config`. Every key is in [Configuration](Configuration.md).
2. Create the `redirects` global set. Rules entered there answer a page that is not found (see [Writing redirect rules](Writing-redirect-rules.md)).
3. To serve redirect domains, create `redirect_hosts` and `redirect_domains` and copy their blueprints, as [Setting up redirect domains](Setting-up-redirect-domains.md) describes.
4. To let staff request tagged URLs, create `tagged_url_requests`, the two lookup collections and `tagged_url_parameters`, as [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md) describes.
5. To make QR code files, create an asset container and set `qr.container` to its handle.
6. Grant the roles their permissions (see [Permissions](Permissions.md)).

## What the add-on does on its own

- It registers the Tools navigation items, the Control Panel routes and the permissions.
- It answers a request for a redirect domain on the global middleware stack, so such a request never reaches Statamic's routes.
- It answers a page that is not found with the stored rule for its path, before the 404 page renders. A rule with status `403`, `410` or `451` renders the site's `errors/<code>` view, which the site provides.

The Control Panel assets are built into the package, so a Composer install is enough.
