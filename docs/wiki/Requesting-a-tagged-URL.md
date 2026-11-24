Written against v0.5.2.

A tagged URL is a destination plus the campaign parameters a report reads, such as `utm_source` and `utm_campaign`. A request for one can stand alone or ride on a vanity request. This guide covers both. It assumes the site is set up for tagged URLs (see [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md)) and that the request screen opens (see [Your first vanity URL](Your-first-vanity-URL.md)).

## A tagged URL alone

1. Open **Tools**, then **Marketing Redirects**. A user who may request vanity URLs or make short links chooses **Tagged URL only**. For a user who holds only *Request tagged URLs*, every request is a tagged URL and the choice is not shown. Neither works without the `tagged_url_requests` collection.
2. Enter the destination, the URL the parameters are added to.
3. Choose the channel. Each later step (`source`, `source_platform`, `medium`, `creative_format`, `specifications`, `placement`, `term` and `content`) offers the list the earlier choices point to, narrowing as choices are made. A step may take typed text beside a list choice.
4. Fill in the request fields the site's parameters read, such as `utm_campaign`, `utm_id` and `utm_marketing_tactic`.
5. Optionally turn on **Leave the utm_ parameters off the URL**, which leaves every `utm_*` parameter out and keeps the others.
6. Answer the Creative Services question, and add the requester and notes.
7. Submit. The screen answers `Tagged URL request submitted for review. The tagged URL is issued once an approver approves it.`

The request is saved pending as an entry of `tagged_url_requests`. It is never a redirect and nothing serves from the collection.

When an approver approves it, the URL is composed from the `tagged_url_parameters` global and stored on the entry as `tagged_url`: `Tagged URL approved and issued: <url>`. A later change to a list or a parameter leaves an issued URL as it was.

A tagged-only request refuses the vanity fields (`host`, `source`, `response`, `valid_from` and `valid_until`).

## A tagged URL on a vanity request

A vanity request on a site with lookup data shows the same choices. The choices are kept with the request until approval. Approval composes the URL from the destination as typed and the choices, stores it as the redirect's destination, and does not compose it again.

Two request field handles are filled by the system and refused if typed:

| Handle | Value |
| --- | --- |
| `vanity_url` | The vanity domain and back-half, lowercased and percent-encoded, such as `uams.info%2Fflu`. Blank on a main-site request. Left off when the suppress option is on and the parameter's name starts with `utm_`. |
| `qr_code` | `false` on the vanity redirect, on a main-site request and on any redirect without a QR code; `true` on its `-QR` redirect. |

A request with a QR code is issued two URLs, one for each redirect, so the QR redirect's scans are counted under its own back-half.

## What the server refuses

Each submission is checked on the server, and a refusal is a `422` that names the field:

- `This choice is not offered at this step.` The chosen option is not one the cascade offers there, given the earlier choices.
- `This choice is not in the list.` for a choice from another list, and `The channel is not one this site offers.` for the channel.
- `No tagged URL parameter reads this field.`, `No tagged URL parameter reads this list.` and `No tagged URL parameter reads this value.`: the choice is one no enabled parameter reads. Of the request fields, only the three older handles (`utm_campaign`, `utm_id`, `utm_marketing_tactic`) are refused when nothing reads them; another unread handle is ignored.
- `The system fills this value.` for `vanity_url` and `qr_code`.
- `Tagged URL requests are not set up on this site.` when the collection is missing.

An approver gets `Tagged URL parameters are not set up on this site, so no tagged URL can be issued yet.` when the `tagged_url_parameters` global does not exist, and the request stays pending.

## Reference

- [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md): the cascade, the parameters and how the URL is built.
- [Permissions](Permissions.md)
