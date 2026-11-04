<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Facades\GlobalSet;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;

/**
 * How `EdgeRedirectManifestBuilder` compiles a rule list: the envelope around the
 * entries, the engine's evaluation order, which rules it withholds and why, and
 * the action each rule it keeps becomes. The rules are passed in directly, so each
 * case reads as the rule data it is about; the parity check against the serving
 * engine is the host application's.
 *
 * @command  vendor/bin/pest tests/Integration/Services/EdgeRedirectManifestBuilderTest.php
 */

/**
 * A rule redirecting a source to a URL with a 301, with the given fields added
 * or overridden. No `enabled` or `status` is stored unless given, as for a rule
 * saved before those fields existed.
 *
 * @param  string  $source  The rule's source.
 * @param  array<string, mixed>  $fields  Fields to add or override.
 * @return array<string, mixed> The rule.
 */
function edgeBuilderRule(string $source, array $fields = []): array
{
    return [
        'source' => $source,
        'target_type' => 'url',
        'target_url' => 'https://uams.edu/to',
        'response' => '301',
        ...$fields,
    ];
}

/**
 * The manifest for a rule list.
 *
 * @param  list<array<string, mixed>>  $rules  The rules.
 * @return array{version: int, site: string, semantics: array<string, string>, reserved_prefixes: list<string>, exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>, prefix_complete: bool, withheld: array<string, int>} The manifest.
 */
function edgeBuilderManifest(array $rules): array
{
    return app(EdgeRedirectManifestBuilder::class)->build($rules, 'default');
}

/**
 * The withheld counts, all zero but those given.
 *
 * @param  array<string, int>  $counts  The non-zero counts.
 * @return array<string, int> Every count, in the manifest's order.
 */
function edgeBuilderWithheld(array $counts = []): array
{
    return [
        'disabled' => 0,
        'unapproved' => 0,
        'regex' => 0,
        'validity_window' => 0,
        'shadowed' => 0,
        'unmatchable' => 0,
        ...$counts,
    ];
}

/**
 * The redirect action fields for a fixed 301.
 *
 * @param  string  $target  The emitted target.
 * @param  array<string, mixed>  $fields  Fields to override.
 * @return array<string, mixed> The action fields.
 */
function edgeBuilderRedirect(string $target, array $fields = []): array
{
    return [
        'action' => 'redirect',
        'status' => 301,
        'target' => $target,
        'append_remainder' => false,
        'query_string' => '',
        'forward_query' => true,
        ...$fields,
    ];
}

it('wraps an empty rule list in its version, site, reserved prefixes and every semantic rule', function (): void {
    $manifest = app(EdgeRedirectManifestBuilder::class)->build([], 'news', ['/cp']);

    expect($manifest['version'])->toBe(1)
        ->and($manifest['site'])->toBe('news')
        ->and($manifest['reserved_prefixes'])->toBe(['/cp'])
        ->and(array_keys($manifest['semantics']))->toBe([
            'scope', 'reserved', 'request', 'order', 'exact', 'prefix', 'origin', 'target', 'query', 'location', 'prefix_complete',
        ])
        ->and($manifest['exact'])->toBe([])
        ->and($manifest['prefix'])->toBe([])
        ->and($manifest['prefix_complete'])->toBeTrue()
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld());
});

it('names the host for a host, reserves nothing there, and scopes it differently from a site', function (): void {
    $host = app(EdgeRedirectManifestBuilder::class)->forHost('agec.org', [edgeBuilderRule('/a')]);
    $site = edgeBuilderManifest([]);

    expect($host['version'])->toBe(1)
        ->and($host['host'])->toBe('agec.org')
        ->and($host)->not->toHaveKey('site')
        ->and($host['reserved_prefixes'])->toBe([])
        ->and($host['semantics']['scope'])->not->toBe($site['semantics']['scope'])
        ->and(array_keys($host['semantics']))->toBe(array_keys($site['semantics']))
        ->and($host['exact'])->toBe([['source' => '/a', 'with_query' => false, ...edgeBuilderRedirect('https://uams.edu/to')]]);
});

it('reserves each route the application registers for a site, and none it does not', function (): void {
    config()->set('statamic.cp.enabled', true);
    config()->set('statamic.cp.route', '/admin/');
    config()->set('statamic.routes.action', '!');
    config()->set('statamic.assets.image_manipulation.route', '/');

    $builder = app(EdgeRedirectManifestBuilder::class);
    $global = GlobalSet::make('redirects');
    Assert::assertInstanceOf(GlobalSetContract::class, $global);

    expect($builder->forSite($global, 'default', null)['reserved_prefixes'])->toBe(['/admin', '/!']);

    config()->set('statamic.cp.enabled', false);
    config()->set('statamic.assets.image_manipulation.route', 'img');

    $manifest = $builder->forSite($global, 'default', null);

    expect($manifest['reserved_prefixes'])->toBe(['/!', '/img'])
        ->and($manifest['site'])->toBe('default');
});

it('evaluates rules by ascending order, an unordered rule as order 30, and keeps ties in stored order', function (): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/thirty-one', ['order' => 31]),
        edgeBuilderRule('/unordered'),
        edgeBuilderRule('/ten-first', ['order' => 10]),
        edgeBuilderRule('/twenty-nine', ['order' => 29]),
        edgeBuilderRule('/ten-second', ['order' => 10]),
        edgeBuilderRule('/thirty', ['order' => 30]),
    ]);

    expect(array_column($manifest['exact'], 'source'))
        ->toBe(['/ten-first', '/ten-second', '/twenty-nine', '/unordered', '/thirty', '/thirty-one']);
});

it('withholds what the engine skips or can never match, counts each once, and keeps reading past it', function (): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/disabled', ['order' => 1, 'enabled' => false]),
        edgeBuilderRule('/pending', ['order' => 2, 'status' => 'pending']),
        edgeBuilderRule('/old/*', ['order' => 3]),
        edgeBuilderRule('/ignored', ['order' => 4, 'source' => 5]),
        edgeBuilderRule('/a//b', ['order' => 5]),
        edgeBuilderRule('/a\\b', ['order' => 6]),
        edgeBuilderRule('/ends?', ['order' => 7]),
        edgeBuilderRule('/Dup', ['order' => 8]),
        edgeBuilderRule('/dup', ['order' => 9, 'target_url' => 'https://uams.edu/later']),
        edgeBuilderRule('/approved', ['order' => 10, 'enabled' => true, 'status' => 'approved']),
    ]);

    expect(array_column($manifest['exact'], 'source'))->toBe(['/Dup', '/approved'])
        ->and($manifest['exact'][0]['target'])->toBe('https://uams.edu/to')
        ->and(array_column($manifest['prefix'], 'base'))->toBe(['/old/'])
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld(['disabled' => 1, 'unapproved' => 1, 'shadowed' => 1, 'unmatchable' => 3]));
});

it('counts every withheld rule of a kind, not just the first', function (): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/off-1', ['enabled' => false]),
        edgeBuilderRule('/off-2', ['enabled' => false]),
        edgeBuilderRule('/off-3/*', ['enabled' => false]),
        edgeBuilderRule('/no-1', ['status' => 'rejected']),
        edgeBuilderRule('/no-2', ['status' => 'pending']),
        edgeBuilderRule('/x//1'),
        edgeBuilderRule('/x//2'),
        edgeBuilderRule('/s'),
        edgeBuilderRule('/s'),
        edgeBuilderRule('/S'),
        edgeBuilderRule('/w-1', ['valid_from' => '2026-01-01']),
        edgeBuilderRule('/w-2', ['valid_until' => '2026-12-31']),
    ]);

    expect($manifest['withheld'])->toBe(edgeBuilderWithheld([
        'disabled' => 3, 'unapproved' => 2, 'validity_window' => 2, 'shadowed' => 2, 'unmatchable' => 2,
    ]));
});

it('hands a windowed exact source to the origin, with every later rule for it', function (string $field, string $date): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/flu?season=1', [$field => $date]),
        edgeBuilderRule('/flu?season=1'),
        edgeBuilderRule('/after'),
    ]);

    expect($manifest['exact'])->toBe([
        ['source' => '/flu?season=1', 'with_query' => true, 'action' => 'origin'],
        ['source' => '/after', 'with_query' => false, ...edgeBuilderRedirect('https://uams.edu/to')],
    ])
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld(['validity_window' => 1, 'shadowed' => 1]));
})->with([
    'a start date only' => ['valid_from', '2026-01-01'],
    'an end date only' => ['valid_until', '2026-12-31'],
]);

it('treats a regex rule as a pattern whatever its source looks like', function (): void {
    $manifest = edgeBuilderManifest([edgeBuilderRule('/plain', ['use_regex' => true])]);

    expect($manifest['exact'])->toBe([])
        ->and($manifest['prefix_complete'])->toBeFalse()
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld(['regex' => 1]));
});

it('lists patterns in order and stops at the first one the edge cannot evaluate, withholding every pattern after it', function (array $stopper, string $reason): void {
    /** @var array<string, mixed> $stopper */
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/exact-first', ['order' => 1]),
        edgeBuilderRule('/off/*', ['order' => 2, 'enabled' => false]),
        edgeBuilderRule('/kept/*', ['order' => 3]),
        edgeBuilderRule('/between', ['order' => 4]),
        edgeBuilderRule('/slashed//*', ['order' => 5]),
        edgeBuilderRule('/also-kept/*', ['order' => 6]),
        ['order' => 7, ...$stopper],
        edgeBuilderRule('/late-1/*', ['order' => 8]),
        edgeBuilderRule('/late-2/*', ['order' => 9]),
    ]);

    expect(array_column($manifest['prefix'], 'base'))->toBe(['/kept/', '/also-kept/'])
        ->and(array_column($manifest['exact'], 'source'))->toBe(['/exact-first', '/between'])
        ->and($manifest['prefix_complete'])->toBeFalse()
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld(['disabled' => 1, $reason => 1, 'shadowed' => 2, 'unmatchable' => 1]));
})->with([
    'a regex' => [edgeBuilderRule('^/re', ['use_regex' => true]), 'regex'],
    'a windowed pattern' => [edgeBuilderRule('/window/*', ['valid_until' => '2026-12-31']), 'validity_window'],
]);

it('ignores a regex rule whose source is empty, zero or not a string, and keeps reading patterns', function (mixed $source): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/x', ['order' => 1, 'source' => $source, 'use_regex' => true]),
        edgeBuilderRule('/after/*', ['order' => 2]),
    ]);

    expect(array_column($manifest['prefix'], 'base'))->toBe(['/after/'])
        ->and($manifest['prefix_complete'])->toBeTrue()
        ->and($manifest['withheld'])->toBe(edgeBuilderWithheld());
})->with([
    'empty' => [''],
    'zero' => ['0'],
    'not a string' => [7],
]);

it('sends an error or unrecognized response to the origin, and redirects each edge status', function (mixed $response, ?int $status): void {
    $exact = edgeBuilderManifest([edgeBuilderRule('/a', ['response' => $response])])['exact'][0];

    expect($exact)->toBe($status === null
        ? ['source' => '/a', 'with_query' => false, 'action' => 'origin']
        : ['source' => '/a', 'with_query' => false, ...edgeBuilderRedirect('https://uams.edu/to', ['status' => $status])]);
})->with([
    '300' => ['300', null],
    '301' => ['301', 301],
    '302' => ['302', 302],
    '303' => ['303', 303],
    '304' => ['304', null],
    '306' => ['306', null],
    '307' => ['307', 307],
    '308' => ['308', 308],
    '309' => ['309', null],
    '404' => ['404', null],
    'an integer redirect' => [302, 302],
    'an integer error' => [410, null],
    'not a number' => ['gone', null],
    'none' => [null, null],
]);

it('redirects only a URL target with a location, and roots a fixed one', function (array $fields, ?string $target): void {
    /** @var array<string, mixed> $fields */
    $exact = edgeBuilderManifest([edgeBuilderRule('/a', $fields)])['exact'][0];

    expect($exact)->toBe($target === null
        ? ['source' => '/a', 'with_query' => false, 'action' => 'origin']
        : ['source' => '/a', 'with_query' => false, ...edgeBuilderRedirect($target)]);
})->with([
    'an entry target' => [['target_type' => 'entry', 'target_url' => '/stale'], null],
    'an empty URL' => [['target_url' => ''], null],
    'a zero URL' => [['target_url' => '0'], null],
    'no URL' => [['target_url' => null], null],
    'a relative path' => [['target_url' => 'new/home/'], '/new/home'],
    'a rooted path with a query' => [['target_url' => '/new/?a=1'], '/new?a=1'],
    'a fixed target ending in a star' => [['target_url' => '/new/*'], '/new/*'],
]);

it('appends the remainder to a starred target on a pattern only when the edge can root it', function (string $target, ?string $emitted): void {
    $prefix = edgeBuilderManifest([edgeBuilderRule('/old/*', ['target_url' => $target])])['prefix'][0];

    expect($prefix)->toBe($emitted === null
        ? ['base' => '/old/', 'action' => 'origin']
        : ['base' => '/old/', ...edgeBuilderRedirect($emitted, ['append_remainder' => true])]);
})->with([
    'a rooted path' => ['/new/*', '/new/'],
    'an https URL' => ['https://uams.edu/new/*', 'https://uams.edu/new/'],
    'an http URL' => ['http://uams.edu/*', 'http://uams.edu/'],
    'a protocol-relative URL' => ['//cdn.uams.edu/*', null],
    'a relative path' => ['new/*', null],
    'another scheme' => ['ftp://uams.edu/*', null],
    'a scheme not at the start' => ['xhttps://uams.edu/*', null],
    'nothing before the star' => ['*', null],
]);

it('roots a fixed target on a pattern as it roots any other', function (): void {
    $prefix = edgeBuilderManifest([edgeBuilderRule('/old/*', ['target_url' => 'new/'])])['prefix'][0];

    expect($prefix)->toBe(['base' => '/old/', ...edgeBuilderRedirect('/new')]);
});

it("carries the rule's trimmed query string, and forwards the request's only when the source names none", function (): void {
    $manifest = edgeBuilderManifest([
        edgeBuilderRule('/a', ['target_query_string' => " ?&utm=1 \n"]),
        edgeBuilderRule('/b?c=1'),
    ]);

    expect($manifest['exact'][0])->toMatchArray(['query_string' => 'utm=1', 'forward_query' => true])
        ->and($manifest['exact'][1])->toMatchArray(['with_query' => true, 'forward_query' => false]);
});

it('roots a location as the URL generator does, leaving an absolute URL alone', function (string $location, string $rooted): void {
    expect(EdgeRedirectManifestBuilder::rooted($location))->toBe($rooted);
})->with([
    'an absolute URL' => ['https://uams.edu/a/', 'https://uams.edu/a/'],
    'a relative path' => ['a/b/', '/a/b'],
    'a rooted path with a query' => ['/a/b/?q=1/', '/a/b?q=1/'],
    'a bare query' => ['?q=1', '/?q=1'],
    'a query at the first question mark' => ['a?b?c', '/a?b?c'],
    'the root' => ['/', '/'],
]);
