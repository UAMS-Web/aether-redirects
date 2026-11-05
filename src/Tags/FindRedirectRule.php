<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Tags;

use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Tags\Context;
use Statamic\Tags\Parameters;
use Statamic\Tags\Tags;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;
use UAMSWeb\Aether\Redirects\Support\TagParam;

/**
 * Provides a Statamic tag for finding redirect rules with regex support.
 *
 * Handles the {{ find_redirect_rule }} tag in Antlers templates, allowing
 * retrieval of redirect rules that match the current URI using both exact
 * matches and regular expressions. Matches exact redirects first for backwards
 * compatibility, then checks regex patterns when enabled.
 */
class FindRedirectRule extends Tags
{
    /**
     * The {{ find_redirect_rule }} tag.
     *
     * Finds a redirect rule that matches the current URI, checking exact matches
     * first, then regex patterns when enabled. Returns an array containing the
     * first matching redirect rule respecting the order field and enabled status.
     *
     * Parameters:
     *   - handle (optional): The handle of the redirects global set; defaults to
     *     'redirects'.
     *   - in (optional): The site handle to query; defaults to the current site
     *     if not specified.
     *
     * Returns:
     *   - Array: An array containing the matching redirect rule data if found,
     *     including source, target_type, target_entry, target_url,
     *     target_query_string, response, order, message, notes, use_regex, enabled,
     *     type, and id fields. Returns an empty array if no matching redirect rule
     *     is found.
     *
     * @return list<array<string, mixed>> The matching redirect rule in an array, or empty array if not found
     */
    public function index(): array
    {
        // ---
        // Get the global handle parameter; default to 'redirects' if not
        // specified
        // ---
        /** @var Parameters $params */
        $params = $this->params;
        /** @var string $globalHandle */
        $globalHandle = TagParam::get(
            $params,
            'handle',
            'redirects'
        );

        // ---
        // Get the site handle to query; use the provided 'in' parameter if
        // specified, otherwise default to the current site's handle
        // ---
        /** @var \Statamic\Sites\Site $currentSite */
        $currentSite = Site::current();
        /** @var string $siteHandle */
        $siteHandle = TagParam::get(
            $params,
            'in',
            $currentSite->handle()
        );

        // ---
        // Get the current URI from the request; this is the path that needs to
        // be matched against redirect rules. It is canonicalized the same way a
        // stored source is, so `/foo` and `/foo/` resolve one rule rather than
        // two, and the query string is carried separately — SRM matches against
        // the path alone unless the stored source itself names a query
        // ---
        $currentUri = $this->getCurrentUri();

        // Get the request's query string, which drives both matching and forwarding
        $currentQuery = $this->getCurrentQueryString();

        // ---
        // Fetch the global set for the specified handle and site; this contains
        // the redirect rules
        // ---
        $global = GlobalSet::findByHandle($globalHandle);

        // Check if the global set was found
        if (! $global) {
            // Return empty array if no global set found
            return [];
        }

        // ---
        // Read the requested site's redirect list, then merge the default site's
        // list beneath it (per-site source wins, default fills the rest). The
        // `redirects` field is non-localizable — one shared list edited on the
        // default site, intended to apply everywhere — but on an origin-null
        // non-default site a raw per-site read returns [] (no cascade), so an
        // editor-added redirect silently 404s off the default site. Merging the
        // default list at read time serves that shared list on every site with no
        // content migration (UAMS-Web/uams-statamic-archive#1110, decision C) and subsumes the people-merge
        // per-site writes (UAMS-Web/uams-statamic-archive#1088), which still win on their own site.
        // ---
        // ---
        // Read this site's rules (with the default site's merged beneath) and select the one that
        // applies, both through the shared matcher. The selection used to live here; it moved to
        // `UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher` in UAMS-Web/uams-statamic-archive#1765 so the link-health resolver
        // cannot disagree with what this tag serves. Behavior is unchanged — the code moved
        // verbatim — and the reasoning is in that class's docblock.
        // ---
        $matcher = new RedirectRuleMatcher;

        /** @var \Statamic\Sites\Site|null $defaultSite */
        $defaultSite = Site::default();
        /** @var string|null $defaultSiteHandle */
        $defaultSiteHandle = $defaultSite?->handle();

        return $matcher->match(
            $matcher->rulesForSite($global, $siteHandle, $defaultSiteHandle),
            $currentUri,
            $currentQuery
        );
    }

    /**
     * Get the current URI from the request.
     *
     * Retrieves the current request path, ensuring it starts with a forward
     * slash and is relative to the site root. Uses the context's current_uri
     * if available, otherwise falls back to the request path. The result is
     * canonicalized the same way a stored source is, so a trailing slash never
     * decides whether a rule matches.
     *
     * @return string The canonicalized current URI path
     */
    private function getCurrentUri(): string
    {
        // ---
        // Try to get current_uri from the context first (matches template
        // variable)
        // ---
        /** @var Context $context */
        $context = $this->context;
        $currentUri = $context->get('current_uri');

        // Check if current_uri is available in context (&& not ||: a truthy
        // non-string must fall through to the request path).
        if (
            $currentUri
            && is_string($currentUri)
        ) {
            // Return the canonicalized URI from context
            return RedirectSource::normalize($currentUri);
        }

        // Fall back to getting the path from the request
        $path = request()->path();

        // Canonicalize it the same way, which also forces the leading slash
        return RedirectSource::normalize($path);
    }

    /**
     * Get the current request's query string, in the order it arrived.
     *
     * Read separately from the path because a redirect matches on the path
     * alone unless the stored source names a query of its own, while the query
     * is still forwarded to the destination.
     *
     * Taken off the raw request URI rather than from `getQueryString()`, which
     * normalizes and **sorts** parameters by key — a forwarded query has to reach
     * the destination as the visitor sent it, not re-ordered.
     *
     * @return string The raw query string, or an empty string when there is none
     */
    private function getCurrentQueryString(): string
    {
        // Pull the query off the raw request URI so ordering survives.
        $queryString = parse_url(
            request()->getRequestUri(),
            PHP_URL_QUERY
        );

        // Normalize the absent case to an empty string (EmptyStringToNotEmpty
        // dies when a path-only match must not grow a target_query_string).
        return is_string($queryString) ? $queryString : '';
    }
}
