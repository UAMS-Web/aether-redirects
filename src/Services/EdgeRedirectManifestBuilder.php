<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use UAMSWeb\Aether\Redirects\Support\RedirectPattern;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Support\ValidityWindow;

/**
 * Builds the edge redirect manifest for one site: the part of the `redirects`
 * global set an edge can serve without PHP, in a provider-neutral form (UAMS-Web/uams-statamic-archive#1935).
 *
 * UAMS-Web/uams-statamic-archive#1935 decided that the edge carries a static-safe subset and that everything
 * else keeps being served by the existing PHP path. The same decision left the
 * output target open, Cloudflare rules or a `_redirects` file, until there is
 * something to deploy to, so this emits neither. It emits the subset with the
 * engine's semantics written out as data, and a renderer for whichever target is
 * chosen reads the manifest.
 *
 * The rules are read through `RedirectRuleMatcher::rulesForSite()`, the same merge
 * the serving tag uses, and filtered with the same helpers the matcher uses, so
 * the manifest and the engine cannot disagree about which rules exist.
 *
 * **Leaving a rule out is not enough on its own, and this is the load-bearing
 * part.** The engine walks rules in order and serves the first match. A rule the
 * edge cannot evaluate, a regex or one with a validity window, may match at
 * request time, and when it does it wins over every rule after it. An edge that
 * simply dropped it would serve the next rule instead, which the engine would not.
 * So:
 *
 *   - an exact source whose first rule the edge cannot evaluate is sent to the
 *     origin, together with every later rule for that source; and
 *   - the pattern list stops at the first pattern rule the edge cannot evaluate,
 *     because a regex's matches cannot be expressed at an edge.
 *
 * A request that matches nothing in the manifest goes to the origin, which serves
 * it exactly as today. The manifest is therefore conservative: whatever it serves,
 * the engine serves identically, and everything else is left to PHP.
 *
 * The engine's matching and its redirect are also narrower than they look, and the
 * manifest follows both rather than the rule data alone:
 *
 *   - it matches against a path Statamic has tidied, so a request carrying a
 *     backslash, a colon or a doubled slash is left to the origin, and a source
 *     containing one, which the engine can never match, is left out;
 *   - it redirects through Laravel's URL generator, which roots a relative target
 *     and trims its trailing slash, so fixed targets are emitted rooted and the
 *     edge roots a remainder target once the remainder is appended; and
 *   - only a url target redirects at the edge. An entry target resolves through the
 *     serving template on every request, and follows the entry when it moves.
 *
 * Disabled and unapproved rules are left out as the engine would skip them at the
 * moment of generation. Both can change in the Control Panel without a deploy, so
 * the manifest is a snapshot that a deploy regenerates, as UAMS-Web/uams-statamic-archive#1935 records.
 */
final readonly class EdgeRedirectManifestBuilder
{
    /**
     * The manifest format version, bumped when a field changes meaning.
     */
    public const int VERSION = 1;

    /**
     * The response codes an edge serves itself. Every other code is rendered by the
     * site's error pages, so its rule sends the request to the origin.
     *
     * @var list<int>
     */
    private const array EDGE_REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * The `scope` rule for a site's manifest.
     */
    private const string SITE_SCOPE = 'Valid only for a site with no content of its own. The engine runs on the 404 page, so a page, and any other path the application routes, wins over every rule.';

    /**
     * The `scope` rule for a host's manifest (UAMS-Web/uams-statamic-archive#2678).
     */
    private const string HOST_SCOPE = 'Valid only for the host named in "host", a domain that is not a Statamic site and serves no content of its own. Every request to it is answered by these rules or sent to the origin.';

    /**
     * The engine's default for a rule with no stored order.
     */
    private const int DEFAULT_ORDER = 30;

    /**
     * @param  RedirectRuleMatcher  $matcher  The serving engine, for the site's merged rule list.
     */
    public function __construct(private RedirectRuleMatcher $matcher)
    {
        //
    }

    /**
     * Build the manifest for a site from a redirects global set.
     *
     * @param  GlobalSetContract  $global  The redirects global set.
     * @param  string  $siteHandle  The site the manifest is for.
     * @param  string|null  $defaultSiteHandle  The default site, whose rules merge beneath, or null.
     * @return array<string, mixed> The manifest.
     */
    public function forSite(
        GlobalSetContract $global,
        string $siteHandle,
        ?string $defaultSiteHandle
    ): array {
        // Read the site's rules exactly as the serving tag does
        $rules = $this->matcher->rulesForSite($global, $siteHandle, $defaultSiteHandle);

        return $this->build($rules, $siteHandle, self::reservedPrefixes());
    }

    /**
     * Build the manifest for a domain that is not a Statamic site, from its rules in
     * the `redirect_domains` collection (UAMS-Web/uams-statamic-archive#2678).
     *
     * The rules are the host's alone: no site's rules merge beneath them, and no
     * path is reserved, because the application serves nothing on the host. The
     * manifest names the host instead of a site, and its `scope` reads accordingly.
     *
     * @param  string  $host  The domain the manifest is for.
     * @param  array<int, array<string, mixed>>  $rules  The host's rules, as `RedirectDomainRules` reads them.
     * @return array<string, mixed> The manifest.
     */
    public function forHost(string $host, array $rules): array
    {
        return [
            'version' => self::VERSION,
            'host' => $host,
            'semantics' => self::semantics(self::HOST_SCOPE),
            'reserved_prefixes' => [],
            ...self::compile($rules),
        ];
    }

    /**
     * Build the manifest from a site's merged rule list.
     *
     * @param  array<int, array<string, mixed>>  $rules  The site's rules, as `rulesForSite()` returns them.
     * @param  string  $siteHandle  The site the manifest is for.
     * @param  list<string>  $reservedPrefixes  Paths the application routes itself, which no rule may claim.
     * @return array<string, mixed> The manifest.
     */
    public function build(
        array $rules,
        string $siteHandle,
        array $reservedPrefixes = []
    ): array {
        return [
            'version' => self::VERSION,
            'site' => $siteHandle,
            'semantics' => self::semantics(self::SITE_SCOPE),
            'reserved_prefixes' => $reservedPrefixes,
            ...self::compile($rules),
        ];
    }

    /**
     * The exact entries, the prefix entries, whether the prefix list is complete, and
     * the withheld counts, for a rule list.
     *
     * @param  array<int, array<string, mixed>>  $rules  The rules.
     * @return array{exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>, prefix_complete: bool, withheld: array<string, int>}
     */
    private static function compile(array $rules): array
    {
        // Put the rules in the engine's evaluation order
        $ordered = self::inEngineOrder($rules);

        $withheld = [
            'disabled' => 0,
            'unapproved' => 0,
            'regex' => 0,
            'validity_window' => 0,
            'shadowed' => 0,
            'unmatchable' => 0,
        ];

        // ---
        // Exact pass. The first rule for a source decides it; later ones are dead.
        // ---
        $exact = [];
        $claimed = [];

        foreach ($ordered as $rule) {
            // Leave out what the engine skips, counting why
            $skip = self::skipReason($rule);

            if ($skip !== null) {
                $withheld[$skip]++;

                continue;
            }

            // Patterns belong to the second pass
            if (self::isPatternSource($rule)) {
                continue;
            }

            $source = self::stringValue($rule['source'] ?? null);

            // A source that is not a string can never match
            if ($source === null) {
                continue;
            }

            $normalized = RedirectSource::normalize($source);

            // A source the engine can never match: it compares against a tidied path
            if (self::isUnmatchable($normalized)) {
                $withheld['unmatchable']++;

                continue;
            }

            $key = strtolower($normalized);

            // A later rule for a source already decided is never reached
            if (in_array($key, $claimed, true)) {
                $withheld['shadowed']++;

                continue;
            }

            $claimed[] = $key;

            // A windowed rule may or may not answer at request time, so the origin decides
            if (self::hasValidityWindow($rule)) {
                $withheld['validity_window']++;
                $exact[] = self::originEntry(['source' => $normalized, 'with_query' => self::carriesQuery($normalized)]);

                continue;
            }

            $exact[] = [
                'source' => $normalized,
                'with_query' => self::carriesQuery($normalized),
                ...self::action($rule, $source, false),
            ];
        }

        // ---
        // Pattern pass, in order, up to the first rule the edge cannot evaluate.
        // ---
        $prefix = [];
        $complete = true;

        foreach ($ordered as $rule) {
            // Skipped rules were counted in the exact pass
            if (self::skipReason($rule) !== null) {
                continue;
            }

            if (! self::isPatternSource($rule)) {
                continue;
            }

            // Once a rule the edge cannot evaluate is reached, nothing after it is safe
            if (! $complete) {
                $withheld['shadowed']++;

                continue;
            }

            $source = self::stringValue($rule['source'] ?? null);
            // The engine ignores an empty or `0` pattern source
            if ($source === null) {
                continue;
            }

            if ($source === '') {
                continue;
            }

            if ($source === '0') {
                continue;
            }

            // A regex or a windowed pattern may match anything at request time
            $isRegex = ! empty($rule['use_regex']);

            if ($isRegex || self::hasValidityWindow($rule)) {
                $withheld[$isRegex ? 'regex' : 'validity_window']++;
                $complete = false;

                continue;
            }

            $base = RedirectPattern::base($source);

            // A base the engine can never match, against a tidied path
            if (self::isUnmatchable($base)) {
                $withheld['unmatchable']++;

                continue;
            }

            $prefix[] = [
                'base' => $base,
                ...self::action($rule, $source, true),
            ];
        }

        return [
            'exact' => $exact,
            'prefix' => $prefix,
            'prefix_complete' => $complete,
            'withheld' => $withheld,
        ];
    }

    /**
     * The rules in the order the engine evaluates them: ascending `order`, with
     * ties kept in their stored order, as the engine's stable `usort` keeps them.
     *
     * @param  array<int, array<string, mixed>>  $rules  The rules.
     * @return list<array<string, mixed>> The rules in evaluation order.
     */
    private static function inEngineOrder(array $rules): array
    {
        $ordered = $rules;

        // `usort` also reindexes, so the result is a list whatever keys came in
        usort(
            $ordered,
            static fn (array $a, array $b): int => ($a['order'] ?? self::DEFAULT_ORDER) <=> ($b['order'] ?? self::DEFAULT_ORDER),
        );

        return $ordered;
    }

    /**
     * Why the engine would skip a rule outright, or null when it considers it.
     *
     * @param  array<string, mixed>  $rule  The rule.
     */
    private static function skipReason(array $rule): ?string
    {
        // The engine skips a disabled rule
        if (! ($rule['enabled'] ?? true)) {
            return 'disabled';
        }

        // And one not approved to serve traffic
        if (! RedirectStatus::fromRow($rule)->servesTraffic()) {
            return 'unapproved';
        }

        return null;
    }

    /**
     * Whether a rule is matched by pattern rather than literally, as the engine decides it.
     *
     * @param  array<string, mixed>  $rule  The rule.
     */
    private static function isPatternSource(array $rule): bool
    {
        // A regex rule is always a pattern
        if (! empty($rule['use_regex'])) {
            return true;
        }

        $source = self::stringValue($rule['source'] ?? null);

        return $source !== null && RedirectPattern::isWildcard($source);
    }

    /**
     * Whether a rule serves only within a date window.
     *
     * @param  array<string, mixed>  $rule  The rule.
     */
    private static function hasValidityWindow(array $rule): bool
    {
        $window = ValidityWindow::fromRow($rule);

        return $window->from instanceof CarbonImmutable || $window->until instanceof CarbonImmutable;
    }

    /**
     * Whether a normalized source names a query string of its own.
     *
     * The engine's test is a separator past the first character. A normalized
     * source always opens with `/`, so any separator in it is past the first
     * character and the plain containment test is the same test.
     *
     * @param  string  $source  The normalized source.
     */
    private static function carriesQuery(string $source): bool
    {
        return str_contains($source, '?');
    }

    /**
     * What the edge does for a rule it can evaluate: redirect, or hand the request
     * to the origin when the edge could not reproduce the engine's response.
     *
     * @param  array<string, mixed>  $rule  The rule.
     * @param  string  $source  The rule's stored source.
     * @param  bool  $isPattern  Whether the rule is a wildcard pattern.
     * @return array<string, mixed> The action fields.
     */
    private static function action(
        array $rule,
        string $source,
        bool $isPattern
    ): array {
        $status = self::statusCode($rule['response'] ?? null);

        // Error responses and anything unrecognized are rendered by the origin
        if ($status === null || ! in_array($status, self::EDGE_REDIRECT_STATUSES, true)) {
            return self::originAction();
        }

        // Only a url target redirects at the edge. An entry target resolves through
        // the serving template on each request and moves when the entry is edited,
        // which a deploy-time snapshot would not follow.
        $target = ($rule['target_type'] ?? null) === 'url'
            ? self::stringValue($rule['target_url'] ?? null)
            : null;

        // A `*`-terminated target on a pattern appends the matched remainder, as the engine does
        $appendRemainder = $isPattern && $target !== null && RedirectPattern::isWildcard($target);
        $emitted = $appendRemainder ? rtrim($target, '*') : $target;

        // The redirect tag sends nothing for an empty or `0` location, so neither may
        // this: a target that is empty or `0`, before any remainder, goes to the origin
        if (in_array($emitted, [null, '', '0'], true)) {
            return self::originAction();
        }

        // A remainder target is rooted by the edge, which reproduces the URL generator
        // only for a single-`/` path or an http(s) URL; anything else stays with PHP
        if ($appendRemainder && ! self::hasPredictableRoot($emitted)) {
            return self::originAction();
        }

        return [
            'action' => 'redirect',
            'status' => $status,
            // A fixed target is emitted already rooted, as the URL generator would root it;
            // a remainder target is rooted by the edge after the remainder is appended
            'target' => $appendRemainder ? $emitted : self::rooted($emitted),
            'append_remainder' => $appendRemainder,
            'query_string' => self::storedQueryString($rule),
            'forward_query' => ! self::carriesQuery(RedirectSource::normalize($source)),
        ];
    }

    /**
     * A location as Laravel's URL generator sends it: an absolute URL unchanged,
     * anything else rooted at `/` with its path's surrounding slashes trimmed and its
     * query kept as written (`UrlGenerator::to()`).
     *
     * The generator also prefixes the site's scheme and host, which an edge serving
     * that host supplies by answering with a rooted location.
     *
     * @param  string  $location  The location the serving template builds.
     */
    public static function rooted(string $location): string
    {
        // An absolute URL passes through untouched
        if (URL::isValidUrl($location)) {
            return $location;
        }

        // Split at the first `?`, as the generator does, and root the path
        $position = strpos($location, '?');
        $path = $position === false ? $location : substr($location, 0, $position);
        $query = $position === false ? '' : substr($location, $position);

        return '/'.trim($path, '/').$query;
    }

    /**
     * Whether a remainder target's location is rooted the same way whatever is
     * appended to it: a path opening with exactly one `/`, which the generator roots,
     * or an http(s) URL, which it leaves untouched. The generator's other absolute
     * forms (`//host`, `mailto:`, anything `filter_var()` accepts) can change
     * classification once a remainder is appended, so they are left to the origin.
     *
     * @param  string  $target  The target with its trailing `*` removed.
     */
    private static function hasPredictableRoot(string $target): bool
    {
        // A rooted path the generator rebuilds
        if (str_starts_with($target, '/')) {
            return ! str_starts_with($target, '//');
        }

        // Or an absolute web URL it leaves alone
        return preg_match('~^https?://~', $target) === 1;
    }

    /**
     * The action for a request the origin must answer.
     *
     * @return array<string, mixed> The action fields.
     */
    private static function originAction(): array
    {
        return ['action' => 'origin'];
    }

    /**
     * An exact entry whose request the origin must answer.
     *
     * @param  array<string, mixed>  $match  The entry's match fields.
     * @return array<string, mixed> The entry.
     */
    private static function originEntry(array $match): array
    {
        return [...$match, ...self::originAction()];
    }

    /**
     * The rule's own query string, trimmed as the engine trims it.
     *
     * @param  array<string, mixed>  $rule  The rule.
     */
    private static function storedQueryString(array $rule): string
    {
        $stored = self::stringValue($rule['target_query_string'] ?? null) ?? '';

        return ltrim(trim($stored), '?&');
    }

    /**
     * A stored response code as an integer, or null when it is not one.
     *
     * @param  mixed  $value  The stored `response` value.
     */
    private static function statusCode(mixed $value): ?int
    {
        // The field stores the code as a string key or an integer
        if (is_int($value)) {
            return $value;
        }

        // `||` here is an equivalent mutant: an int never reaches this line, and
        // `ctype_digit()` refuses every other non-string, so both give null.
        // @pest-mutate-ignore: BooleanAndToBooleanOr
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A value as a string, or null when it is not one.
     *
     * @param  mixed  $value  The value.
     */
    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Whether a source or base can never match the engine, which compares against a
     * path Statamic has tidied (`Path::tidy()` turns a backslash into `/` and collapses
     * repeated slashes), or against a path-plus-query that never ends in a bare `?`.
     *
     * @param  string  $value  The normalized source, or a wildcard base.
     */
    private static function isUnmatchable(string $value): bool
    {
        return str_contains($value, '\\') || str_contains($value, '//') || str_ends_with($value, '?');
    }

    /**
     * The paths the application routes itself, ahead of the page the engine runs on,
     * so no rule may claim them at the edge: the Control Panel, Statamic's action
     * routes, and Glide's image route.
     *
     * @return list<string> The reserved path prefixes, each rooted at `/`.
     */
    private static function reservedPrefixes(): array
    {
        $prefixes = [];

        // Only a route the application actually registers is reserved
        foreach ([
            config('statamic.cp.enabled') !== false ? config('statamic.cp.route') : null,
            config('statamic.routes.action'),
            config('statamic.assets.image_manipulation.route'),
        ] as $route) {
            if (is_string($route) && trim($route, '/') !== '') {
                $prefixes[] = '/'.trim($route, '/');
            }
        }

        return $prefixes;
    }

    /**
     * How an edge must read the manifest, stated with it so a renderer needs
     * nothing else.
     *
     * @param  string  $scope  The `scope` rule, which differs between a site and a host.
     * @return array<string, string> The rules, by topic.
     */
    private static function semantics(string $scope): array
    {
        return [
            'scope' => $scope,
            'reserved' => 'Send a request whose path equals an entry of "reserved_prefixes", or begins with one followed by "/", to the origin before trying any rule.',
            'request' => 'Send a request whose raw path contains a backslash, a colon or two consecutive slashes, or whose request target does not parse as a URI, to the origin. Otherwise the path is the raw, still percent-encoded path with surrounding slashes trimmed and exactly one "/" prefixed, the bare root being "/", and the query is the raw query string, empty when there is none.',
            'order' => 'Try the "exact" entries in their listed order, then the "prefix" entries in their listed order. The first match wins, even over a later entry that is more specific. A request that matches nothing goes to the origin.',
            'exact' => 'When "with_query" is false, compare "source" with the path. When it is true, compare it with the path alone if the query is empty, and with path + "?" + query otherwise. Compare after ASCII-lowercasing both sides.',
            'prefix' => 'ASCII-lowercase the path, append "/" unless it is the bare root, and match when the result starts with the lowercased "base".',
            'origin' => 'An entry whose "action" is "origin" claims the request and sends it to the origin unchanged.',
            'target' => 'When "append_remainder" is true, append the path after its first strlen("base") bytes, with leading slashes removed, in its original case.',
            'query' => 'Join the non-empty "query_string" and, when "forward_query" is true, the non-empty request query with "&". Append the result to the target with "&" when the target already contains "?", and "?" otherwise.',
            'location' => 'When "append_remainder" is true and the target begins with "/", root the result: rebuild it as "/" + the part before its first "?" with surrounding slashes trimmed + the "?" and everything after it. A remainder target beginning with "http://" or "https://" is used as built, and every other target is emitted already rooted. A location beginning with "/" is on the requested host.',
            'prefix_complete' => 'False when later pattern rules were withheld behind one the edge cannot evaluate. They are served by the origin either way.',
        ];
    }
}
