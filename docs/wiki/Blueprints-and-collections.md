Written against v0.5.2.

The add-on serves some blueprints itself and leaves the rest to the site. The split follows what Statamic allows: a global set's blueprint has a fallback hook, which the add-on uses, and a collection's entry blueprints are read from the site's files only, so the add-on ships those as reference files to copy.

## Served by the add-on

| Blueprint | Used when |
| --- | --- |
| `globals/redirects` | The site has no `globals/redirects.yaml` of its own. A site that keeps or saves its own copy, as the Control Panel's blueprint editor does, overrides this one. |
| `globals/tagged_url_parameters` | The site has no `globals/tagged_url_parameters.yaml` of its own. |

The `redirects` blueprint's `target_url` field reads the site's `uamsweb_common` fieldset, as the redirect domains blueprint does.

## Copied by the site

These files are in [`docs/blueprints/`](https://github.com/UAMS-Web/aether-redirects/tree/main/docs/blueprints) of the repository. Copy each into the site's blueprint directory.

| File | Becomes | Needed for |
| --- | --- | --- |
| `redirect_hosts_collection.yaml` | The settings of the `redirect_hosts` collection | The domain list |
| `redirect_host.yaml` | `collections/redirect_hosts/redirect_host` | The domain list |
| `redirect_domain_host_field.yaml` | The `host` field of the site's `redirect_domain` blueprint | A rule that names a domain from the list |
| `redirect_domain_workflow.yaml` | Fields added to the site's `redirect_domain` blueprint | Showing the approval fields on a rule |
| `tagged_url_requests_collection.yaml` | The settings of the `tagged_url_requests` collection | Tagged-only requests |
| `tagged_url_request.yaml` | `collections/tagged_url_requests/tagged_url_request` | Tagged-only requests |
| `lookup_list.yaml` | `collections/lookup_lists/lookup_list` | The tagged URL cascade |
| `lookup_option.yaml` | `collections/lookup_options/lookup_option` | The tagged URL cascade |

The `redirect_domains` collection and its `redirect_domain` blueprint belong to the site. The request workflow writes its fields to an entry whether or not the blueprint shows them.

## Reference

- [Setting up a site](Setting-up-a-site.md)
- [Setting up redirect domains](Setting-up-redirect-domains.md)
- [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md)
