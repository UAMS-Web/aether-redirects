Written against v0.5.2.

A short link is a redirect on `go.uams.edu` whose source is a generated six-character code, for a link that does not involve Creative Services. It assumes the request screen opens (see [Your first vanity URL](Your-first-vanity-URL.md)) and that `go.uams.edu` has a record in the domain list (see [Setting up redirect domains](Setting-up-redirect-domains.md)). The requester holds *Create short links* (see [Permissions](Permissions.md)).

## Steps

1. Open **Tools**, then **Marketing Redirects**. For a user who holds only *Create short links*, every request is a short link on `go.uams.edu`.
2. Choose **Generate code** on `go.uams.edu`. The code is six characters from the letters and numbers that are easy to tell apart (no `i`, `l`, `o`, `0` or `1`). The button then reads **Generate another**; choose it to draw another before saving.
3. Enter the destination and the redirect type, `301` or `302`.
4. Answer the Creative Services question. A short link may answer either way, and a yes needs a job number.
5. Submit the request.

## What happens next

- **A destination on a trusted host is approved at once:** `Short link created. It serves at go.uams.edu/<code>.` The trusted hosts are the application's own host and its subdomains, each site's domain, and the published records of the domain list. Only an absolute `http` or `https` URL can be trusted.
- **Any other destination waits for an approver:** `Short link submitted for review, because its destination is not on a UAMS domain. It serves at go.uams.edu/<code> once an approver approves it.` A generated code hides its destination, so a code that points elsewhere is reviewed first.

A code is never issued twice on the domain. If another request took the code first, the screen answers `The code <code> is already in use on go.uams.edu. Generate another.`

## Reference

- [Permissions](Permissions.md)
- [Reviewing redirect requests](Reviewing-redirect-requests.md)
