Written against v0.5.2.

A request does not serve until an approver approves it. This guide covers the review queue. The approver holds *Approve marketing redirects* (see [Permissions](Permissions.md)).

## Open the queue

Open **Tools**, then **Review Redirects**. The item carries the number of requests waiting, such as `Review Redirects (3)`. The count adds three kinds of request:

- rules for the main site, saved in the `redirects` global set;
- rules for a redirect domain, saved as `redirect_domains` entries, including short links that wait for review;
- tagged-only requests, saved in `tagged_url_requests`.

## Approve

Each row shows what was requested: the destination, the dates, the Creative Services answer and job number, and for a tagged request the choices made. Check the job number against the job.

Choose **Approve**. The response says what happened:

- `Redirect approved. It now serves on its domain.` for a rule on a redirect domain.
- `Redirect approved. It now serves on a 404 for every site.` for a main-site rule.
- `Tagged URL approved and issued: <url>` for a tagged-only request, whose URL is composed and stored at this moment.

Approval also runs the checks that guard against a race. A back-half that another approved rule issued in the meantime is refused, and a typed back-half passes its rules again. A vanity request made with tagged choices, like a tagged-only request, is refused with `Tagged URL parameters are not set up on this site, so no tagged URL can be issued yet.` until the site has a `tagged_url_parameters` global set.

## Reject

Choose **Reject** and enter a reason, which is required and at most 2048 characters. The reason is kept with the request, and a rejected request never serves.

## What an approver cannot do

- Review a request they made: `You cannot review a redirect you requested.` The row's buttons are disabled and the row says so.
- Review a request twice: `This redirect has already been reviewed.`
- Open the queue or act without the permission: the queue answers `403`.

## Reference

- [Permissions](Permissions.md)
- [Requesting a vanity URL](Requesting-a-vanity-URL.md)
