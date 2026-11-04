<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Tags\Context;
use Statamic\Tags\Parameters;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

uses(MockeryPHPUnitIntegration::class);

/**
 * Unit tests for the FindRedirectRule tag.
 *
 * Tests the redirect rule finding functionality, including exact match
 * redirects, regex pattern redirects, order sorting, enabled/disabled
 * redirects, missing global sets, different site handles, bounded-PCRE
 * (ReDoS) limits, and context-based URI retrieval.
 *
 * The rule SELECTION these tests exercise now lives in UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher,
 * extracted in UAMS-Web/uams-statamic-archive#1765 so the link-health resolver cannot disagree with what this tag serves. The
 * tests are unchanged and still drive it through the tag.
 *
 * Mutation, measured in this package on Windows with PCOV through this file, the multisite and
 * mutation tests, and `TagParamTest`: the matcher's covered lines yield 197 mutants with two
 * untested, `BooleanAndToBooleanOr` on the merge's inner condition and `RemoveFunctionCall` on
 * the recursion-limit `ini_set()`. Each carries its reason beside the code
 * (UAMS-Web/uams-statamic-archive#2298). Fifteen more are uncovered: the four PCRE bound
 * constants, the `'0'` pattern skip, the missing-localization early return, and the
 * substitution-failure fallback. No test in the host application reached those branches either.
 *
 * @command  vendor/bin/pest tests/Integration/Tags/FindRedirectRuleTest.php
 */

/**
 * Create a FindRedirectRule tag instance with mocked dependencies.
 *
 * Builds the common context and parameters mocks and wires them onto a fresh
 * tag instance via reflection (the properties are private on Statamic\Tags\Tags).
 *
 * @param  array<string, mixed>  $contextData  Context data (e.g. ['current_uri' => '/test'])
 * @param  array<string, mixed>  $params  Tag parameters (e.g. ['handle' => 'redirects'])
 */
function findRedirectRuleTag(array $contextData = [], array $params = []): FindRedirectRule
{
    // Create a mock context that returns values from contextData.
    $context = Mockery::mock(Context::class);
    $context->shouldReceive('get')
        ->andReturnUsing(fn (string $key) => $contextData[$key] ?? null);

    // Create a mock parameters object that returns values from params.
    $parameters = Mockery::mock(Parameters::class);
    $parameters->shouldReceive('get')
        ->andReturnUsing(fn (string $key, mixed $default = null) => $params[$key] ?? $default);

    // Create the tag instance.
    $tag = new FindRedirectRule;

    // Set the private context and params properties using reflection.
    $reflection = new ReflectionClass($tag);

    $contextProperty = $reflection->getProperty('context');
    $contextProperty->setValue($tag, $context);

    $paramsProperty = $reflection->getProperty('params');
    $paramsProperty->setValue($tag, $parameters);

    return $tag;
}

/**
 * Create a mock global set whose variables expose the given redirects.
 *
 * @param  string  $siteHandle  The site handle expected on the in() call
 * @param  array<int, array<string, mixed>>  $redirects  The redirects array
 */
function findRedirectRuleMockGlobalSet(string $siteHandle, array $redirects): GlobalSetContract&MockInterface
{
    // Create a mock Variables object that returns the redirects.
    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')
        ->with('redirects', [])
        ->once()
        ->andReturn($redirects);

    // Create a mock GlobalSet object that returns the variables for the site.
    /** @var GlobalSetContract&MockInterface $globalSet */
    $globalSet = Mockery::mock(GlobalSetContract::class);
    $globalSet->shouldReceive('in')
        ->with($siteHandle)
        ->once()
        ->andReturn($variables);

    return $globalSet;
}

/**
 * Create a mock global set exposing distinct redirect lists for a queried
 * (non-default) site and the default site — the read-time merge path (UAMS-Web/uams-statamic-archive#1110).
 *
 * @param  string  $siteHandle  The queried (non-default) site handle
 * @param  array<int, array<string, mixed>>  $siteRedirects  The queried site's own list
 * @param  string  $defaultHandle  The default site handle
 * @param  array<int, array<string, mixed>>  $defaultRedirects  The default site's shared list (merged beneath)
 */
function findRedirectRuleMockGlobalSetMultisite(
    string $siteHandle,
    array $siteRedirects,
    string $defaultHandle,
    array $defaultRedirects
): GlobalSetContract&MockInterface {
    // The queried site's localization.
    $siteVariables = Mockery::mock(Variables::class);
    $siteVariables->shouldReceive('get')
        ->with('redirects', [])
        ->once()
        ->andReturn($siteRedirects);

    // The default site's localization (holds the shared, non-localizable list).
    $defaultVariables = Mockery::mock(Variables::class);
    $defaultVariables->shouldReceive('get')
        ->with('redirects', [])
        ->once()
        ->andReturn($defaultRedirects);

    /** @var GlobalSetContract&MockInterface $globalSet */
    $globalSet = Mockery::mock(GlobalSetContract::class);
    $globalSet->shouldReceive('in')
        ->with($siteHandle)
        ->once()
        ->andReturn($siteVariables);
    $globalSet->shouldReceive('in')
        ->with($defaultHandle)
        ->once()
        ->andReturn($defaultVariables);

    return $globalSet;
}

/**
 * Create a mock site object with the given handle.
 */
function findRedirectRuleMockSite(string $handle): MockInterface
{
    $site = Mockery::mock();
    $site->shouldReceive('handle')
        ->once()
        ->andReturn($handle);

    return $site;
}

/**
 * Set up mocks for the GlobalSet and Site facades.
 *
 * @param  string  $globalHandle  The global set handle to mock
 * @param  GlobalSetContract|null  $globalSet  The mock global set, or null if not found
 * @param  string  $siteHandle  The current/queried site handle to mock
 * @param  string  $defaultHandle  The default site handle to mock (drives the read-time merge)
 */
function findRedirectRuleFacadeMocks(
    string $globalHandle,
    ?GlobalSetContract $globalSet,
    string $siteHandle = 'default',
    string $defaultHandle = 'default'
): void {
    // Mock the GlobalSet facade lookup.
    GlobalSet::shouldReceive('findByHandle')
        ->with($globalHandle)
        ->once()
        ->andReturn($globalSet);

    // Mock the Site facade to return a site with the given handle.
    $site = findRedirectRuleMockSite($siteHandle);
    Site::shouldReceive('current')
        ->once()
        ->andReturn($site);

    // The tag also reads the default site to merge its shared (non-localizable)
    // redirects beneath the queried site's list (UAMS-Web/uams-statamic-archive#1110). Lenient (zero-or-more):
    // the default read is skipped when the global is absent, and no merge happens
    // when the queried site IS the default.
    $defaultSite = Mockery::mock();
    $defaultSite->shouldReceive('handle')
        ->andReturn($defaultHandle);
    Site::shouldReceive('default')
        ->andReturn($defaultSite);
}

/**
 * Execute the tag's index() method and return the result.
 *
 * @param  string|null  $currentUri  The current URI for the context, or null to omit it (fallback test)
 * @param  array<string, mixed>  $params  Tag parameters
 * @return list<array<string, mixed>>
 */
function findRedirectRuleExecute(?string $currentUri = null, array $params = []): array
{
    // Add the current URI to the context data when provided.
    $contextData = [];
    if ($currentUri !== null) {
        $contextData['current_uri'] = $currentUri;
    }

    return findRedirectRuleTag($contextData, $params)->index();
}

/**
 * Create a standard redirect array structure with overridable defaults.
 *
 * @param  string  $source  The source URL or regex pattern
 * @param  array<string, mixed>  $overrides  Values to override defaults
 * @return array<string, mixed>
 */
function findRedirectRuleRedirect(string $source, array $overrides = []): array
{
    $redirect = [
        'id' => 'redirect-1',
        'source' => $source,
        'target_type' => 'url',
        'target_url' => '/new-page',
        'response' => '301',
        'order' => 30,
        'use_regex' => false,
        'enabled' => true,
        'type' => 'redirect',
    ];

    return array_merge($redirect, $overrides);
}

/**
 * Build a rule with one key REMOVED, so the matcher's `??` fallback for that key is the thing
 * under test. The default fixture supplies every key the matcher reads, so no test in this
 * suite reached any of those fallbacks until this helper existed.
 *
 * @param  string  $source  The source URL or regex pattern
 * @param  string  $drop  The key to remove, so its `??` fallback is the thing under test
 * @param  array<string, mixed>  $overrides  Values to override defaults
 * @return array<string, mixed> The rule, without the dropped key
 */
function findRedirectRuleKeylessRedirect(string $source, string $drop, array $overrides = []): array
{
    $redirect = findRedirectRuleRedirect($source, $overrides);

    unset($redirect[$drop]);

    return $redirect;
}

/**
 * Build a rule with NO `order` key at all, so the `?? 30` fallback in the comparator is the
 * thing under test. `findRedirectRuleRedirect()` supplies `order` in its defaults, so a rule
 * built through it can never exercise that branch.
 *
 * @param  string  $source  The source URL or regex pattern
 * @param  array<string, mixed>  $overrides  Values to override defaults
 * @return array<string, mixed> The rule, without its `order` key
 */
function findRedirectRuleUnorderedRedirect(string $source, array $overrides = []): array
{
    $redirect = findRedirectRuleRedirect($source, $overrides);

    unset($redirect['order']);

    return $redirect;
}

it('treats a rule with no enabled key as enabled', function (): void {
    // `findRedirectRuleRedirect()` supplies `enabled`, so no fixture had ever reached the
    // `?? true` fallback. A legacy globals-editor or importer row can omit it entirely, and
    // omitting it must not silently disable the rule.
    $redirects = [
        findRedirectRuleKeylessRedirect('/no-enabled-key', 'enabled', ['target_url' => '/still-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/no-enabled-key'), '0.target_url'))->toEqual('/still-served');
});

it('treats a pattern rule with no enabled key as enabled', function (): void {
    // The same guard is written twice — once per matching pass — so killing it in the exact
    // pass says nothing about the pattern pass. This is the second copy.
    $redirects = [
        findRedirectRuleKeylessRedirect('/wild/*', 'enabled', [
            'use_regex' => false,
            'target_url' => '/pattern-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/wild/thing'), '0.target_url'))->toEqual('/pattern-served');
});

it('treats a rule with no use_regex key as a non-regex rule', function (): void {
    // The `?? false` fallback. A source carrying regex special characters must be matched
    // literally when the key is absent, not compiled as a pattern.
    $redirects = [
        findRedirectRuleKeylessRedirect('/a+b', 'use_regex', ['target_url' => '/literal']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // Matches the literal path...
    expect(data_get(findRedirectRuleExecute('/a+b'), '0.target_url'))->toEqual('/literal');
});

it('sorts a lower stored order ahead of a higher one', function (): void {
    // Both match; only `order` decides. The lower-numbered rule is the one served.
    $redirects = [
        findRedirectRuleRedirect('/both', ['id' => 'high', 'order' => 40, 'target_url' => '/from-high']),
        findRedirectRuleRedirect('/both', ['id' => 'low', 'order' => 10, 'target_url' => '/from-low']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/both'), '0.target_url'))->toEqual('/from-low');
});

it('sorts on the stored order either side of the unordered default', function (): void {
    // Straddles 30 in the other direction, so a comparator that substitutes a constant for
    // EITHER side is caught rather than only the side the previous case exercises.
    $redirects = [
        findRedirectRuleRedirect('/straddle', ['id' => 'just-under', 'order' => 29, 'target_url' => '/from-29']),
        findRedirectRuleRedirect('/straddle', ['id' => 'just-over', 'order' => 31, 'target_url' => '/from-31']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/straddle'), '0.target_url'))->toEqual('/from-29');
});

it('treats a rule with no order as 30, ranking it against explicit siblings', function (): void {
    // The `?? 30` fallback, exercised from both sides: the unordered rule must lose to 29 and
    // beat 31. A fallback of 29 or 31 instead of 30 flips one of the two.
    $beatsIt = [
        findRedirectRuleUnorderedRedirect('/fallback', ['id' => 'none', 'target_url' => '/from-none']),
        findRedirectRuleRedirect('/fallback', ['id' => 'under', 'order' => 29, 'target_url' => '/from-29']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $beatsIt));

    expect(data_get(findRedirectRuleExecute('/fallback'), '0.target_url'))->toEqual('/from-29');

    $losesToIt = [
        findRedirectRuleUnorderedRedirect('/fallback', ['id' => 'none', 'target_url' => '/from-none']),
        findRedirectRuleRedirect('/fallback', ['id' => 'over', 'order' => 31, 'target_url' => '/from-31']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $losesToIt));

    expect(data_get(findRedirectRuleExecute('/fallback'), '0.target_url'))->toEqual('/from-none');
});

it('finds an exact match redirect', function (): void {
    $currentUri = '/old-page';

    $redirects = [
        findRedirectRuleRedirect('/old-page'),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual('/old-page');
    expect(data_get($result, '0.target_url'))->toEqual('/new-page');
});

it('finds a regex match redirect', function (): void {
    $currentUri = '/blog/2024/my-post';

    $redirects = [
        findRedirectRuleRedirect('/old-page'),
        findRedirectRuleRedirect('/^\/blog\/\d{4}\/.+$/', [
            'id' => 'redirect-2',
            'target_url' => '/articles',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual('/^\/blog\/\d{4}\/.+$/');
    expect(data_get($result, '0.use_regex'))->toEqual(true);
    expect(data_get($result, '0.target_url'))->toEqual('/articles');
});

it('yields no match for a catastrophic redos pattern and finishes quickly', function (): void {
    // Classic nested-quantifier pattern against a moderate subject; bounded PCRE
    // limits should abort matching before ReDoS dominates the request.
    $currentUri = str_repeat('a', 40).'b';

    $redirects = [
        findRedirectRuleRedirect('#(a+)+$#', [
            'id' => 'redirect-redos',
            'target_url' => '/evil-target',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $start = microtime(true);
    $result = findRedirectRuleExecute($currentUri);
    $elapsedSeconds = microtime(true) - $start;

    expect($result)->toBeEmpty();
    expect($elapsedSeconds)->toBeLessThan(2.0, 'Regex evaluation should finish quickly under PCRE limits.');
});

it('skips regex redirects when the pattern exceeds the byte limit', function (): void {
    $pattern = str_repeat('x', 1025);

    $currentUri = '/any';

    $redirects = [
        findRedirectRuleRedirect($pattern, [
            'id' => 'redirect-long-pattern',
            'target_url' => '/target',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toBeEmpty();
});

it('skips regex redirects when the uri exceeds the subject byte limit', function (): void {
    $currentUri = '/'.str_repeat('a', 4096);

    expect(strlen($currentUri))->toBeGreaterThan(4096);

    $redirects = [
        findRedirectRuleRedirect('/.+/', [
            'id' => 'redirect-catch-all',
            'target_url' => '/target',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toBeEmpty();
});

it('prioritizes an exact match over a regex match', function (): void {
    // Both an exact and a regex redirect target the same URI; the exact match
    // must win for backwards compatibility.
    $currentUri = '/specific-page';

    $redirects = [
        findRedirectRuleRedirect('/^\/specific-page$/', [
            'id' => 'redirect-1',
            'target_url' => '/regex-target',
            'order' => 20,
            'use_regex' => true,
        ]),
        findRedirectRuleRedirect('/specific-page', [
            'id' => 'redirect-2',
            'target_url' => '/exact-target',
            'order' => 30,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual('/specific-page');
    expect(data_get($result, '0.use_regex'))->toEqual(false);
    expect(data_get($result, '0.target_url'))->toEqual('/exact-target');
});

it('skips disabled redirects', function (): void {
    $currentUri = '/old-page';

    $redirects = [
        findRedirectRuleRedirect('/old-page', ['enabled' => false]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toBeEmpty();
});

it('keeps scanning past a disabled redirect to a later match', function (): void {
    // ---
    // Kills ContinueToBreak on the `enabled` guard in the literal pass
    // (RedirectRuleMatcher line 114). The existing 'skips disabled redirects'
    // test cannot: with a single disabled rule, `continue` and `break` both
    // end the loop with nothing found, so the mutant is indistinguishable.
    //
    // Two rules are required, the skipped one FIRST. Under `break` the scan
    // stops at the disabled rule and never reaches the match, so this returns
    // empty; under `continue` it goes on and serves /old-page.
    //
    // Verified by hand-mutating line 114 to `break;` and running this file:
    // red here, green with `continue;`.
    // ---
    $redirects = [
        findRedirectRuleRedirect('/disabled-first', ['enabled' => false, 'order' => 10]),
        findRedirectRuleRedirect('/old-page', ['order' => 20, 'target_url' => '/reached-anyway']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('keeps scanning past an unapproved redirect to a later match', function (): void {
    // Kills ContinueToBreak on the approval guard in the literal pass (line 127).
    // Same construction as the disabled case: the skipped rule must come FIRST,
    // or `break` and `continue` are indistinguishable.
    $redirects = [
        findRedirectRuleRedirect('/pending-first', ['status' => 'pending', 'order' => 10]),
        findRedirectRuleRedirect('/old-page', ['order' => 20, 'target_url' => '/reached-anyway']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('keeps scanning past a disabled pattern to a later pattern match', function (): void {
    // Kills ContinueToBreak on the `enabled` guard in the PATTERN pass (line 165).
    // The literal pass has its own copy of these guards, so a literal fixture
    // cannot reach this one — both rules must be pattern sources.
    $redirects = [
        findRedirectRuleRedirect('^/disabled-pattern/(.*)$', [
            'use_regex' => true, 'enabled' => false, 'order' => 10,
        ]),
        findRedirectRuleRedirect('^/old-page/(.*)$', [
            'use_regex' => true, 'order' => 20, 'target_url' => '/reached-anyway',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page/thing');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('keeps scanning past an unapproved pattern to a later pattern match', function (): void {
    // Kills ContinueToBreak on the approval guard in the PATTERN pass (line 178).
    $redirects = [
        findRedirectRuleRedirect('^/pending-pattern/(.*)$', [
            'use_regex' => true, 'status' => 'pending', 'order' => 10,
        ]),
        findRedirectRuleRedirect('^/old-page/(.*)$', [
            'use_regex' => true, 'order' => 20, 'target_url' => '/reached-anyway',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page/thing');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('keeps scanning past a pattern that cannot compile to a later pattern match', function (): void {
    // Kills ContinueToBreak on the compile guard (line 220). An unterminated
    // group cannot compile either as stored or wrapped, so the matcher skips it
    // — and must go on rather than abandon the scan.
    $redirects = [
        findRedirectRuleRedirect('^/broken/(unclosed', [
            'use_regex' => true, 'order' => 10,
        ]),
        findRedirectRuleRedirect('^/old-page/(.*)$', [
            'use_regex' => true, 'order' => 20, 'target_url' => '/reached-anyway',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page/thing');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('keeps scanning past a non-matching pattern to a later pattern match', function (): void {
    // Kills ContinueToBreak on the match guard (line 232). This is the ordinary
    // case and the one most likely to be assumed covered: a pattern that compiles
    // fine and simply does not match the request.
    $redirects = [
        findRedirectRuleRedirect('^/never-matches/(.*)$', [
            'use_regex' => true, 'order' => 10,
        ]),
        findRedirectRuleRedirect('^/old-page/(.*)$', [
            'use_regex' => true, 'order' => 20, 'target_url' => '/reached-anyway',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-page/thing');

    expect($result)->not->toBeEmpty();
    expect(data_get($result, '0.target_url'))->toEqual('/reached-anyway');
});

it('serves a redirect explicitly marked approved', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old-page', ['status' => 'approved']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBe($redirects);
});

it('serves a legacy redirect that carries no status key (grandfathered, #1357)', function (): void {
    // Legacy globals-editor + WordPress-importer rows predate the status field
    // and must keep serving exactly as before.
    $redirects = [
        findRedirectRuleRedirect('/old-page'),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBe($redirects);
});

it('does not serve a redirect awaiting approval', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old-page', ['status' => 'pending']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBeEmpty();
});

it('does not serve a rejected redirect', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old-page', ['status' => 'rejected']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBeEmpty();
});

it('does not serve a pending regex redirect', function (): void {
    // The approval gate applies on the regex path too, not just exact matches.
    $redirects = [
        findRedirectRuleRedirect('#^/old-.*#', [
            'use_regex' => true,
            'status' => 'pending',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBeEmpty();
});

it('serves an approved regex redirect', function (): void {
    $redirects = [
        findRedirectRuleRedirect('#^/old-.*#', [
            'use_regex' => true,
            'status' => 'approved',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    expect(findRedirectRuleExecute('/old-page'))->toBe($redirects);
});

it('sorts redirects by order field', function (): void {
    // Multiple redirects match; the one with the lowest order value wins.
    $currentUri = '/test-page';

    $redirects = [
        findRedirectRuleRedirect('/test-page', [
            'id' => 'redirect-1',
            'target_url' => '/higher-order',
            'order' => 50,
        ]),
        findRedirectRuleRedirect('/test-page', [
            'id' => 'redirect-2',
            'target_url' => '/lower-order',
            'order' => 20,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.order'))->toEqual(20);
    expect(data_get($result, '0.target_url'))->toEqual('/lower-order');
});

it('returns an empty array when no redirect matches', function (): void {
    $currentUri = '/non-existent-page';

    $redirects = [
        findRedirectRuleRedirect('/other-page', ['target_url' => '/target']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toBeEmpty();
});

it('returns an empty array when the global set does not exist', function (): void {
    findRedirectRuleFacadeMocks('redirects', null);

    $result = findRedirectRuleExecute('/test');

    expect($result)->toBeEmpty();
});

it('respects the custom global handle parameter', function (): void {
    $currentUri = '/old-page';

    $redirects = [
        findRedirectRuleRedirect('/old-page'),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('custom-redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri, ['handle' => 'custom-redirects']);

    expect($result)->toHaveCount(1);
});

it('respects the custom site handle parameter', function (): void {
    $currentUri = '/old-page';

    $redirects = [
        findRedirectRuleRedirect('/old-page'),
    ];

    // Queried site is also the default here, so the read is single-site (no merge).
    $globalSet = findRedirectRuleMockGlobalSet('custom-site', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet, 'custom-site', 'custom-site');

    $result = findRedirectRuleExecute($currentUri, ['in' => 'custom-site']);

    expect($result)->toHaveCount(1);
});

it('retrieves the uri from the context when available', function (): void {
    $currentUri = '/context-uri';

    $redirects = [
        findRedirectRuleRedirect('/context-uri', ['target_url' => '/target']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute($currentUri);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual('/context-uri');
});

it('serves a default-site redirect on a non-default site via the read-time merge', function (): void {
    // The queried non-default site has no list of its own; the shared redirect
    // edited on the default site must still serve there (UAMS-Web/uams-statamic-archive#1110). A raw per-site
    // read would return [] here and 404.
    $currentUri = '/shared-old';

    $globalSet = findRedirectRuleMockGlobalSetMultisite(
        'health',
        [],
        'default',
        [findRedirectRuleRedirect('/shared-old', ['target_url' => '/shared-new'])],
    );
    findRedirectRuleFacadeMocks('redirects', $globalSet, 'health', 'default');

    $result = findRedirectRuleExecute($currentUri, ['in' => 'health']);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual('/shared-old');
    expect(data_get($result, '0.target_url'))->toEqual('/shared-new');
});

it('lets a non-default site redirect win over a same-source default redirect', function (): void {
    // Both the queried site and the default site define '/dup'. The queried
    // site's entry must win and the default's same-source row must be dropped —
    // even though the default's lower order would otherwise sort it first.
    $currentUri = '/dup';

    $globalSet = findRedirectRuleMockGlobalSetMultisite(
        'health',
        [findRedirectRuleRedirect('/dup', ['id' => 'site', 'target_url' => '/site-target', 'order' => 30])],
        'default',
        [findRedirectRuleRedirect('/dup', ['id' => 'default', 'target_url' => '/default-target', 'order' => 10])],
    );
    findRedirectRuleFacadeMocks('redirects', $globalSet, 'health', 'default');

    $result = findRedirectRuleExecute($currentUri, ['in' => 'health']);

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/site-target');
});

it('falls back to the request path when the context uri is unavailable', function (): void {
    $requestPath = 'request-path';
    $currentUri = '/request-path';

    $redirects = [
        findRedirectRuleRedirect('/request-path', ['target_url' => '/target']),
    ];

    // Bind a request whose path drives the fallback URI resolution.
    app()->instance('request', Request::create("/{$requestPath}"));

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    // Build the tag WITHOUT current_uri in context to exercise the fallback.
    $tag = findRedirectRuleTag([], []);

    $result = $tag->index();

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.source'))->toEqual($currentUri);
});

it('serves a redirect while today is inside its validity window', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/campaign', [
            'target_url' => '/promo',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-30',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2026-06-15'), function (): void {
        $result = findRedirectRuleExecute('/campaign');

        expect($result)->toHaveCount(1);
        expect(data_get($result, '0.target_url'))->toEqual('/promo');
    });
});

it('does not serve a redirect before its validity window opens', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/campaign', [
            'target_url' => '/promo',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-30',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2026-05-15'), function (): void {
        $result = findRedirectRuleExecute('/campaign');

        expect($result)->toBeEmpty();
    });
});

it('does not serve a redirect after its validity window closes', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/campaign', [
            'target_url' => '/promo',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-30',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2026-07-01'), function (): void {
        $result = findRedirectRuleExecute('/campaign');

        expect($result)->toBeEmpty();
    });
});

it('serves a redirect on the last day of its inclusive validity window', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/campaign', [
            'target_url' => '/promo',
            'valid_until' => '2026-06-30',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    // Late on the final day is still within the window (end snaps to end-of-day).
    $this->travelTo(CarbonImmutable::parse('2026-06-30 23:00:00'), function (): void {
        $result = findRedirectRuleExecute('/campaign');

        expect($result)->toHaveCount(1);
    });
});

it('always serves a legacy redirect that carries no validity window', function (): void {
    // A redirect row with neither bound (every existing rule) is unbounded and
    // serves regardless of the date — behavior is unchanged for legacy data.
    $redirects = [
        findRedirectRuleRedirect('/old-page', ['target_url' => '/target']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2099-01-01'), function (): void {
        $result = findRedirectRuleExecute('/old-page');

        expect($result)->toHaveCount(1);
    });
});

it('honors the validity window on a regex redirect', function (): void {
    $redirects = [
        findRedirectRuleRedirect('#^/promo/\d+$#', [
            'id' => 'redirect-regex-window',
            'target_url' => '/expired',
            'use_regex' => true,
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-30',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    // Outside the window the regex branch must skip the redirect too.
    $this->travelTo(CarbonImmutable::parse('2026-07-15'), function (): void {
        $result = findRedirectRuleExecute('/promo/123');

        expect($result)->toBeEmpty();
    });
});

it('keeps scanning past an out-of-window exact redirect to a later valid one', function (): void {
    // An out-of-window redirect sorts first; the tag must skip it (continue) and
    // still find the later valid exact match — not stop scanning at the first miss.
    $redirects = [
        findRedirectRuleRedirect('/expired', [
            'id' => 'redirect-expired',
            'target_url' => '/old',
            'order' => 10,
            'valid_from' => '2020-01-01',
            'valid_until' => '2020-12-31',
        ]),
        findRedirectRuleRedirect('/target', [
            'id' => 'redirect-live',
            'target_url' => '/new',
            'order' => 20,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2026-06-15'), function (): void {
        $result = findRedirectRuleExecute('/target');

        expect($result)->toHaveCount(1);
        expect(data_get($result, '0.target_url'))->toEqual('/new');
    });
});

it('keeps scanning past an out-of-window regex redirect to a later valid one', function (): void {
    // The regex branch must likewise continue past an out-of-window pattern to a
    // later valid pattern rather than break out of the loop.
    $redirects = [
        findRedirectRuleRedirect('#^/expired/\d+$#', [
            'id' => 'redirect-expired-regex',
            'target_url' => '/old',
            'order' => 10,
            'use_regex' => true,
            'valid_from' => '2020-01-01',
            'valid_until' => '2020-12-31',
        ]),
        findRedirectRuleRedirect('#^/live/\d+$#', [
            'id' => 'redirect-live-regex',
            'target_url' => '/new',
            'order' => 20,
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $this->travelTo(CarbonImmutable::parse('2026-06-15'), function (): void {
        $result = findRedirectRuleExecute('/live/42');

        expect($result)->toHaveCount(1);
        expect(data_get($result, '0.target_url'))->toEqual('/new');
    });
});

it('matches an exact source without regard to case', function (): void {
    // SRM folds case on both sides by default, so `/HotDog` resolves `/hotdog`.
    $redirects = [
        findRedirectRuleRedirect('/hotdog', ['target_url' => '/food/hot-dog']),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/HotDog');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/food/hot-dog');
});

it('matches a wildcard source and appends the remainder to a wildcard target', function (): void {
    // The canonical imported SRM wildcard rule; 2,532 of them in the export corpus.
    $redirects = [
        findRedirectRuleRedirect('/academic-senate-committees/*', [
            'id' => 'redirect-wildcard',
            'target_url' => '/committees/*',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/academic-senate-committees/research');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/committees/research');
});

it('matches the base path of a wildcard source', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/officers/*', [
            'id' => 'redirect-wildcard-base',
            'target_url' => '/leadership/*',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/officers');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/leadership/');
});

it('does not let a wildcard source match a sibling that merely shares its prefix', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/committees/*', [
            'id' => 'redirect-wildcard-sibling',
            'target_url' => '/groups/*',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/committees-archive');

    expect($result)->toBeEmpty();
});

it('sends every wildcard match to a fixed target that carries no wildcard', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/retired/*', [
            'id' => 'redirect-wildcard-fixed',
            'target_url' => '/replacement',
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/retired/deep/path');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/replacement');
});

it('leaves an entry-target wildcard rule untouched', function (): void {
    // An entry target is an id, so there is nothing to append a remainder to.
    $redirects = [
        findRedirectRuleRedirect('/old-section/*', [
            'id' => 'redirect-wildcard-entry',
            'target_type' => 'entry',
            'target_entry' => 'entry-id-1',
            'target_url' => null,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/old-section/child');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_entry'))->toEqual('entry-id-1');
    expect(data_get($result, '0.target_url'))->toBeNull();
});

it('prioritizes an exact match over a wildcard covering the same path', function (): void {
    // UAMS-Web/uams-statamic-archive#1657 relies on a pattern only ever claiming a request nothing literal did.
    $redirects = [
        findRedirectRuleRedirect('/section/*', [
            'id' => 'redirect-wildcard-broad',
            'target_url' => '/broad/*',
            'order' => 10,
        ]),
        findRedirectRuleRedirect('/section/specific', [
            'id' => 'redirect-exact-narrow',
            'target_url' => '/narrow',
            'order' => 50,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/section/specific');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/narrow');
});

it('matches a bare Safe Redirect Manager regex source', function (): void {
    // The exact row the importer lands (see SafeRedirectManagerCoverageTest): a
    // bare pattern carrying no delimiters, which preg_match cannot run as stored.
    $redirects = [
        findRedirectRuleRedirect('/legacy/(.*)', [
            'id' => 'redirect-bare-regex',
            'target_url' => '/new/$1',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/legacy/foo');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/new/foo');
});

it('substitutes several captures into a regex target', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/(\w+)\/(\d+)$', [
            'id' => 'redirect-multi-capture',
            'target_url' => '/$2/$1',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/section/42');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/42/section');
});

it('substitutes captures into an absolute url target', function (): void {
    // A deliberate divergence from SRM, whose FILTER_VALIDATE_URL guard skips
    // substitution here and sends 76 corpus rules to a literal `$1`.
    $redirects = [
        findRedirectRuleRedirect('^\/jobs\/(.+)$', [
            'id' => 'redirect-absolute-capture',
            'target_url' => 'https://jobs.example.test/$1',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/jobs/postings');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('https://jobs.example.test/postings');
});

it('collapses an authority a crafted request injects into a relative target', function (): void {
    // Capture substitution is request-derived, so a relative target must stay
    // relative rather than becoming a protocol-relative open redirect.
    $redirects = [
        findRedirectRuleRedirect('^\/go\/(.*)$', [
            'id' => 'redirect-open-redirect-guard',
            'target_url' => '$1',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/go//evil.test/phish');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toEqual('/evil.test/phish');
});

it('leaves an entry-target regex rule untouched', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/legacy\/(.+)$', [
            'id' => 'redirect-regex-entry',
            'target_type' => 'entry',
            'target_entry' => 'entry-id-2',
            'target_url' => null,
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/legacy/thing');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_entry'))->toEqual('entry-id-2');
    expect(data_get($result, '0.target_url'))->toBeNull();
});

it('skips a regex source that cannot compile even once wrapped', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/broken\/(.+', [
            'id' => 'redirect-bad-pattern',
            'target_url' => '/target',
            'use_regex' => true,
        ]),
    ];

    $globalSet = findRedirectRuleMockGlobalSet('default', $redirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet);

    $result = findRedirectRuleExecute('/broken/thing');

    expect($result)->toBeEmpty();
});

/**
 * Bind a request carrying a query string, so the matcher sees a non-empty `$currentQuery`.
 *
 * The tag reads the query off `request()->getRequestUri()`, so a test that only sets
 * `current_uri` in the context leaves `$currentQuery` empty and can never reach the
 * query-bearing branches of the matcher.
 *
 * @param  string  $uri  The full request URI, query string included
 */
function findRedirectRuleRequest(string $uri): void
{
    app()->instance('request', Request::create($uri));
}

it('matches a source that names its own query against the query-bearing subject', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old?ref=email', ['target_url' => '/matched-with-query']),
    ];

    findRedirectRuleRequest('/old?ref=email');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/old'), '0.target_url'))->toEqual('/matched-with-query');
});

it('does not match a query-bearing source when the request carries no query', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old?ref=email', ['target_url' => '/should-not-serve']),
    ];

    findRedirectRuleRequest('/old');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/old'))->toHaveCount(0);
});

it('treats a source written as a bare query as query-bearing, because normalization moves the separator off position zero', function (): void {
    // `sourceCarriesQuery()` ports SRM's `strpos($redirect_from, '?')`, which is deliberately
    // falsy at position zero. That branch is UNREACHABLE through the serving path: every source
    // is canonicalized by RedirectSource::normalize(), which is `'/'.trim($raw, '/')` and so
    // always prepends a slash — a `?` therefore lands at index 1 at the earliest.
    //
    // This case pins index 1, which is the boundary the `> 0` comparison actually decides. It is
    // what separates `> 0` from `> 1`; the `>= 0` and `> -1` variants are indistinguishable from
    // `> 0` for every input this class can receive, and are annotated at the comparison itself.
    $redirects = [
        findRedirectRuleRedirect('?ref=email', ['target_url' => '/position-one']),
    ];

    findRedirectRuleRequest('/?ref=email');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // The source normalizes to `/?ref=email`, so the separator sits at index 1: the rule is
    // query-bearing and matches against the path-with-query subject rather than the path alone.
    expect(data_get(findRedirectRuleExecute('/'), '0.target_url'))->toEqual('/position-one');
});

it('withholds the forwarded query when the rule matched on one already', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/old?ref=email', ['target_url' => '/no-forward']),
    ];

    findRedirectRuleRequest('/old?ref=email');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/old');

    expect(data_get($result, '0.target_url'))->toEqual('/no-forward');
    expect(data_get($result, '0.target_query_string'))->toBeNull();
});

it('forwards the request query to a rule that does not name one', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/plain', ['target_url' => '/forwarded']),
    ];

    findRedirectRuleRequest('/plain?utm_source=news&utm_medium=email');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/plain'), '0.target_query_string'))
        ->toEqual('utm_source=news&utm_medium=email');
});

it('normalizes a stored target query string and joins it with the forwarded query', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/plain', [
            'target_url' => '/joined',
            'target_query_string' => '  ?&stored=1  ',
        ]),
    ];

    findRedirectRuleRequest('/plain?utm=x');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // The stored value keeps its order ahead of the forwarded one, and both survive the join.
    expect(data_get(findRedirectRuleExecute('/plain'), '0.target_query_string'))
        ->toEqual('stored=1&utm=x');
});

it('emits the stored target query string alone when the request carries no query', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/plain', [
            'target_url' => '/stored-only',
            'target_query_string' => '?stored=1',
        ]),
    ];

    findRedirectRuleRequest('/plain');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/plain'), '0.target_query_string'))->toEqual('stored=1');
});

it('forwards the query for a regex rule whose pattern contains a question mark', function (): void {
    // In a PCRE source a `?` is a quantifier, not a query separator, so the query-bearing
    // test is forced off for regex rules and the request query is always forwarded.
    $redirects = [
        findRedirectRuleRedirect('^\/name\/?(\?.*)?$', [
            'use_regex' => true,
            'target_url' => '/regex-forwarded',
        ]),
    ];

    findRedirectRuleRequest('/name?utm=x');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/name');

    expect(data_get($result, '0.target_url'))->toEqual('/regex-forwarded');
    expect(data_get($result, '0.target_query_string'))->toEqual('utm=x');
});

it('folds case on both sides when comparing a source to the request path', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/Mixed-Case', ['target_url' => '/folded']),
    ];

    findRedirectRuleRequest('/mixed-case');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/mixed-case'), '0.target_url'))->toEqual('/folded');
});

it('does not let a bare trailing separator in a source match a request with no query', function (): void {
    // With no request query the matcher compares against the path alone. A source of `/old?`
    // normalizes to `/old?`, which is query-bearing, so it is compared against the
    // path-with-query subject — and that subject must be the bare path, not the path with a
    // dangling `?` appended.
    $redirects = [
        findRedirectRuleRedirect('/old?', ['target_url' => '/dangling']),
    ];

    findRedirectRuleRequest('/old');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/old'))->toHaveCount(0);
});

it('sorts a rule with no order key against one that has a lower order', function (): void {
    $redirects = [
        findRedirectRuleUnorderedRedirect('/ordering', ['id' => 'defaulted', 'target_url' => '/from-default']),
        findRedirectRuleRedirect('/ordering', ['id' => 'explicit', 'order' => 10, 'target_url' => '/from-explicit']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // The keyless rule falls back to 30, so the explicit 10 sorts ahead of it.
    expect(data_get(findRedirectRuleExecute('/ordering'), '0.target_url'))->toEqual('/from-explicit');
});

it('sorts a rule with no order key ahead of one with a higher order', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/ordering', ['id' => 'explicit', 'order' => 40, 'target_url' => '/from-explicit']),
        findRedirectRuleUnorderedRedirect('/ordering', ['id' => 'defaulted', 'target_url' => '/from-default']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // The keyless rule's 30 sorts ahead of the explicit 40, pinning the fallback from both sides.
    expect(data_get(findRedirectRuleExecute('/ordering'), '0.target_url'))->toEqual('/from-default');
});

it('skips a disabled wildcard rule in the pattern pass', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/wild/*', ['enabled' => false, 'target_url' => '/should-not-serve']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/wild/thing'))->toHaveCount(0);
});

it('skips a pattern-pass rule whose source key is absent', function (): void {
    $redirects = [
        findRedirectRuleKeylessRedirect('/wild/*', 'source', ['use_regex' => true, 'target_url' => '/no-source']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/wild/thing'))->toHaveCount(0);
});

it('skips a pattern-pass rule whose source is not a string', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/placeholder', ['source' => ['not', 'a', 'string'], 'use_regex' => true, 'target_url' => '/non-string']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/wild/thing'))->toHaveCount(0);
});

it('treats a wildcard source with no use_regex key as a wildcard rather than a regex', function (): void {
    // `?? false` must default the flag OFF: read as a regex, `/wild/*` is an invalid pattern
    // (nothing to repeat) and would compile to nothing, serving no redirect at all.
    $redirects = [
        findRedirectRuleKeylessRedirect('/wild/*', 'use_regex', ['target_url' => '/wildcard-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/wild/thing'), '0.target_url'))->toEqual('/wildcard-served');
});

it('appends a default redirect that follows one the site overrides', function (): void {
    // The override path `continue`s rather than breaking, so a default row AFTER an overridden
    // one still reaches the merged list.
    $siteRedirects = [
        findRedirectRuleRedirect('/shared', ['target_url' => '/site-wins']),
    ];
    $defaultRedirects = [
        findRedirectRuleRedirect('/shared', ['target_url' => '/default-loses']),
        findRedirectRuleRedirect('/default-only', ['target_url' => '/default-served']),
    ];

    $globalSet = findRedirectRuleMockGlobalSetMultisite('health', $siteRedirects, 'default', $defaultRedirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet, 'health', 'default');

    expect(data_get(findRedirectRuleExecute('/default-only', ['in' => 'health']), '0.target_url'))
        ->toEqual('/default-served');
});

it('does not let a non-string site source suppress a default row', function (): void {
    // A site row whose source is not a string contributes no override key, so the default
    // survives the merge.
    $siteRedirects = [
        findRedirectRuleRedirect('/placeholder', ['source' => ['not', 'a', 'string'], 'target_url' => '/site-noise']),
    ];
    $defaultRedirects = [
        findRedirectRuleRedirect('/from-default', ['target_url' => '/default-served']),
    ];

    $globalSet = findRedirectRuleMockGlobalSetMultisite('health', $siteRedirects, 'default', $defaultRedirects);
    findRedirectRuleFacadeMocks('redirects', $globalSet, 'health', 'default');

    expect(data_get(findRedirectRuleExecute('/from-default', ['in' => 'health']), '0.target_url'))
        ->toEqual('/default-served');
});

it('leaves a non-url target type unexpanded', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/entry-target/*', [
            'target_type' => 'entry',
            'target_url' => '/should-not-expand/',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    // An entry target is an id, so the wildcard remainder is never appended to it.
    expect(data_get(findRedirectRuleExecute('/entry-target/thing'), '0.target_url'))
        ->toEqual('/should-not-expand/');
});

it('leaves an empty url target unexpanded', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/empty-target/*', ['target_url' => '']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/empty-target/thing'), '0.target_url'))->toEqual('');
});

it('leaves a non-string url target unexpanded', function (): void {
    $redirects = [
        findRedirectRuleRedirect('/non-string-target/*', ['target_url' => ['not', 'a', 'string']]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/non-string-target/thing'), '0.target_url'))
        ->toEqual(['not', 'a', 'string']);
});

it('leaves a non-url target type unsubstituted on a regex rule', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/capture\/(.+)$', [
            'use_regex' => true,
            'target_type' => 'entry',
            'target_url' => '/entry-id-$1',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/capture/thing'), '0.target_url'))->toEqual('/entry-id-$1');
});

it('leaves an empty url target unsubstituted on a regex rule', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/capture\/(.+)$', ['use_regex' => true, 'target_url' => '']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/capture/thing'), '0.target_url'))->toEqual('');
});

it('leaves a non-string url target unsubstituted on a regex rule', function (): void {
    $redirects = [
        findRedirectRuleRedirect('^\/capture\/(.+)$', [
            'use_regex' => true,
            'target_url' => ['not', 'a', 'string'],
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/capture/thing'), '0.target_url'))
        ->toEqual(['not', 'a', 'string']);
});

it('logs the redirect id, the error code and the error message when pcre limits abort a match', function (): void {
    // A nested-quantifier pattern against a subject long enough to exhaust the bounded
    // backtrack budget. The match cannot complete, so the matcher logs what it aborted on.
    $log = Log::spy();

    $currentUri = str_repeat('a', 40).'b';

    $redirects = [
        findRedirectRuleRedirect('#(a+)+$#', [
            'id' => 'redos-rule',
            'use_regex' => true,
            'target_url' => '/never-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute($currentUri))->toBeEmpty();

    $log->shouldHaveReceived('warning')->withArgs(

        // Every key is asserted: the id identifies which rule aborted, and the two error
        // fields are what makes the entry actionable rather than merely present.
        fn (string $message, array $context): bool => $context['redirect_id'] === 'redos-rule'
        && $context['preg_last_error'] === PREG_BACKTRACK_LIMIT_ERROR
        && \is_string($context['preg_last_error_msg'])
        && $context['preg_last_error_msg'] !== ''
    )->once();
});

it('logs a null redirect id when the aborting rule carries no id', function (): void {
    $log = Log::spy();

    $currentUri = str_repeat('a', 40).'b';

    $redirects = [
        findRedirectRuleKeylessRedirect('#(a+)+$#', 'id', [
            'use_regex' => true,
            'target_url' => '/never-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute($currentUri))->toBeEmpty();

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['redirect_id'] === null
    )->once();
});

it('logs a scalar redirect id as a string', function (): void {
    $log = Log::spy();

    $currentUri = str_repeat('a', 40).'b';

    $redirects = [
        findRedirectRuleRedirect('#(a+)+$#', [
            'id' => 42,
            'use_regex' => true,
            'target_url' => '/never-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute($currentUri))->toBeEmpty();

    $log->shouldHaveReceived('warning')->withArgs(
        // The cast is the thing under test: an int id must reach the log as `'42'`, not `42`.
        fn (string $message, array $context): bool => $context['redirect_id'] === '42'
    )->once();
});

it('logs a null redirect id when the id is not scalar', function (): void {
    $log = Log::spy();

    $currentUri = str_repeat('a', 40).'b';

    $redirects = [
        findRedirectRuleRedirect('#(a+)+$#', [
            'id' => ['not', 'scalar'],
            'use_regex' => true,
            'target_url' => '/never-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute($currentUri))->toBeEmpty();

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['redirect_id'] === null
    )->once();
});

it('restores the previous pcre limits after running a bounded match', function (): void {
    // The bounds are tightened for the duration of the match and handed back afterwards, so a
    // redirect lookup cannot leave the process running under the matcher's limits.
    $previousBacktrackLimit = ini_get('pcre.backtrack_limit');
    $previousRecursionLimit = ini_get('pcre.recursion_limit');

    $redirects = [
        findRedirectRuleRedirect('^\/bounded\/(.+)$', [
            'use_regex' => true,
            'target_url' => '/restored',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/bounded/thing'))->toHaveCount(1);

    expect(ini_get('pcre.backtrack_limit'))->toEqual($previousBacktrackLimit);
    expect(ini_get('pcre.recursion_limit'))->toEqual($previousRecursionLimit);
});

it('leaves a wildcard target unexpanded when the target type is not a url', function (): void {
    // The target must itself be a wildcard for expansion to be observable — `expandTarget()`
    // returns a fixed destination untouched, so a non-wildcard target cannot distinguish the
    // early return from falling through to it.
    $redirects = [
        findRedirectRuleRedirect('/entry-wild/*', [
            'target_type' => 'entry',
            'target_url' => '/entry-id/*',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/entry-wild/thing'), '0.target_url'))->toEqual('/entry-id/*');
});

it('does not invent a target url for a wildcard rule that has none', function (): void {
    // With no `target_url` key the `?? ''` fallback makes the guard return the row untouched,
    // so the row must come back still carrying no target at all.
    $redirects = [
        findRedirectRuleKeylessRedirect('/no-target/*', 'target_url'),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/no-target/thing');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toBeNull();
});

it('does not invent a target url for a regex rule that has none', function (): void {
    $redirects = [
        findRedirectRuleKeylessRedirect('^\/no-target-regex\/(.+)$', 'target_url', ['use_regex' => true]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/no-target-regex/thing');

    expect($result)->toHaveCount(1);
    expect(data_get($result, '0.target_url'))->toBeNull();
});

it('keeps scanning pattern rules after skipping one with an empty source', function (): void {
    // The empty-pattern guard `continue`s rather than breaking, so a later pattern rule is
    // still reached.
    $redirects = [
        findRedirectRuleRedirect('/placeholder', ['id' => 'empty', 'source' => '', 'use_regex' => true, 'order' => 10]),
        findRedirectRuleRedirect('/later/*', ['id' => 'later', 'order' => 20, 'target_url' => '/later-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/later/thing'), '0.target_url'))->toEqual('/later-served');
});

it('breaks an order tie with the keyless rule against an explicit 30', function (): void {
    // The fallback is exactly 30, so a keyless rule and an explicit 30 tie, and PHP's stable
    // sort keeps the keyless rule first. A fallback of 29 or 31 would break the tie either way.
    $redirects = [
        findRedirectRuleUnorderedRedirect('/tie', ['id' => 'keyless', 'target_url' => '/from-keyless']),
        findRedirectRuleRedirect('/tie', ['id' => 'explicit', 'order' => 30, 'target_url' => '/from-explicit']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/tie'), '0.target_url'))->toEqual('/from-keyless');
});

it('breaks an order tie with the explicit 30 against a keyless rule', function (): void {
    // The mirror of the case above: with the explicit rule first, the tie must keep IT first.
    // Together the two pin the fallback at exactly 30 from both sides.
    $redirects = [
        findRedirectRuleRedirect('/tie-mirror', ['id' => 'explicit', 'order' => 30, 'target_url' => '/from-explicit']),
        findRedirectRuleUnorderedRedirect('/tie-mirror', ['id' => 'keyless', 'target_url' => '/from-keyless']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/tie-mirror'), '0.target_url'))->toEqual('/from-explicit');
});

it('matches a rule with no source key against the site root', function (): void {
    // The `?? ''` fallback normalizes to `/`, so a row carrying no source at all is a rule for
    // the bare root rather than a rule that matches nothing.
    $redirects = [
        findRedirectRuleKeylessRedirect('/unused', 'source', ['target_url' => '/root-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/'), '0.target_url'))->toEqual('/root-served');
});

it('withholds the forwarded query for a query-bearing rule that has no use_regex key', function (): void {
    // `?? false` must default the flag OFF here too: read as a regex the source would be forced
    // to `''`, the query-bearing test would not fire, and the request query would be forwarded.
    $redirects = [
        findRedirectRuleKeylessRedirect('/keyless?ref=email', 'use_regex', ['target_url' => '/no-forward']),
    ];

    findRedirectRuleRequest('/keyless?ref=email');
    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/keyless');

    expect(data_get($result, '0.target_url'))->toEqual('/no-forward');
    expect(data_get($result, '0.target_query_string'))->toBeNull();
});

it('accepts a regex source of exactly the maximum pattern length', function (): void {
    // The guard is `>`, not `>=`, so a source of exactly MAX_REDIRECT_REGEX_PATTERN_BYTES must
    // still compile and match. This pins the boundary itself rather than a value beyond it.
    $filler = str_repeat('a', 1024 - \strlen('^\/x\/') - \strlen('$'));
    $source = '^\/x\/'.$filler.'$';

    expect(\strlen($source))->toEqual(1024);

    $redirects = [
        findRedirectRuleRedirect($source, ['use_regex' => true, 'target_url' => '/exact-length-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/x/'.$filler), '0.target_url'))->toEqual('/exact-length-served');
});

it('sorts a keyless rule ahead of an explicit 31 listed before it', function (): void {
    // The mirror of `sorts a rule with no order key ahead of one with a higher order`. usort()
    // passes the first element as `$a` and the second as `$b` — measured on PHP 8.4.23 — so a
    // keyless rule listed FIRST only ever exercises the `$a` side of the comparator, and the
    // `$b` fallback is a separate expression that no other fixture reaches with a decisive
    // outcome. An explicit 31 must lose to the 30 fallback from either position.
    $redirects = [
        findRedirectRuleRedirect('/order-b-side', ['id' => 'over', 'order' => 31, 'target_url' => '/from-31']),
        findRedirectRuleUnorderedRedirect('/order-b-side', ['id' => 'none', 'target_url' => '/from-none']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute('/order-b-side'), '0.target_url'))->toEqual('/from-none');
});

it('ignores a non-string stored target query string rather than composing it', function (): void {
    // `target_query_string` is tolerated as a non-string, and the tolerance must be a discard
    // rather than a coercion: a row carrying an array there composes to nothing, so the row is
    // handed back with its own value untouched instead of gaining a fabricated query.
    $redirects = [
        findRedirectRuleRedirect('/non-string-query', [
            'target_query_string' => ['not', 'a', 'string'],
            'target_url' => '/composed',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    $result = findRedirectRuleExecute('/non-string-query');

    expect(data_get($result, '0.target_url'))->toEqual('/composed');
    expect(data_get($result, '0.target_query_string'))->toEqual(['not', 'a', 'string']);
});

it('refuses a regex source longer than the pattern byte bound even when it would match', function (): void {
    // The companion to `accepts a regex source of exactly the maximum pattern length`. That test
    // pins the boundary from below; this one pins that the guard actually returns rather than
    // falling through, by giving it a pattern that WOULD match the request if it ever compiled.
    $filler = str_repeat('a', 1030);
    $source = '^\/'.$filler.'$';

    expect(\strlen($source))->toBeGreaterThan(1024);

    $redirects = [
        findRedirectRuleRedirect($source, ['use_regex' => true, 'target_url' => '/over-length-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute('/'.$filler))->toBeEmpty();
});

it('runs a regex against a URI of exactly the subject byte bound', function (): void {
    // The subject guard is `>`, not `>=`, so a URI of exactly MAX_REDIRECT_REGEX_SUBJECT_BYTES is
    // still matched. Nothing else in the suite sits on that boundary, so an off-by-one there
    // would silently stop serving the longest URIs the bound is meant to admit.
    $uri = '/'.str_repeat('a', 4095);

    expect(\strlen($uri))->toEqual(4096);

    $redirects = [
        findRedirectRuleRedirect('^\/a+$', ['use_regex' => true, 'target_url' => '/bounded-served']),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(data_get(findRedirectRuleExecute($uri), '0.target_url'))->toEqual('/bounded-served');
});

it('restores pcre limits to values the matcher never sets', function (): void {
    // `restores the previous pcre limits after running a bounded match` captures whatever the
    // process is already running under. Once a restore has been missed, that captured "previous"
    // value IS the leaked one and the assertion passes — so the test cannot see the second and
    // every later leak. Sentinels neither the matcher nor PHP's defaults use make a missing
    // restore visible on the first run, whatever ran before it.
    $previousBacktrackLimit = ini_get('pcre.backtrack_limit');
    $previousRecursionLimit = ini_get('pcre.recursion_limit');

    ini_set('pcre.backtrack_limit', '654321');
    ini_set('pcre.recursion_limit', '123456');

    try {
        $redirects = [
            findRedirectRuleRedirect('^\/sentinel\/(.+)$', [
                'use_regex' => true,
                'target_url' => '/restored',
            ]),
        ];

        findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

        expect(findRedirectRuleExecute('/sentinel/thing'))->toHaveCount(1);

        expect(ini_get('pcre.backtrack_limit'))->toEqual('654321');
        expect(ini_get('pcre.recursion_limit'))->toEqual('123456');
    } finally {
        ini_set('pcre.backtrack_limit', $previousBacktrackLimit);
        ini_set('pcre.recursion_limit', $previousRecursionLimit);
    }
});

it('logs nothing for a pcre error that is not one of the two limits', function (): void {
    // The warning is about the ReDoS bounds, so an error from anything else must not raise it.
    // `RedirectPattern::compile()` honors a source that is already a delimited pattern, modifiers
    // included, which is the only way a `u` modifier reaches preg_match() from stored data; a
    // malformed-UTF-8 request path then fails with PREG_BAD_UTF8_ERROR rather than a limit.
    $log = Log::spy();

    $redirects = [
        findRedirectRuleRedirect('#^/(.+)$#u', [
            'id' => 'utf8-rule',
            'use_regex' => true,
            'target_url' => '/never-served',
        ]),
    ];

    findRedirectRuleFacadeMocks('redirects', findRedirectRuleMockGlobalSet('default', $redirects));

    expect(findRedirectRuleExecute("/\xC3\x28"))->toBeEmpty();

    $log->shouldNotHaveReceived('warning', ['Redirect regex matching stopped due to PCRE limits.', Mockery::any()]);
});
