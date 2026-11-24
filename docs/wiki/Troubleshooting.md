Written against v0.5.2.

One entry per symptom, as it is met: the message or behavior, its cause, and the fix.

- [A rule in the `redirects` global set does nothing](#a-rule-in-the-redirects-global-set-does-nothing)
- [A redirect domain answers a bare 404](#a-redirect-domain-answers-a-bare-404)
- [The domain is not offered on the request screen](#the-domain-is-not-offered-on-the-request-screen)
- [A short link cannot be made](#a-short-link-cannot-be-made)
- ["does not serve this destination"](#does-not-serve-this-destination)
- ["A vanity URL is made with Creative Services"](#a-vanity-url-is-made-with-creative-services)
- ["A redirect from ... already exists"](#a-redirect-from--already-exists)
- ["The back-half ... is already in use"](#the-back-half--is-already-in-use)
- ["No asset container is set up for QR codes"](#no-asset-container-is-set-up-for-qr-codes)
- [The QR code screen answers 404](#the-qr-code-screen-answers-404)
- ["You cannot review a redirect you requested"](#you-cannot-review-a-redirect-you-requested)
- ["Tagged URL parameters are not set up"](#tagged-url-parameters-are-not-set-up)
- [The Tagged URL only choice is missing](#the-tagged-url-only-choice-is-missing)
- ["You may not manage redirect domains"](#you-may-not-manage-redirect-domains)

## A rule in the `redirects` global set does nothing

A request for the rule's path still shows the site's 404 page.

**Cause:** the rules answer a request that is about to render a `404`, in order, and skip a rule that is off, outside its **Valid From** and **Valid Until** dates, or not approved. A rule requested through the Control Panel stays `pending` until an approver approves it, and a `rejected` one never serves. A path that has content is not a `404`, and Control Panel and API requests are never answered. A rule with no target, or an entry target that is unpublished, does nothing. A rule with response `404` leaves the 404 as it is.

**Fix:** check `enabled`, the dates and `status` on the row, then the order: an earlier rule that also matches wins (see [Redirect rules](Redirect-rules.md)).

## A redirect domain answers a bare 404

Requests to `uams.info` or another redirect domain answer a plain-text `Not Found`.

**Cause:** the add-on answers a request on a domain when the host is not the host of a Statamic site and the host has a published domain record and a published `redirect_domains` entry. Its rules come from that host's entries alone. A request no rule answers gets a bare `404`, and so does a rule that is pending or rejected, outside its dates, or without a target. An entry whose host is not a published record of the domain list is not served.

**Fix:** open the entry and check its domain, `enabled`, status and dates. Check that the domain has a published record in the domain list (see [Setting up redirect domains](Setting-up-redirect-domains.md)).

## The domain is not offered on the request screen

The request screen's domain menu lacks `uams.info`, or another domain.

**Cause:** the menu lists the domains that have a published record in the domain list. A user who holds *Request vanity URLs* or *Request custom vanity URLs* sees only the vanity domains, and *Create short links* adds `go.uams.edu`. Only *Create marketing redirects* sees every record.

**Fix:** add the record, or grant the permission (see [Permissions](Permissions.md)).

## A short link cannot be made

The domain menu has no `go.uams.edu` and there is no **Generate code** button. A user who holds only *Create short links* sees a tagged URL form, and submitting it fails with `Something went wrong. Please try again.` A direct request to the endpoint answers `go.uams.edu is not a redirect domain.`

**Cause:** `go.uams.edu` has no published record in the domain list.

**Fix:** add a published record for `go.uams.edu`.

## "does not serve this destination"

The screen answers `<host> does not serve this destination. An approver can save the exception.`

**Cause:** a vanity domain takes certain destinations: `uams.info` a `uams.edu` page, `uams.health` a `uamshealth.com` page, and `uamsbaptist.health` also any `uamshealth.com` page. A requester who pairs another domain with the destination is refused.

**Fix:** choose the domain the destination calls for (see [Requesting a vanity URL](Requesting-a-vanity-URL.md)), or ask an approver, who can save the pairing; it stays pending.

## "A vanity URL is made with Creative Services"

The screen answers `A vanity URL is made with Creative Services. For a link that does not involve them, request a go.uams.edu short code.`

**Cause:** a vanity URL request must answer yes to the Creative Services question and give a job number.

**Fix:** answer yes and enter the job number, or make a short link instead (see [Making a short link](Making-a-short-link.md)). If a job number format is set, a wrong number is refused with `The job number must match <format>.`

## "A redirect from ... already exists"

The screen answers `A redirect from <domain><source> to <target> already exists within an overlapping validity window.`

**Cause:** another redirect on the same domain and source has dates that share a day with this one, or one of them has no dates. On a vanity domain a rule holds its path for 12 weeks after its last day.

**Fix:** choose another source, or dates that do not overlap (see [Requesting a vanity URL](Requesting-a-vanity-URL.md)).

## "The back-half ... is already in use"

The screen answers `The back-half "<text>" is already in use on this domain, in any case.`

**Cause:** a pending request or an approved redirect holds that back-half on the domain, compared without regard to case. Other refusals name their own rule: letters, numbers and hyphens only, at most 50 characters, not ending in `-QR`, and not a reserved word.

**Fix:** type another back-half, or leave the source blank for a generated one.

## "No asset container is set up for QR codes"

Making the QR files answers `No asset container is set up for QR codes, so no files can be made.`

**Cause:** `qr.container` is `null`, or names a container that does not exist.

**Fix:** create the asset container and set `qr.container` to its handle (see [Configuration](Configuration.md)).

## The QR code screen answers 404

**Cause:** the id in the URL is not the id of a `redirect_domains` entry that has a QR redirect, or the vanity redirect was requested without a QR code.

**Fix:** use the id of the vanity redirect, not the `-QR` one. A request made without the QR option has no code to make, and its screen answers `404`.

## "You cannot review a redirect you requested"

The approve and reject buttons are disabled on a row, which reads `You requested this redirect, so you cannot review it.` The message in the heading comes from the endpoint.

**Cause:** nobody approves their own request.

**Fix:** another user who holds *Approve marketing redirects* reviews it.

## "Tagged URL parameters are not set up"

Approving a tagged request, or a vanity request made with tagged choices, answers `Tagged URL parameters are not set up on this site, so no tagged URL can be issued yet.`

**Cause:** the site has no `tagged_url_parameters` global set, so the approval would issue the bare destination.

**Fix:** create the global set (see [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md)). The request stays pending until then.

## The Tagged URL only choice is missing

**Cause:** the choice is shown to a user who may request vanity URLs or make short links, and only with the `tagged_url_requests` collection. A user who holds only *Request tagged URLs* has no choice, because every request of theirs is a tagged URL. Without the collection the endpoint refuses with `Tagged URL requests are not set up on this site.`

**Fix:** create the collection and copy its blueprint (see [Setting up a site](Setting-up-a-site.md)), and grant the permission.

## "You may not manage redirect domains"

Saving a record of the domain list answers `You may not manage redirect domains.`

**Cause:** the signed-in user lacks *Manage redirect domains*. A domain another record already names is refused too, with `<host> is already in the domain list.`

**Fix:** grant the permission (see [Permissions](Permissions.md)), or edit the existing record.
