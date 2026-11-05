<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Assert;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\NotFoundRedirectResolver;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test writes the `redirects` global set.
uses(IsolatesStache::class);

/**
 * The edge redirect manifest, the static-safe subset of the `redirects` global
 * set in a provider-neutral form, checked against the engine that serves the
 * same rules (UAMS-Web/uams-statamic-archive#1935, UAMS-Web/uams-statamic#398).
 *
 * The central test is a parity check with two independent arms:
 *
 *   - the engine: the 404 resolver asked about a request, against these rules
 *     stored in the real global set, so the redirect goes through Laravel's URL
 *     generator exactly as it does on a 404; and
 *   - an edge: a reference evaluator that follows the manifest's own `semantics`
 *     text step by step, and nothing else.
 *
 * Whenever the edge serves a redirect, the engine must serve the same status and
 * the same Location. The fixture carries exact, wildcard and regex rules, plus the
 * rules and requests an edge must leave to the origin: disabled, unapproved and
 * windowed rules, error responses, entry targets, empty targets, sources the engine
 * can never match, untidy request paths, reserved routes, and rules shadowed behind
 * ones the edge cannot evaluate.
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/EdgeRedirectManifestTest.php
 */

/** The host the test application answers on, which a rooted edge location resolves against. */
const EDGE_MANIFEST_HOST = 'http://localhost';

beforeEach(function (): void {
    View::addLocation(__DIR__.'/../../Fixtures/views');

    $set = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Redirects');
    $set->save();

    $vars = $set->makeLocalization('default');
    Assert::assertInstanceOf(Variables::class, $vars);
    $vars->data(['redirects' => []]);
    $vars->save();
});

/**
 * A value read from the manifest, asserted to be an array.
 *
 * @param  mixed  $value  The value.
 * @param  string  $label  What the value is, for the failure message.
 * @return array<array-key, mixed>
 */
function edgeManifestArray(mixed $value, string $label): array
{
    Assert::assertIsArray($value, "The {$label} is not an array.");

    return $value;
}

/**
 * A value read from the manifest or a response, asserted to be a string.
 *
 * @param  mixed  $value  The value.
 * @param  string  $label  What the value is, for the failure message.
 */
function edgeManifestString(mixed $value, string $label): string
{
    Assert::assertIsString($value, "The {$label} is not a string.");

    return $value;
}

/**
 * A fixture rule, with the store's defaults filled in.
 *
 * @param  string  $source  The rule's source.
 * @param  array<string, mixed>  $overrides  Fields that differ from the defaults.
 * @return array<string, mixed>
 */
function edgeManifestRule(
    string $source,
    array $overrides = []
): array {
    return [
        'source' => $source,
        'enabled' => true,
        'use_regex' => false,
        'target_type' => 'url',
        'response' => '301',
        'order' => 30,
        ...$overrides,
    ];
}

/**
 * The fixture rule set, in stored order.
 *
 * @return list<array<string, mixed>>
 */
function edgeManifestRules(): array
{
    return [
        // Exact rules: case, a stored trailing slash, a forwarded query, a query-bearing source.
        edgeManifestRule('/Old-Page/', ['target_url' => '/new-page']),
        edgeManifestRule('/promo?utm=x', ['target_url' => 'https://example.test/p', 'response' => '302']),
        edgeManifestRule('/qs', ['target_url' => '/qs-target/?a=1', 'target_query_string' => ' ?ref=edge ']),
        // The URL generator roots a relative target and trims a trailing slash.
        edgeManifestRule('/trail', ['target_url' => '/new/']),
        edgeManifestRule('/relative', ['target_url' => 'about-us']),
        edgeManifestRule('/root', ['target_url' => '/']),
        // A url target ending in a literal `*` on an exact rule is used as written.
        edgeManifestRule('/star', ['target_url' => '/literal*']),
        // The first rule for a source wins, whatever its case; lower order beats stored order.
        edgeManifestRule('/dup', ['target_url' => '/first', 'order' => 10]),
        edgeManifestRule('/DUP', ['target_url' => '/second', 'order' => 20]),
        edgeManifestRule('/rev', ['target_url' => '/second', 'order' => 20]),
        edgeManifestRule('/REV', ['target_url' => '/first', 'order' => 10]),
        // A query-bearing source loses to an earlier plain one for the same path.
        edgeManifestRule('/pq', ['target_url' => '/pq-plain', 'order' => 10]),
        edgeManifestRule('/pq?x=1', ['target_url' => '/pq-query', 'order' => 20]),
        // A rule with no `enabled` key is enabled.
        array_diff_key(edgeManifestRule('/no-enabled-key', ['target_url' => '/enabled']), ['enabled' => true]),
        // Responses and targets the edge leaves to the origin.
        edgeManifestRule('/gone', ['response' => '410']),
        edgeManifestRule('/nf', ['response' => '404']),
        edgeManifestRule('/not-modified', ['target_url' => '/x', 'response' => '304']),
        edgeManifestRule('/entry-target', ['target_type' => 'entry', 'target_entry' => ['entry-1']]),
        edgeManifestRule('/empty-target', ['target_url' => '']),
        edgeManifestRule('/zero-target', ['target_url' => '0']),
        // Sources the engine can never match against a tidied path.
        edgeManifestRule('/bare?', ['target_url' => '/never']),
        edgeManifestRule('/a//b', ['target_url' => '/never']),
        // Windows: open now, closed, and start-only, each ahead of a plain rule.
        edgeManifestRule('/windowed', ['target_url' => '/w', 'valid_from' => '2000-01-01', 'valid_until' => '2999-12-31', 'order' => 10]),
        edgeManifestRule('/windowed', ['target_url' => '/w2', 'order' => 20]),
        edgeManifestRule('/expired', ['target_url' => '/e1', 'valid_from' => '2000-01-01', 'valid_until' => '2000-12-31', 'order' => 10]),
        edgeManifestRule('/expired', ['target_url' => '/e2', 'order' => 20]),
        edgeManifestRule('/from-only', ['target_url' => '/f1', 'valid_from' => '2000-01-01', 'order' => 10]),
        edgeManifestRule('/from-only', ['target_url' => '/f2', 'order' => 20]),
        // Rules the engine skips.
        edgeManifestRule('/disabled', ['target_url' => '/x', 'enabled' => false]),
        edgeManifestRule('/pending', ['target_url' => '/x', 'status' => 'pending']),
        // An exact rule that the untidy requests below would reach if the edge did not defer them.
        edgeManifestRule('/old/x/y', ['target_url' => '/exact-hit']),
        // Wildcards, in order.
        edgeManifestRule('/docs/*', ['target_url' => '/help/*', 'order' => 40]),
        edgeManifestRule('/cp/*', ['target_url' => '/not-the-cp', 'order' => 41]),
        edgeManifestRule('/old/*', ['target_url' => '/new/*', 'order' => 42]),
        edgeManifestRule('/blank/*', ['target_url' => '*', 'order' => 43]),
        edgeManifestRule('/proto/*', ['target_url' => '//cdn.example.test/*', 'order' => 44]),
        array_diff_key(edgeManifestRule('/no-key/*', ['target_url' => '/no-key-target', 'order' => 45]), ['use_regex' => false]),
        // A rooted remainder target that does not end in `/`.
        edgeManifestRule('/pre/*', ['target_url' => '/prefix-*', 'order' => 46]),
        edgeManifestRule('/docs/v1/*', ['target_url' => '/legacy', 'order' => 50]),
        edgeManifestRule('/news/*', ['target_url' => 'https://news.example.test/*', 'order' => 55]),
        // Regex rules with no usable source, which the engine skips rather than stops at.
        edgeManifestRule('', ['use_regex' => true, 'order' => 56]),
        edgeManifestRule('0', ['use_regex' => true, 'order' => 56]),
        // A regex, then rules that overlap what it matches.
        edgeManifestRule('^/archive/(\d+)', ['use_regex' => true, 'target_url' => '/a/$1', 'order' => 60]),
        edgeManifestRule('/archive/*', ['target_url' => '/wrong', 'order' => 70]),
        edgeManifestRule('*', ['target_url' => '/catch-all', 'order' => 80]),
        // An exact rule ordered after every pattern: the exact pass still reaches it.
        edgeManifestRule('/late', ['target_url' => '/late-target', 'order' => 90]),
    ];
}

/** Store the fixture rules on the default site's `redirects` global. */
function edgeManifestSeed(): void
{
    $global = GlobalSet::findByHandle('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);

    $vars = $global->in('default');
    Assert::assertInstanceOf(Variables::class, $vars);
    $vars->data(['redirects' => edgeManifestRules()]);
    $vars->save();
}

/**
 * The fixture manifest, built from the stored rules as the command builds it.
 *
 * @return array<string, mixed>
 */
function edgeManifest(): array
{
    $global = GlobalSet::findByHandle('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);

    return app(EdgeRedirectManifestBuilder::class)->forSite($global, 'default', 'default');
}

/**
 * What the real serving path does for a request that is about to render a 404:
 * the resolver, which answers with the rule's redirect or error page.
 *
 * @param  string  $requestUri  The request URI, query string included.
 * @return array{status: int, location: string|null}
 */
function edgeManifestServe(string $requestUri): array
{
    $request = Request::create($requestUri);
    app()->instance('request', $request);

    $response = app(NotFoundRedirectResolver::class)->responseFor($request);

    if ($response === null) {
        return ['status' => 200, 'location' => null];
    }

    return [
        'status' => $response->getStatusCode(),
        'location' => $response->isRedirection() ? $response->headers->get('Location') : null,
    ];
}

/**
 * What an edge following the manifest's `semantics`, step by step, does for a request.
 *
 * @param  array<string, mixed>  $manifest  The manifest.
 * @param  string  $requestUri  The request URI, query string included.
 * @return array{0: string, 1?: int, 2?: string} `['origin']` or `['redirect', status, location]`.
 */
function edgeManifestEvaluate(
    array $manifest,
    string $requestUri
): array {
    // request: an unparseable target, or an untidy path, goes to the origin
    $parts = parse_url($requestUri);

    if ($parts === false) {
        return ['origin'];
    }

    $rawPath = $parts['path'] ?? '/';

    if (str_contains($rawPath, '\\') || str_contains($rawPath, ':') || str_contains($rawPath, '//')) {
        return ['origin'];
    }

    $path = '/'.trim($rawPath, '/');
    $query = $parts['query'] ?? '';

    // reserved: routes the application answers itself
    foreach (edgeManifestArray($manifest['reserved_prefixes'], 'reserved_prefixes') as $prefix) {
        $prefix = edgeManifestString($prefix, 'reserved prefix');

        if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
            return ['origin'];
        }
    }

    // order + exact: the listed exact entries, first match wins
    $hit = null;

    foreach (edgeManifestArray($manifest['exact'], 'exact') as $entry) {
        /** @var array<string, mixed> $entry */
        $subject = $entry['with_query'] && $query !== '' ? $path.'?'.$query : $path;

        if (strtolower(edgeManifestString($entry['source'], 'source')) === strtolower($subject)) {
            $hit = $entry;

            break;
        }
    }

    // order + prefix: then the listed prefix entries
    if ($hit === null) {
        $candidate = strtolower($path === '/' ? $path : $path.'/');

        foreach (edgeManifestArray($manifest['prefix'], 'prefix') as $entry) {
            /** @var array<string, mixed> $entry */
            if (str_starts_with($candidate, strtolower(edgeManifestString($entry['base'], 'base')))) {
                $hit = $entry;

                break;
            }
        }
    }

    // origin: nothing matched, or an entry claims the request for the origin
    if ($hit === null || $hit['action'] === 'origin') {
        return ['origin'];
    }

    $location = edgeManifestString($hit['target'], 'target');

    // target: append the remainder in its original case
    if ($hit['append_remainder']) {
        $location .= ltrim(substr($path, strlen(edgeManifestString($hit['base'], 'base'))), '/');
    }

    // query: the stored query and, when forwarded, the request's own
    $joined = implode('&', array_filter(
        [edgeManifestString($hit['query_string'], 'query_string'), $hit['forward_query'] ? $query : ''],
        static fn (string $part): bool => $part !== '',
    ));

    if ($joined !== '') {
        $location .= (str_contains($location, '?') ? '&' : '?').$joined;
    }

    // location: root a remainder target that begins with "/"
    if ($hit['append_remainder'] && str_starts_with($location, '/')) {
        $position = strpos($location, '?');
        $before = $position === false ? $location : substr($location, 0, $position);
        $location = '/'.trim($before, '/').($position === false ? '' : substr($location, $position));
    }

    $status = $hit['status'];

    if (! is_int($status)) {
        throw new RuntimeException('A redirect entry carries a non-integer status.');
    }

    return ['redirect', $status, $location];
}

/**
 * A Location as an absolute URL on the test host, with an empty path read as "/".
 *
 * @param  string  $location  A rooted or absolute location.
 */
function edgeManifestAbsolute(string $location): string
{
    $absolute = str_starts_with($location, '/') && ! str_starts_with($location, '//')
        ? EDGE_MANIFEST_HOST.$location
        : $location;

    $parts = parse_url($absolute);

    if ($parts === false || ! isset($parts['host'])) {
        return $absolute;
    }

    return ($parts['scheme'] ?? '').'://'.$parts['host'].($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
}

it('never serves a redirect the engine would not serve identically', function (string $requestUri, string $expected): void {
    edgeManifestSeed();

    $edge = edgeManifestEvaluate(edgeManifest(), $requestUri);

    // Whatever the edge serves, the real serving path serves exactly that
    if ($edge[0] === 'redirect') {
        $engine = edgeManifestServe($requestUri);

        expect($engine['status'])->toBe($edge[1] ?? null)
            ->and(edgeManifestAbsolute(edgeManifestString($engine['location'], 'engine location')))
            ->toBe(edgeManifestAbsolute(edgeManifestString($edge[2] ?? null, 'edge location')));
    }

    // And the edge serves what it can rather than deferring everything
    expect($edge[0])->toBe($expected);
})->with([
    'exact, case and stored slash' => ['/old-page', 'redirect'],
    'exact, upper case with slash' => ['/OLD-PAGE/', 'redirect'],
    'exact, request query forwarded' => ['/old-page?x=1', 'redirect'],
    'query-bearing source' => ['/promo?utm=x', 'redirect'],
    'query-bearing source, other query' => ['/promo?utm=y', 'origin'],
    'stored query into a target with one' => ['/qs?z=2', 'redirect'],
    'target trailing slash trimmed' => ['/trail', 'redirect'],
    'relative target rooted' => ['/relative', 'redirect'],
    'root target' => ['/root', 'redirect'],
    'literal star target' => ['/star', 'redirect'],
    'first rule for a source' => ['/dup', 'redirect'],
    'lower order wins over stored order' => ['/rev', 'redirect'],
    'earlier plain source beats a query-bearing one' => ['/pq?x=1', 'redirect'],
    'no enabled key' => ['/no-enabled-key', 'redirect'],
    '410' => ['/gone', 'origin'],
    '404' => ['/nf', 'origin'],
    '304' => ['/not-modified', 'origin'],
    'entry target' => ['/entry-target', 'origin'],
    'empty url target' => ['/empty-target', 'origin'],
    'zero url target' => ['/zero-target', 'origin'],
    'bare query source never matches' => ['/bare', 'origin'],
    'doubled-slash source never matches' => ['/a/b', 'origin'],
    'windowed source, window open' => ['/windowed', 'origin'],
    'windowed source, window closed' => ['/expired', 'origin'],
    'window with only a start' => ['/from-only', 'origin'],
    'disabled' => ['/disabled', 'origin'],
    'unapproved' => ['/pending', 'origin'],
    'doubled slash in the request' => ['/old/x//y', 'origin'],
    'backslash in the request' => ['/old/x\\y', 'origin'],
    'colon in the request' => ['/old/a:b', 'origin'],
    'reserved control panel route' => ['/cp/users', 'origin'],
    'wildcard base itself' => ['/docs', 'redirect'],
    'wildcard remainder kept in case' => ['/docs/Guide/Intro', 'redirect'],
    'wildcard remainder with a query' => ['/docs/a/?b=1', 'redirect'],
    'earlier wildcard wins' => ['/docs/v1/x', 'redirect'],
    'wildcard to a bare star target' => ['/blank/x', 'origin'],
    'wildcard to a protocol-relative target' => ['/proto/x', 'origin'],
    'wildcard with no use_regex key' => ['/no-key/a', 'redirect'],
    'absolute remainder target' => ['/news/story?id=3', 'redirect'],
    'regex match' => ['/archive/42', 'origin'],
    'behind the regex' => ['/archive/about', 'origin'],
    'no rule' => ['/nothing', 'origin'],
    'exact rule ordered after the patterns' => ['/late', 'redirect'],
    'rooted remainder target not ending in a slash' => ['/pre/x', 'redirect'],
]);

it("serves the engine's exact Location for the redirects it produces", function (): void {
    edgeManifestSeed();
    $manifest = edgeManifest();

    // Spot-check the edge's own answers, so parity cannot pass on two identical wrong ones
    expect(edgeManifestEvaluate($manifest, '/OLD-PAGE/?x=1'))->toBe(['redirect', 301, '/new-page?x=1'])
        ->and(edgeManifestEvaluate($manifest, '/qs?z=2'))->toBe(['redirect', 301, '/qs-target?a=1&ref=edge&z=2'])
        ->and(edgeManifestEvaluate($manifest, '/trail'))->toBe(['redirect', 301, '/new'])
        ->and(edgeManifestEvaluate($manifest, '/relative'))->toBe(['redirect', 301, '/about-us'])
        ->and(edgeManifestEvaluate($manifest, '/pq?x=1'))->toBe(['redirect', 301, '/pq-plain?x=1'])
        ->and(edgeManifestEvaluate($manifest, '/docs'))->toBe(['redirect', 301, '/help'])
        ->and(edgeManifestEvaluate($manifest, '/docs/Guide/Intro'))->toBe(['redirect', 301, '/help/Guide/Intro'])
        ->and(edgeManifestEvaluate($manifest, '/news/story?id=3'))->toBe(['redirect', 301, 'https://news.example.test/story?id=3']);

    // And the real serving path produces the two a review once found diverging
    expect(edgeManifestServe('/trail')['location'])->toBe(EDGE_MANIFEST_HOST.'/new')
        ->and(edgeManifestServe('/docs')['location'])->toBe(EDGE_MANIFEST_HOST.'/help');
});

it('withholds the rules an edge cannot evaluate, and those shadowed behind them', function (): void {
    edgeManifestSeed();
    $manifest = edgeManifest();

    // The wildcards after the regex overlap what the regex matches, so none is emitted
    expect(collect(edgeManifestArray($manifest['prefix'], 'prefix'))->pluck('base')->all())
        ->toBe(['/docs/', '/cp/', '/old/', '/blank/', '/proto/', '/no-key/', '/pre/', '/docs/v1/', '/news/'])
        ->and($manifest['prefix_complete'])->toBeFalse()
        ->and($manifest['withheld'])->toBe([
            'disabled' => 1,
            'unapproved' => 1,
            // Only the regex that can match; the source-less ones are skipped as the engine skips them
            'regex' => 1,
            'validity_window' => 3,
            // The later rule for `/dup`, `/rev`, `/windowed`, `/expired` and `/from-only`,
            // and the two wildcards behind the regex
            'shadowed' => 7,
            'unmatchable' => 2,
        ]);

    // The windowed source is claimed for the origin, so no later rule answers it at the edge
    $windowed = collect(edgeManifestArray($manifest['exact'], 'exact'))->firstWhere('source', '/windowed');

    expect($windowed)->toBe(['source' => '/windowed', 'with_query' => false, 'action' => 'origin']);
});

it('states its version, site, reserved routes and every semantic rule a renderer needs', function (): void {
    edgeManifestSeed();
    $manifest = edgeManifest();

    expect(array_keys($manifest))->toBe(['version', 'site', 'semantics', 'reserved_prefixes', 'exact', 'prefix', 'prefix_complete', 'withheld'])
        ->and($manifest['version'])->toBe(EdgeRedirectManifestBuilder::VERSION)
        ->and($manifest['site'])->toBe('default')
        // Every route the application answers ahead of the 404 page the engine runs on
        ->and($manifest['reserved_prefixes'])->toBe(array_map(
            static fn (string $key): string => '/'.trim(edgeManifestString(config($key), $key), '/'),
            ['statamic.cp.route', 'statamic.routes.action', 'statamic.assets.image_manipulation.route'],
        ))
        ->and(array_keys(edgeManifestArray($manifest['semantics'], 'semantics')))
        ->toBe(['scope', 'reserved', 'request', 'order', 'exact', 'prefix', 'origin', 'target', 'query', 'location', 'prefix_complete']);

    // Each rule is stated, not left as a placeholder
    foreach (edgeManifestArray($manifest['semantics'], 'semantics') as $rule) {
        expect(edgeManifestString($rule, 'semantic rule'))->not->toBe('');
    }
});

it('is reproducible from the store alone', function (): void {
    edgeManifestSeed();

    // No clock, no randomness: the same rules give the same bytes
    expect(json_encode(edgeManifest()))->toBe(json_encode(edgeManifest()));
});

it("merges the default site's rules beneath the site's own, as the serving tag does", function (): void {
    // Two real localizations: this pins that the builder reads the engine's merged
    // list, whose merge rules the tag's own multisite tests already cover
    config()->set('statamic.system.multisite', true);
    Site::setSites([
        'default' => ['name' => 'Enterprise', 'url' => '/', 'locale' => 'en_US'],
        'news' => ['name' => 'News', 'url' => '/news/', 'locale' => 'en_US'],
    ]);

    $global = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->sites(['default', 'news']);
    $global->save();

    $rulesBySite = [
        'default' => [
            edgeManifestRule('/shared', ['target_url' => '/default-copy']),
            edgeManifestRule('/default-only', ['target_url' => '/from-default']),
        ],
        'news' => [edgeManifestRule('/shared', ['target_url' => '/site-own'])],
    ];

    foreach ($rulesBySite as $site => $rules) {
        $variables = $global->makeLocalization($site);
        Assert::assertInstanceOf(Variables::class, $variables);
        $variables->data(['redirects' => $rules]);
        $variables->save();
    }

    $manifest = app(EdgeRedirectManifestBuilder::class)->forSite($global, 'news', 'default');

    // The site's own rule wins for a shared source, and the default fills the rest
    expect(collect(edgeManifestArray($manifest['exact'], 'exact'))->pluck('target', 'source')->all())
        ->toBe(['/shared' => '/site-own', '/default-only' => '/from-default']);
});

it('writes the manifest for a site from the command, identically to standard output', function (): void {
    edgeManifestSeed();

    $path = tempnam(sys_get_temp_dir(), 'edge-manifest-');
    Assert::assertIsString($path);

    expect(Artisan::call('redirects:edge-manifest', ['site' => 'default', '--output' => $path]))->toBe(0);

    $written = file_get_contents($path);
    unlink($path);

    expect(Artisan::call('redirects:edge-manifest', ['site' => 'default']))->toBe(0)
        ->and(Artisan::output())->toBe($written);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(edgeManifestString($written, 'manifest'), true, flags: JSON_THROW_ON_ERROR);

    // The file is the builder's manifest, not a different reading of the store
    expect($decoded)->toBe(edgeManifest());
});

it('refuses an unknown site or global set rather than emit an empty manifest', function (): void {
    expect(Artisan::call('redirects:edge-manifest', ['site' => 'no-such-site']))->toBe(1)
        ->and(Artisan::call('redirects:edge-manifest', ['site' => 'default', '--global' => 'no-such-global']))->toBe(1);
});
