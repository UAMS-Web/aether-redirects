Written against v0.5.2.

A vanity URL is a short address on one of the vanity domains that redirects to a longer one. This guide covers the choices on the request screen. It assumes the request screen opens (see [Your first vanity URL](Your-first-vanity-URL.md)).

## The domain

The vanity domains are `uams.info`, `uams.health` and `uamsbaptist.health`. A domain is offered once it has a record in the domain list.

| Destination | Domain it goes on |
| --- | --- |
| A page on `uams.edu` or a subdomain | `uams.info` |
| A page on `uamshealth.com` or a subdomain | `uams.health` |
| Any page on `uamshealth.com` | `uamsbaptist.health` also accepts it |

A requester who pairs a destination with any other vanity domain is refused with `<host> does not serve this destination. An approver can save the exception.` A user who can approve may save the pairing, and it stays pending.

A user who holds *Create marketing redirects* may also request a rule for the main site, or for any domain in the list. A user who holds *Request custom vanity URLs* is limited to the vanity domains.

## The back-half

The back-half is the part after the domain. The permission decides who types it:

- *Request vanity URLs* leaves the source blank, and the system generates seven letters and numbers.
- *Request custom vanity URLs* and *Create marketing redirects* may type one or leave it blank.

A typed back-half must:

- use only letters, numbers and hyphens, starting and ending with a letter or number, at most 50 characters;
- not end in `-QR` in any case, because `-QR` names the QR redirect of a vanity URL;
- not be a word listed in `back_half.reserved` (see [Configuration](Configuration.md));
- not be held on that domain, in any case, by a pending request or by an approved redirect that is live, not yet started, or inside the 12-week reuse buffer.

Each refusal names the rule that failed. The same rules run again at approval, so two pending requests for one back-half cannot both go live.

## Dates and collisions

The valid-from and valid-until dates are optional and inclusive: a redirect valid until 10 January serves for the whole day. Valid-until must be on or after valid-from.

Two redirects on the same source collide when their date windows share any day, and a redirect with no dates collides with any other on that source. A collision is refused with `A redirect from <domain><source> to <target> already exists within an overlapping validity window.` (or the same without `to <target>`). The comparison includes the domain, so the same path on two domains does not collide.

On a vanity domain a rule holds its path for 12 weeks after its last day, and a rule with no end date holds it without limit. A typed back-half is checked first, against today: while a pending request or an approved rule still holds it, including that buffer, it is refused as already in use whatever dates the new request carries, so it cannot be booked ahead. The date overlap rule therefore decides the main site, other domains and the `-QR` source.

## Creative Services

Every request answers whether it involves Creative Services. A vanity URL must answer yes and give a job number, and the approver sees both. By default any job number is accepted; `creative_services.job_number.pattern` sets a format (see [Configuration](Configuration.md)).

## A QR code

Turn on the QR option and the request saves a second redirect beside the first: the same back-half with `-QR` added. The vanity redirect carries `utm_specs=text-url` and the QR redirect carries `utm_specs=qr`, so scans are counted apart from typed visits. Saving the vanity redirect with a new destination repoints its QR redirect, so a printed code never leads somewhere the text URL does not. (A pair requested with tagged URL choices keeps the destination composed for each side, so a later edit to one side does not reach the other.)

A QR code needs a vanity domain, and the request is refused unless the `-QR` source is free as well.

The files are made on the QR code screen, which can be opened as soon as the vanity redirect exists, at `<cp>/redirects/marketing/<vanity redirect id>/qr`. No screen links to it, so it is reached by its address. It is open to whoever may request a vanity URL and to approvers. **Make the files** writes SVG, PNG, PDF and EPS files to the asset container named by `qr.container`, in the folder `qr.folder`. Making them again replaces each file in place, so its link does not change. The PNG is at least 1000 pixels square. The code encodes `https://<domain><back-half>-QR`, so its destination can still change after printing. Without a container the screen answers `No asset container is set up for QR codes, so no files can be made.`

**Note:** print a QR code with the visible text URL beside it, and do not make a code from the text URL, because scans would count as typed visits. The screen links the university's QR code guidance.

## A tagged URL on the request

A vanity request on a site with lookup data also offers the tagged URL choices. Approval composes one URL for the vanity redirect and one for its QR redirect, and stores each as that redirect's destination (see [Requesting a tagged URL](Requesting-a-tagged-URL.md)).

## Reference

- [Permissions](Permissions.md)
- [Configuration](Configuration.md)
- [Reviewing redirect requests](Reviewing-redirect-requests.md)
