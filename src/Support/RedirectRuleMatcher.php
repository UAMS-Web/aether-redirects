<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * Selects the redirect rule that applies to a URI, independently of who is asking.
 *
 * Extracted from {@see FindRedirectRule} (UAMS-Web/uams-statamic-archive#1765) so the tag and
 * the host application's link-health resolver cannot disagree about what redirects. That
 * agreement is an acceptance criterion rather than a nicety: a link report that says a path is
 * fine while the site redirects it — or the reverse — is worse than no report, because it sends
 * an editor to a page that behaves differently from what they were told.
 *
 * The alternative was to re-derive the selection in the resolver. It is four filters (`enabled`,
 * validity window, approval status, pattern-source) over a two-pass exact-before-pattern walk,
 * and two hand-maintained copies of that drift.
 *
 * This follows a precedent rather than inventing one — {@see RedirectPattern} was factored out of
 * the same tag for the same reason. What stays behind in the tag is only what is genuinely
 * request-bound: reading parameters, the current URI, and the query string.
 *
 * The selection is deliberately UNCHANGED from the extracted original, down to the comments, so
 * the move is reviewable as a relocation rather than a rewrite.
 */
class RedirectRuleMatcher
{
    private const int MAX_REDIRECT_REGEX_PATTERN_BYTES = 1024;

    private const int MAX_REDIRECT_REGEX_SUBJECT_BYTES = 4096;

    // Moving either step limit by one is not observable. A test would need a pattern whose
    // match takes exactly the limit in steps, and PCRE counts steps differently under JIT and
    // the interpreter, so no stable input sits on that edge. Measured with each of the four
    // applied by hand: FindRedirectRuleTest and RedirectRuleMatcherTest pass unchanged.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    private const int REDIRECT_REGEX_PCRE_BACKTRACK_LIMIT = 50_000;

    // The same reason as the backtrack limit above.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    private const int REDIRECT_REGEX_PCRE_RECURSION_LIMIT = 50_000;

    /**
     * Read a site's redirect rules, with the default site's list merged beneath.
     *
     * @param  GlobalSetContract  $global  The redirects global set.
     * @param  string  $siteHandle  The site whose rules are wanted.
     * @param  string|null  $defaultSiteHandle  The default site, or null to skip the merge.
     * @return array<int, array<string, mixed>> The site's rules, default-site rules merged beneath.
     */
    public function rulesForSite(GlobalSetContract $global, string $siteHandle, ?string $defaultSiteHandle): array
    {
        $redirects = $this->redirectsForSite($global, $siteHandle);

        // Only reach for the default site when the requested site is not itself the default — a
        // same-site merge is a no-op, and skipping it avoids a redundant localization read.
        if ($defaultSiteHandle !== null && $defaultSiteHandle !== $siteHandle) {
            return $this->mergeDefaultRedirects(
                $redirects,
                $this->redirectsForSite($global, $defaultSiteHandle),
            );
        }

        return $redirects;
    }

    /**
     * The rule that applies to this URI, or an empty array when none does.
     *
     * @param  array<int, array<string, mixed>>  $redirects  The candidate rules.
     * @param  string  $currentUri  The canonicalized request path.
     * @param  string  $currentQuery  The request query string, which drives matching and forwarding.
     * @return list<array<string, mixed>> The matching rule in an array, or an empty array.
     */
    public function match(array $redirects, string $currentUri, string $currentQuery): array
    {
        // Build the path-with-query subject a query-bearing source matches against
        $currentUriWithQuery = $currentQuery === ''
            ? $currentUri
            : $currentUri.'?'.$currentQuery;

        // Check if there are any redirects
        // The early return below carries a RemoveEarlyReturn mutant that no input can kill:
        // removing it sorts an empty array, iterates nothing, and falls through to the same
        // `return []` that closes match(), so both routes answer identically for every input.
        // Measured alongside the three empty-string fallbacks in the pattern pass below: 125
        // passed (683 assertions), identical to the pristine run.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if ($redirects === []) {
            // Return empty array if no redirects found
            return [];
        }

        // ---
        // Sort redirects by order field (ascending) to ensure proper matching
        // priority; lower order values are checked first
        // ---
        usort(
            $redirects,
            function (array $a, array $b): int {
                $orderA = $a['order'] ?? 30;
                $orderB = $b['order'] ?? 30;

                return $orderA <=> $orderB;
            }
        );

        // ---
        // First, check for exact matches on the source field; this ensures
        // backwards compatibility and prioritizes exact matches over regex
        // patterns
        // ---
        foreach ($redirects as $redirect) {
            // Check if redirect is enabled
            if (! ($redirect['enabled'] ?? true)) {
                // Skip disabled redirects
                continue;
            }

            // Skip redirects outside their optional date validity window (UAMS-Web/uams-statamic-archive#1355)
            if (! $this->isWithinValidityWindow($redirect)) {
                // Not yet valid, or already expired
                continue;
            }

            // Skip redirects awaiting approval or rejected (UAMS-Web/uams-statamic-archive#1357); a missing
            // status (legacy globals-editor + importer rows) grandfathers as approved
            if (! $this->isApproved($redirect)) {
                // Pending or rejected: not yet live
                continue;
            }

            // Skip a pattern source; those are resolved in the second pass so a
            // literal rule always wins over one that merely covers the same path
            if ($this->isPatternSource($redirect)) {
                // Handled below
                continue;
            }

            // Check if this is an exact match, folding case for SRM parity
            if (
                $this->sourceMatchesExactly(
                    $redirect,
                    $currentUri,
                    $currentUriWithQuery
                )
            ) {
                // Return the matching redirect, forwarding the request's query
                return [
                    $this->withComposedQueryString(
                        $redirect,
                        $currentQuery
                    ),
                ];
            }
        }

        // ---
        // If no exact match found, check the pattern sources — a `use_regex`
        // PCRE, or a trailing-`*` wildcard. Patterns are checked after exact
        // matches to maintain backwards compatibility, and because UAMS-Web/uams-statamic-archive#1657 relies
        // on a pattern only ever matching a request nothing literal claimed
        // ---
        foreach ($redirects as $redirect) {
            // Check if redirect is enabled
            if (! ($redirect['enabled'] ?? true)) {
                // Skip disabled redirects
                continue;
            }

            // Skip redirects outside their optional date validity window (UAMS-Web/uams-statamic-archive#1355)
            if (! $this->isWithinValidityWindow($redirect)) {
                // Not yet valid, or already expired
                continue;
            }

            // Skip redirects awaiting approval or rejected (UAMS-Web/uams-statamic-archive#1357); a missing
            // status (legacy globals-editor + importer rows) grandfathers as approved
            if (! $this->isApproved($redirect)) {
                // Pending or rejected: not yet live
                continue;
            }

            // Skip anything the exact-match pass already had its chance at
            if (! $this->isPatternSource($redirect)) {
                // Not a pattern source
                continue;
            }

            // ---
            // Get the pattern from the source field.
            //
            // The three empty strings below each carry an EmptyStringToNotEmpty mutant that no
            // test worth writing can kill. Either fallback is reached only by a `use_regex` rule
            // whose `source` is missing or is not a string, because a wildcard source is a string
            // and never takes them. The original skips such a rule; the mutant compiles the
            // substituted literal instead and evaluates it, and that pattern matches only a URI
            // containing the mutation tool's own replacement string. The empty-pattern guard is
            // the same case read the other way round: it stops skipping an empty pattern, which
            // RedirectPattern::compile() returns null for anyway. Measured with all
            // three replaced, alongside the empty-redirects early return in match(): 125 passed
            // (683 assertions), identical to the pristine run.
            // ---
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            $sourceValue = $redirect['source'] ?? '';
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            $pattern = \is_string($sourceValue) ? $sourceValue : '';
            // Check if pattern is empty
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ($pattern === '') {
                // Skip empty patterns
                continue;
            }

            if ($pattern === '0') {
                // Skip empty patterns
                continue;
            }

            // Resolve the redirect id up front; both branches log against it
            $redirectId = isset($redirect['id']) && \is_scalar($redirect['id'])
                ? (string) $redirect['id']
                : null;

            // Check whether this rule matches by PCRE or by wildcard
            if ($redirect['use_regex'] ?? false) {
                // ---
                // Match the current URI against the regex pattern with bounded
                // PCRE work to reduce ReDoS risk from globals-defined patterns
                // ---
                $compiled = $this->compiledRedirectPattern(
                    $pattern,
                    $currentUri
                );

                // Skip a source that cannot compile either as stored or wrapped
                if ($compiled === null) {
                    // Not runnable
                    continue;
                }

                // Check whether the compiled pattern matches the current URI
                if (
                    ! $this->uriMatchesRedirectRegex(
                        $compiled,
                        $currentUri,
                        $redirectId
                    )
                ) {
                    // No match
                    continue;
                }

                // Substitute the pattern's captures, then forward the query
                return [
                    $this->withComposedQueryString(
                        $this->withSubstitutedTarget(
                            $compiled,
                            $currentUri,
                            $redirect,
                            $redirectId
                        ),
                        $currentQuery
                    ),
                ];
            }

            // Check whether the URI falls under a trailing-`*` wildcard source
            if (
                RedirectPattern::matchesWildcard(
                    $pattern,
                    $currentUri
                )
            ) {
                // Append the matched remainder, then forward the query
                return [
                    $this->withComposedQueryString(
                        $this->withExpandedTarget(
                            $pattern,
                            $currentUri,
                            $redirect
                        ),
                        $currentQuery
                    ),
                ];
            }
        }

        // No matching redirect found
        return [];
    }

    /**
     * Read a single site's redirect list from the redirects global set.
     *
     * @param  GlobalSetContract  $global  The redirects global set
     * @param  string  $siteHandle  The site handle whose localization to read
     * @return array<int, array<string, mixed>> The site's redirect rules, or an empty array when the localization is absent
     */
    public function redirectsForSite(GlobalSetContract $global, string $siteHandle): array
    {
        // Get the global variables for the specified site
        /** @var Variables|null $variables */
        $variables = $global->in($siteHandle);

        // Return an empty list when this site has no localization
        if (! $variables) {
            return [];
        }

        // Get all redirects from this site's localization
        $redirectsValue = $variables->get(
            'redirects',
            []
        );

        // Normalize to an array for safe iteration and merging
        /** @var array<int, array<string, mixed>> $redirects */
        $redirects = \is_array($redirectsValue) ? $redirectsValue : [];

        return $redirects;
    }

    /**
     * Merge the default site's redirects beneath a site's own redirects.
     *
     * A redirect the requested site defines wins over a default-site redirect with
     * the same `source`; default-site redirects for sources the requested site
     * does not define are appended (they fill the rest). Priority across the merged
     * set is still resolved by the `order` sort in index(), so this only decides
     * membership, not match order.
     *
     * @param  array<int, array<string, mixed>>  $siteRedirects  The requested site's redirects (take precedence)
     * @param  array<int, array<string, mixed>>  $defaultRedirects  The default site's redirects (fill the rest)
     * @return array<int, array<string, mixed>> The merged redirect list
     */
    public function mergeDefaultRedirects(array $siteRedirects, array $defaultRedirects): array
    {
        // ---
        // Index the sources the requested site already defines, so a default-site
        // redirect for the same path never overrides the site-specific one.
        // ---
        $siteSources = [];
        foreach ($siteRedirects as $redirect) {
            if (
                isset($redirect['source'])
                && \is_string($redirect['source'])
            ) {
                // The value here is never read — this map is a set, and its only consumer is the
                // `isset()` below, which is true for any value that is not null. `false` is not
                // null, so flipping this literal cannot change the merge. Recorded rather than
                // killed because no test can distinguish the two.
                // @pest-mutate-ignore:TrueToFalse
                $siteSources[$redirect['source']] = true;
            }
        }

        // Start from the site's own redirects (they win)
        $merged = $siteRedirects;

        // Append every default redirect whose source the requested site does not define
        foreach ($defaultRedirects as $redirect) {
            // KNOWN SURVIVOR, DELIBERATELY NOT ANNOTATED. This condition carries two `&&`, and
            // `BooleanAndToBooleanOr` mutates each of them separately:
            //
            //   inner   `(isset($s) || is_string($s)) && isset($siteSources[$s])`   EQUIVALENT
            //   outer   `(isset($s) && is_string($s)) || isset($siteSources[$s])`   killed by tests
            //
            // The inner one is equivalent because its two operands cannot disagree in a way the
            // conjunction can observe: a set string key makes both true, an absent key makes both
            // false, and a set non-string key leaves the third operand deciding — `isset()` on an
            // illegal offset being false rather than an error. So it survives and no input kills it.
            //
            // It is NOT annotated, because `@pest-mutate-ignore` selects by mutator NAME and cannot
            // distinguish two instances of one mutator on the same node. Annotating it here also
            // suppresses the outer mutation, which the suite does kill — measured: with the
            // annotation the node yields one mutant, without it two, one killed and one surviving.
            // Silencing a killed mutant would hide the coverage that kills it, so the survivor is
            // recorded here instead. Kill it by restructuring the condition, not by annotating it.
            if (
                isset($redirect['source'])
                && \is_string($redirect['source'])
                && isset($siteSources[$redirect['source']])
            ) {
                // The requested site overrides this source — skip the default row
                continue;
            }

            $merged[] = $redirect;
        }

        return $merged;
    }

    /**
     * Whether a redirect is within its optional date validity window right now.
     *
     * A marketing redirect may carry `valid_from` / `valid_until` date bounds
     * (UAMS-Web/uams-statamic-archive#1355); the redirect only serves while today falls inside that window.
     * Legacy redirects carry neither bound, so their window is fully open and
     * they always serve — the behavior is unchanged for every existing rule.
     *
     * @param  array<string, mixed>  $redirect  The redirect row under consideration
     * @return bool True when today is within the redirect's validity window
     */
    private function isWithinValidityWindow(array $redirect): bool
    {
        // Honor the row's optional window against the current instant.
        return ValidityWindow::fromRow($redirect)->contains(CarbonImmutable::now());
    }

    /**
     * Whether a redirect is approved to serve traffic (UAMS-Web/uams-statamic-archive#1357).
     *
     * A utility-created redirect only serves once an approver promotes it to
     * `approved`; a `pending` or `rejected` row is withheld. Legacy globals-editor
     * rows and WordPress-importer-created rows carry no `status` key and are
     * grandfathered as approved, so existing redirects are unaffected.
     *
     * @param  array<string, mixed>  $redirect  The redirect row under consideration
     * @return bool True when the redirect's approval status permits serving
     */
    private function isApproved(array $redirect): bool
    {
        // Resolve the row's status (grandfathering pre-#1357 rows to approved).
        return RedirectStatus::fromRow($redirect)->servesTraffic();
    }

    /**
     * Whether a redirect's source is matched by pattern rather than literally.
     *
     * A `use_regex` rule and a trailing-`*` wildcard are both resolved in the
     * pattern pass; everything else is a literal the exact-match pass owns.
     *
     * @param  array<string, mixed>  $redirect  The redirect row under consideration
     * @return bool True when the row's source is a PCRE or a wildcard
     */
    private function isPatternSource(array $redirect): bool
    {
        // A regex rule is a pattern regardless of how its source reads.
        if ($redirect['use_regex'] ?? false) {
            return true;
        }

        // Otherwise only a trailing-`*` source is a pattern.
        // The `''` below carries an EmptyStringToNotEmpty mutant that no input distinguishes.
        // The fallback is consumed only by RedirectPattern::isWildcard(), which is
        // str_ends_with($value, '*'); the substituted literal does not end with `*`, so both
        // values answer false and isPatternSource() returns the same bool for every redirect.
        // Measured alongside the two fallbacks in withComposedQueryString(): 125 passed (683
        // assertions), identical to the pristine run.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $sourceValue = $redirect['source'] ?? '';

        return \is_string($sourceValue) && RedirectPattern::isWildcard($sourceValue);
    }

    /**
     * Whether a literal redirect source matches the current URI.
     *
     * SRM folds case on both sides before comparing — its
     * `uamswp_srm_case_insensitive_redirects` filter defaults to on — so
     * `/HotDog` and `/hotdog` resolve to the same stored rule. Both sides are
     * canonicalized first, so a source stored as `/course/` still answers a
     * request for `/course`; 1,637 rules in the export corpus are stored that way.
     *
     * A source that names a query string of its own is compared against the
     * request's path *and* query, which is how SRM lets a rule target one
     * specific query rather than a path.
     *
     * @param  array<string, mixed>  $redirect  The redirect row under consideration
     * @param  string  $uri  The canonicalized request path
     * @param  string  $uriWithQuery  The canonicalized request path with its query appended
     * @return bool True when the row's source matches the request, ignoring case
     */
    private function sourceMatchesExactly(
        array $redirect,
        string $uri,
        string $uriWithQuery
    ): bool {
        // Read the stored source, tolerating a non-string value.
        $sourceValue = $redirect['source'] ?? '';

        // A non-string source can never match.
        if (! \is_string($sourceValue)) {
            return false;
        }

        // Canonicalize the source the same way the request path was.
        $source = RedirectSource::normalize($sourceValue);

        // Compare against the query-bearing subject only when the source names one.
        $subject = self::sourceCarriesQuery($source) ? $uriWithQuery : $uri;

        // Compare byte-wise folded, matching SRM's own strtolower().
        return strtolower($source) === strtolower($subject);
    }

    /**
     * Whether a stored source names a query string of its own.
     *
     * Ports SRM's `strpos($redirect_from, '?')` test, which is deliberately
     * falsy at position zero — a source that is nothing but a query is not a
     * query-bearing path.
     *
     * @param  string  $source  The canonicalized stored source
     * @return bool True when the source carries a query string
     */
    private static function sourceCarriesQuery(string $source): bool
    {
        // Locate the query separator within the source.
        $position = strpos($source, '?');

        // Only a separator past the first character counts.
        //
        // Four mutants of this expression are provably equivalent, all for one reason: every
        // source reaching here has been through RedirectSource::normalize(), which is
        // `'/'.trim($raw, '/')` and always prepends a slash, so strpos() can return false or an
        // index of 1 or more but never 0. `>= 0` and `> -1` therefore agree with `> 0` on every
        // reachable input; and with 0 excluded, `!== false` and `> 0` are true together or false
        // together, which makes both `||` and the always-true `!== true` agree as well.
        // `> 1` is NOT equivalent — index 1 is reachable and FindRedirectRuleTest pins it.
        // @pest-mutate-ignore:GreaterToGreaterOrEqual,DecrementInteger,BooleanAndToBooleanOr,FalseToTrue
        return $position !== false && $position > 0;
    }

    /**
     * Return the redirect with the request's query string folded into its own.
     *
     * SRM forwards the incoming query to the destination so attribution and
     * marketing parameters survive a redirect, and skips it when the rule matched
     * *on* a query (that query is already accounted for in the target). Both the
     * rule's configured `target_query_string` and the forwarded query are merged
     * into that one field, so the redirects partial appends them in one place for
     * entry and URL targets alike.
     *
     * @param  array<string, mixed>  $redirect  The matched redirect row
     * @param  string  $currentQuery  The request's query string, or an empty string
     * @return array<string, mixed> The redirect row with its effective query string
     */
    private function withComposedQueryString(array $redirect, string $currentQuery): array
    {
        // Read the rule's own configured query string, tolerating a non-string.
        $storedValue = $redirect['target_query_string'] ?? '';
        $stored = \is_string($storedValue) ? ltrim(trim($storedValue), '?&') : '';

        // ---
        // Read the stored source so the query-bearing test can run against it —
        // but only for a literal or wildcard source, where a `?` really is a
        // query separator. In a PCRE source it is a quantifier, so SRM forces the
        // test off for regex rules and always forwards; the corpus shape
        // `^\/name\/?(\?.*)?$` carries three of them and matched on none.
        // ---
        // Both empty strings below carry an EmptyStringToNotEmpty mutant that no input
        // distinguishes. Each is consumed only by sourceCarriesQuery(), which is
        // strpos($source, '?') > 0; the substituted literal contains no `?`, so it and `''`
        // both answer false and $forwarded is the same value either way. Measured alongside the
        // fallback in isPatternSource(): 125 passed (683 assertions), identical to the pristine run.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $sourceValue = $redirect['source'] ?? '';
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $source = ! ($redirect['use_regex'] ?? false) && \is_string($sourceValue)
            ? RedirectSource::normalize($sourceValue)
            : '';

        // Withhold the forwarded query when the rule matched on one already.
        $forwarded = self::sourceCarriesQuery($source) ? '' : $currentQuery;

        // Join whichever parts are present.
        $composed = implode(
            '&',
            array_filter(
                [$stored, $forwarded],
                static fn (string $part): bool => $part !== ''
            )
        );

        // Leave the row's shape alone when there is no query to carry, so a rule
        // that never had the field does not gain one.
        if ($composed === '') {
            return $redirect;
        }

        // Write the effective query string back onto the row.
        $redirect['target_query_string'] = $composed;

        return $redirect;
    }

    /**
     * Compile a stored regex source, enforcing the ReDoS byte bounds first.
     *
     * The bounds are measured against the source as *stored*, which is what an
     * editor or the importer controls; compilation only ever adds delimiters.
     *
     * @param  string  $source  The stored regex source
     * @param  string  $uri  The URI the pattern would be run against
     * @return string|null A runnable pattern, or null when it is over bounds or will not compile
     */
    private function compiledRedirectPattern(string $source, string $uri): ?string
    {
        // Return null if the pattern is too long
        if (\strlen($source) > self::MAX_REDIRECT_REGEX_PATTERN_BYTES) {
            return null;
        }

        // Return null if the URI is too long
        if (\strlen($uri) > self::MAX_REDIRECT_REGEX_SUBJECT_BYTES) {
            return null;
        }

        // Hand back a pattern PCRE will accept, or null when there is none.
        return RedirectPattern::compile($source);
    }

    /**
     * Run a PCRE operation under conservative backtrack and recursion limits.
     *
     * Every pattern reaching this class comes from the globals store, so both the
     * match and the capture substitution that follows it are bounded the same way.
     *
     * @param  callable():(int|string|null|false)  $operation  The PCRE call to run
     * @return int|string|null|false Whatever the operation returned
     */
    private function withBoundedPcre(callable $operation): int|string|null|false
    {
        // Get the previous backtrack limit
        $previousBacktrackLimit = ini_get('pcre.backtrack_limit');

        // Get the previous recursion limit
        $previousRecursionLimit = ini_get('pcre.recursion_limit');

        // Run the operation with bounded PCRE work
        try {
            // Set the backtrack limit to the maximum allowed value
            //
            // Dropping either cast below is equivalent. `ini_set()`'s `$value` parameter has
            // accepted `string|int|float|bool|null` since PHP 8.1, so an `int` constant passed
            // unconverted is neither a `strict_types` violation nor a different value — the
            // engine converts it to the same string. The casts are kept for the reader.
            // @pest-mutate-ignore:RemoveStringCast
            ini_set(
                'pcre.backtrack_limit',
                (string) self::REDIRECT_REGEX_PCRE_BACKTRACK_LIMIT
            );

            // Set the recursion limit to the maximum allowed value
            //
            // Removing this call is equivalent while the two bounds are equal. `pcre.backtrack_limit`
            // caps how many match steps PCRE takes and `pcre.recursion_limit` caps how deep it goes,
            // and a depth of N cannot be reached in fewer than N steps -- so with both set to the
            // same value the step limit is reached no later than the depth limit, and
            // PREG_RECURSION_LIMIT_ERROR never surfaces. Probed at these limits with four
            // catastrophic shapes inside the byte bounds enforced above: every one returned
            // PREG_BACKTRACK_LIMIT_ERROR, and the same shapes DO return PREG_RECURSION_LIMIT_ERROR
            // once the depth limit alone is lowered, so the probe can tell the two apart. Measured
            // with the call removed: 131 passed (725 assertions) across the three redirect suites,
            // identical to the pristine run. The equivalence is bounded to an ambient depth limit at
            // or above this one -- a process configured lower would be a different case.
            //
            // The RemoveFunctionCall mutant is therefore equivalent and is NOT annotated, because
            // it cannot be. That mutator removes the ini_set() statement itself, and an annotation
            // reaches the node's CHILDREN rather than the node it is attached to -- so naming it
            // here generates the mutant and reports it as surviving while reading as suppressed,
            // which is worse than leaving it visible. RemoveStringCast is a different case and is
            // annotated: it targets the cast inside the call, which is a child. Measured on the
            // merged state: of 197 covered mutants only this one and the BooleanAndToBooleanOr in
            // mergeDefaultRedirects() survive, and the same mutator is killed at all three
            // unannotated ini_set() calls in this method, so it is generated and killable here and
            // simply is not equivalent at those three.
            // @pest-mutate-ignore:RemoveStringCast
            ini_set(
                'pcre.recursion_limit',
                (string) self::REDIRECT_REGEX_PCRE_RECURSION_LIMIT
            );

            // Hand the bounded execution to the caller's operation
            return $operation();
        } finally {
            // Restore the previous backtrack limit
            ini_set(
                'pcre.backtrack_limit',
                $previousBacktrackLimit
            );

            // Restore the previous recursion limit
            ini_set(
                'pcre.recursion_limit',
                $previousRecursionLimit
            );
        }
    }

    /**
     * Return the redirect with its wildcard target expanded.
     *
     * A `*`-terminated target receives the part of the URI beyond the source's
     * base; any other target is a fixed destination and is left untouched.
     *
     * @param  string  $source  The stored wildcard source
     * @param  string  $uri  The URI that matched the source
     * @param  array<string, mixed>  $redirect  The matched redirect row
     * @return array<string, mixed> The redirect row, target expanded where applicable
     */
    private function withExpandedTarget(
        string $source,
        string $uri,
        array $redirect
    ): array {
        // Only a URL target can carry a wildcard; an entry target is an id.
        if (($redirect['target_type'] ?? null) !== 'url') {
            return $redirect;
        }

        // Read the stored target, tolerating a non-string value.
        $targetValue = $redirect['target_url'] ?? '';

        // Leave a non-string or empty target alone.
        if (! \is_string($targetValue) || $targetValue === '') {
            return $redirect;
        }

        // Append the matched remainder when the target invites it.
        $redirect['target_url'] = RedirectPattern::expandTarget(
            $source,
            $targetValue,
            $uri
        );

        return $redirect;
    }

    /**
     * Return the redirect with its regex captures substituted into the target.
     *
     * The substitution runs the already-matched pattern a second time, under the
     * same PCRE bounds, so a `$1` in the target resolves to what the source
     * captured. The result is then held to the stored target's own scope.
     *
     * @param  string  $pattern  The compiled pattern that matched
     * @param  string  $uri  The URI that matched the pattern
     * @param  array<string, mixed>  $redirect  The matched redirect row
     * @param  string|null  $redirectId  Redirect id for logging when limits are hit
     * @return array<string, mixed> The redirect row, target substituted where applicable
     */
    private function withSubstitutedTarget(
        string $pattern,
        string $uri,
        array $redirect,
        ?string $redirectId
    ): array {
        // Only a URL target can carry captures; an entry target is an id.
        if (($redirect['target_type'] ?? null) !== 'url') {
            return $redirect;
        }

        // Read the stored target, tolerating a non-string value.
        $targetValue = $redirect['target_url'] ?? '';

        // Leave a non-string or empty target alone.
        if (! \is_string($targetValue) || $targetValue === '') {
            return $redirect;
        }

        // Substitute the captures under the same bounds the match ran under.
        $substituted = $this->withBoundedPcre(
            static fn (): ?string => preg_replace(
                $pattern,
                $targetValue,
                $uri
            )
        );

        // Fall back to the stored target when substitution could not complete.
        if (! \is_string($substituted)) {
            // Log the failure so a pathological pattern is visible
            Log::warning(
                'Redirect target substitution failed; serving the stored target.',
                [
                    'redirect_id' => $redirectId,
                    'preg_last_error' => preg_last_error(),
                    'preg_last_error_msg' => preg_last_error_msg(),
                ]
            );

            return $redirect;
        }

        // Hold the result to the scope the stored target was authored in.
        $redirect['target_url'] = RedirectPattern::constrainToStoredScope(
            $targetValue,
            $substituted
        );

        return $redirect;
    }

    /**
     * Whether the URI matches the redirect regex pattern using conservative PCRE limits.
     *
     * @param  string  $pattern  Compiled pattern derived from the redirect source (globals)
     * @param  string  $uri  URI to test
     * @param  string|null  $redirectId  Redirect id for logging when limits are hit
     */
    private function uriMatchesRedirectRegex(
        string $pattern,
        string $uri,
        ?string $redirectId = null
    ): bool {
        // Attempt to match the pattern against the URI with bounded PCRE work
        $match = $this->withBoundedPcre(
            static fn (): int|false => preg_match(
                $pattern,
                $uri
            )
        );

        // Return true if the pattern matches the URI
        if ($match === 1) {
            return true;
        }

        // ---
        // Return false if the pattern does not match the URI.
        //
        // Three mutants on this guard are equivalent for one shared reason: falling through
        // reaches the same answer. preg_match() returns 1, 0 or false, so `=== 1` and `=== -1` are
        // both unreachable here -- the 1 case has already returned -- and dropping the early
        // return has the same effect. A clean non-match then continues to preg_last_error(), which
        // is PREG_NO_ERROR after a completed match, logs nothing, and arrives at the closing
        // `return false`. Measured with each of the three applied in turn: 131 passed (725
        // assertions) across the three redirect suites, identical to the pristine run.
        // ---
        // @pest-mutate-ignore:IncrementInteger,DecrementInteger,RemoveEarlyReturn
        if ($match === 0) {
            return false;
        }

        // Get the last PCRE error
        $pregError = preg_last_error();

        // Log a warning if the last PCRE error is a backtrack limit error or a recursion limit error
        if (
            $pregError === PREG_BACKTRACK_LIMIT_ERROR
            || $pregError === PREG_RECURSION_LIMIT_ERROR
        ) {
            Log::warning(
                'Redirect regex matching stopped due to PCRE limits.',
                [
                    'redirect_id' => $redirectId,
                    'preg_last_error' => $pregError,
                    'preg_last_error_msg' => preg_last_error_msg(),
                ]
            );
        }

        // Return false if the pattern does not match the URI
        return false;
    }
}
