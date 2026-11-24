Written against v0.5.2.

A redirect rule says that a path goes to a target. This guide adds rules to the `redirects` global set, which answers a page that is not found. Rules on a redirect domain are entries of `redirect_domains` and take the same fields (see [Setting up redirect domains](Setting-up-redirect-domains.md)). It assumes the `redirects` global set exists (see [Setting up a site](Setting-up-a-site.md)).

The add-on answers a request that is about to render a `404`, before the 404 page. A path that has content is not redirected by these rules. Control Panel and API requests are never answered.

## A rule for one path

1. Open the `redirects` global set and choose **Add Redirect**.
2. **Source URL:** the path to redirect from, relative to the site root, such as `/my-old-url`. A trailing slash makes no difference.
3. **Target Type:** `Statamic Entry` to follow an entry, or `Explicit URL` to type one.
4. **Target:** choose the entry, or type the URL. An entry target redirects to the entry's URL on the current site, or to the entry's own URL when it has no localization there. An unpublished entry, or one with no URL, makes the rule do nothing.
5. **HTTP Response Code:** leave `301 Moved Permanently` unless there is a reason.
6. Save the global set.

Matching ignores case. Exact sources are tried before wildcard and regular-expression sources, whatever their order. Within each group rules are tried in ascending **Order**, 30 by default, and rules with the same order keep their stored order.

## A rule for everything under a path

End the source with `*`: `/old-section/*` matches `/old-section` and everything below it. If the target also ends in `*`, the rest of the request path is appended to the target:

```text
Source   /old-section/*
Target   https://www.example.org/new-section/*
/old-section/news/2026   goes to   https://www.example.org/new-section/news/2026
```

## A rule with a regular expression

Turn on **Use Regular Expressions?** and the source is a regular expression, which disables the `*` wildcard. Write it with delimiters, or without, in which case the add-on tries a delimiter that the pattern does not use and adds the `i` modifier. A `$1` in a URL target is replaced with what the source captured. An entry target cannot carry captures.

## Dates

**Valid From** and **Valid Until** are optional and inclusive. Outside the window the rule is skipped, as if it were off. Leave both blank for a rule that always serves.

## Turning a rule off

The **Enabled** toggle off leaves a rule in place and ignored. A redirect the site captures automatically when an entry is deleted may start off, as the blueprint's instructions describe.

## A page that answers with an error

The response codes `403`, `410` and `451` answer with that status instead of redirecting, and render the site's `errors/<code>` view with the rule's **Message**. A `404` leaves the 404 the visitor already has and renders the site's 404 page. A rule with no target and a redirect status does nothing.

## Reference

[Redirect rules](Redirect-rules.md) lists every field and the order rules are tried in.
