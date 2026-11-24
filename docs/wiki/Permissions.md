Written against v0.5.2.

The add-on registers seven permissions in a **Redirects** group, which a role is granted in the Control Panel under *Users*, then *Permissions*. Four of them are levels of one ladder: a wider level includes every narrower one.

| Permission | Handle | What it allows |
| --- | --- | --- |
| Create marketing redirects | `create marketing redirects` | The widest request level: a rule for the main site's 404 path, or for any domain in the domain list, with a typed or generated back-half. Includes the three below. |
| Request custom vanity URLs | `request custom vanity urls` | A vanity URL on a vanity domain, with a back-half the requester types or one the system generates, and a tagged URL. Not the main site and not `go.uams.edu`. Includes the two below. |
| Request vanity URLs | `request vanity urls` | A vanity URL on a vanity domain with a generated back-half only (the source is left blank), and a tagged URL. Includes the one below. |
| Request tagged URLs | `request tagged urls` | A tagged URL alone, with no vanity URL. |
| Approve marketing redirects | `approve marketing redirects` | The review queue, with approve and reject. Never for a request the user made. Also opens a QR code screen. |
| Create short links | `create short links` | A short link on `go.uams.edu`. |
| Manage redirect domains | `manage redirect domains` | Creating or changing a record of the domain list. |

## What each permission shows

- **Tools, then Marketing Redirects** shows to a user who holds any request permission or *Create short links*.
- **Tools, then Review Redirects** shows to a user who holds *Approve marketing redirects*, with the number of requests waiting.
- The QR code screen opens for a user who holds *Request vanity URLs*, *Request custom vanity URLs*, *Create marketing redirects* or *Approve marketing redirects*.
- The tagged URL cascade endpoint answers a user who may request a tagged URL.

## What Statamic's own permissions still decide

The add-on adds no permission for the lookup lists, the lookup options or the parameters. Whoever may edit those collection entries and that global set may change them.

## Reference

- [Requesting a vanity URL](Requesting-a-vanity-URL.md)
- [Reviewing redirect requests](Reviewing-redirect-requests.md)
- [Setting up redirect domains](Setting-up-redirect-domains.md)
