Written against v0.5.2.

A redirect rule is one row of the `redirects` global set, or one entry of the `redirect_domains` collection. This page lists the fields, the order rules are tried in, what a matched rule does, and the Antlers tag that reads them. For steps, see [Writing redirect rules](Writing-redirect-rules.md).

## Fields

| Field | Values | Default | Effect |
| --- | --- | --- | --- |
| `enabled` | on, off | on | Off means the rule is ignored. |
| `source` | a path such as `/old-url`, a path ending in `*`, or a regular expression | none | What the request path is compared with. |
| `use_regex` | on, off | off | Reads `source` as a regular expression and turns the `*` wildcard off. |
| `target_type` | `entry`, `url` | `entry` in the served blueprint | Whether the target is a Statamic entry or a typed URL. |
| `target_entry` | an entry | none | The destination when `target_type` is `entry`. |
| `target_url` | a URL or path | none | The destination when `target_type` is `url`. A `*` at its end takes the rest of the path from a wildcard source. A `$1` takes a capture from a regular-expression source. |
| `target_query_string` | a query string | none | Added to the destination. |
| `response` | `301`, `302`, `303`, `304`, `307`, `308`, `403`, `404`, `410`, `451` | `301` | The status. |
| `order` | an integer | `30` | Lower runs first; equal values keep their stored order. |
| `message` | text | none | Shown with a `403`, `410` or `451`. A 404 page can read it through the tag. |
| `valid_from`, `valid_until` | dates | none | The first and last day the rule serves, both inclusive. |
| `status` | `pending`, `approved`, `rejected` | approved when absent | Only an approved rule serves. The request workflow sets it. |

A rule on a domain adds `host` (a domain record) and `whole_domain`. The request workflow also writes `requester`, `notes`, `creative_services`, `job_number`, `submitted_by`, `reviewed_by`, `rejection_reason`, `generated_back_half`, `qr_role`, `qr_files` and `tagged_choices`.

## How a rule is chosen

1. Rules are sorted by `order`.
2. Rules that are off, outside their dates or not approved are skipped.
3. Exact sources are tried first, in order. A source is compared with the request path after trimming surrounding slashes, ignoring case. A source that contains a `?` is compared with the path and query together, so `/offer?src=mail` matches only that query.
4. Then pattern sources are tried in order: regular expressions, and sources ending in `*`. A wildcard source matches its base and everything below it.
5. The first match wins.

The query string of the request is added to the destination, after the rule's own `target_query_string`, unless the source itself named a query. A regular-expression rule always forwards it.

A regular expression is held to a pattern of 1024 bytes, a request path of 4096 bytes, and bounded backtracking. A source without delimiters is wrapped with a delimiter it does not use and the `i` modifier. A pattern that cannot compile is skipped.

On a site with several languages, the rules of the default site are merged beneath the current site's: a source on the current site wins, and the default site fills the rest.

## What a matched rule does

Applied to a request that is about to render a `404`:

| Status | Result |
| --- | --- |
| `403`, `410`, `451` | That status, rendered with the site's `errors/<code>` view, its layout and the rule's message. |
| `404` | Nothing: the site's 404 page renders. |
| `302`, `303`, `307`, `308` | A redirect with that status. |
| any other (`301`, `304`) | A `301` redirect. |

A rule with no target, or an entry target that is unpublished or has no URL, does nothing. On a redirect domain the same statuses apply with a bare, plain-text body, and a request no rule answers gets a bare `404`.

## The `find_redirect_rule` tag

```antlers
{{ find_redirect_rule handle="redirects" in="default" }}
    {{ target_url }}
{{ /find_redirect_rule }}
```

The tag returns the rule that matches the current request, or nothing. Both parameters are optional.

| Parameter | Default | Effect |
| --- | --- | --- |
| `handle` | `redirects` | The handle of the global set that holds the rules. |
| `in` | the current site | The site whose rules are read. |

The result is the stored row, so it holds the rule's fields, including (`source`, `target_type`, `target_entry`, `target_url`, `target_query_string`, `response`, `order`, `message`, `notes`, `use_regex`, `enabled`, `type` and `id`). The tag and the add-on's own 404 handling use the same engine, so they cannot disagree.

## Reference

- [Writing redirect rules](Writing-redirect-rules.md)
- [Publishing the edge manifest](Publishing-the-edge-manifest.md)
