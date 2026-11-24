Written against v0.5.2.

The marketing-redirect workflow adds two Tools navigation items and a set of Control Panel routes. Every route sits under `<cp>/redirects/marketing` and is named `marketing-redirects.<name>`, so `cp_route('marketing-redirects.review')` builds a link.

## Navigation

| Item | Route | Shown to |
| --- | --- | --- |
| Marketing Redirects | `marketing-redirects.create` | A user who holds any request permission or *Create short links* |
| Review Redirects, with the number waiting when it is above zero | `marketing-redirects.review` | A user who holds *Approve marketing redirects* |

The number adds the pending rules of the `redirects` global set, of `redirect_domains`, and of `tagged_url_requests`.

## Routes

| Method and path | Name | What it does |
| --- | --- | --- |
| `GET /` | `create` | The request screen. |
| `POST /` | `store` | Validates and saves a request as pending, or a short link to a trusted host as approved. |
| `POST short-code` | `short-code` | Draws a short-link code for the screen. |
| `GET review` | `review` | The review queue. |
| `POST {redirect}/approve` | `approve` | Approves a pending request. |
| `POST {redirect}/reject` | `reject` | Rejects a pending request; a `reason` is required. |
| `GET {redirect}/qr` | `qr` | The QR code screen of a vanity redirect. |
| `POST {redirect}/qr` | `qr.generate` | Makes the QR code files, or makes them again in place. |
| `GET {redirect}/qr/{format}` | `qr.download` | Downloads one file: `svg`, `png`, `pdf` or `eps`. |
| `GET tagged-url/cascade` | `tagged-url.cascade` | The options each cascade step offers, as JSON. |

Each route checks the permission in [Permissions](Permissions.md) and answers `403` without it. `{redirect}` is an entry id. The QR routes take the id of the vanity redirect, an entry of `redirect_domains` that has a QR redirect; any other id answers `404`.

## The cascade endpoint

```text
GET <cp>/redirects/marketing/tagged-url/cascade?channel=<option id>&steps[source]=<option id>
```

The query takes `channel`, `steps[<step>]` and `lists[<list id>]`, each an option id. The answer holds `channel` (the channel options), `steps` (each step an enabled parameter reads, with its options), `lists` (each list-sourced custom parameter, its list and options) and `fields` (the request field handles the parameters read). An option is its `id` and `label`. A step no enabled parameter reads is left out, an id that no longer exists is ignored, and a site with no lookup data gets an empty answer with a `200`. The request form calls it to narrow each list as choices are made.

## Reference

- [Permissions](Permissions.md)
- [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md)
