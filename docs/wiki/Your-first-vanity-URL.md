Written against v0.5.2.

By the end of this page a vanity URL such as `uams.info/flu` redirects to a UAMS page: one person requested it and another approved it. It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-redirects#installation)) and that the site is set up for redirect domains (see [Setting up redirect domains](Setting-up-redirect-domains.md)), with `uams.info` in the domain list.

**Note:** nobody approves their own request, so this needs two users: one who holds *Request vanity URLs* (or a wider request permission) and one who holds *Approve marketing redirects*. See [Permissions](Permissions.md).

## 1. Open the request screen

In the Control Panel, open **Tools**, then **Marketing Redirects**. The item shows to a user who holds any request permission or *Create short links*.

## 2. Fill in the request

1. **Domain:** choose `uams.info`. The domain menu lists the vanity domains that have a record in the domain list.
2. **Source:** leave it blank. A user who holds only *Request vanity URLs* is shown no source field, and the system generates a back-half: seven letters and numbers that were never issued on that domain, in any case. Typing one takes *Request custom vanity URLs* or *Create marketing redirects*.
3. **Destination:** enter a `https://www.uams.edu/...` page. A `uams.edu` page goes on `uams.info`, and a requester who pairs a destination with another vanity domain is refused.
4. **Redirect type:** `301 Moved Permanently` or `302 Found`.
5. **Creative Services:** answer **Yes** and enter the job number. A vanity URL is made with Creative Services, and an answer of no is refused with a pointer to a `go.uams.edu` short code.
6. Optionally set the valid-from and valid-until dates, the requester and notes.

Submit the request. The screen answers `Redirect submitted for review. It serves on uams.info/<back-half> once an approver approves it.`, naming the generated back-half. Nothing serves yet.

## 3. Approve it

The approver opens **Tools**, then **Review Redirects**. The item shows the number of requests waiting. Each row shows the destination, the dates and the Creative Services answer. Choose **Approve**: `Redirect approved. It now serves on its domain.`

**Warning:** the approver cannot be the requester. On a row the current user requested, the approve and reject buttons are disabled and the row says so.

## 4. See it serve

Open `https://uams.info/<back-half>`. The visitor is redirected to the destination with the status chosen in step 2.

## Go further

- [Requesting a vanity URL](Requesting-a-vanity-URL.md): typed back-halves, dates, collisions and QR codes.
- [Reviewing redirect requests](Reviewing-redirect-requests.md): what an approver checks, and rejecting with a reason.
- [Making a short link](Making-a-short-link.md): a code on `go.uams.edu` for a link that does not involve Creative Services.
