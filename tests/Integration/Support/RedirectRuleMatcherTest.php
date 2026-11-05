<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;

covers(RedirectRuleMatcher::class);

uses(MockeryPHPUnitIntegration::class);

/**
 * Branches of the matcher that `FindRedirectRuleTest` does not reach through the tag.
 *
 * Each test here kills a mutant the host application's suite left uncovered: the `'0'`
 * pattern skip, the early return for a site with no localization, the fallback when a
 * capture substitution cannot complete, and the pattern byte bound at exactly its limit.
 *
 * @command  vendor/bin/pest tests/Integration/Support/RedirectRuleMatcherTest.php
 */

/**
 * Build a pattern redirect row.
 *
 * @param  string  $source  The stored regex source.
 * @param  string  $target  The stored target URL.
 * @param  array<string, mixed>  $overrides  Values to override the defaults.
 * @return array<string, mixed> The redirect row.
 */
function redirectRuleMatcherPatternRow(string $source, string $target, array $overrides = []): array
{
    return array_merge([
        'id' => 'enterprise-'.md5($source),
        'type' => 'redirect',
        'source' => $source,
        'target_type' => 'url',
        'target_url' => $target,
        'response' => '301',
        'order' => 30,
        'enabled' => true,
        'use_regex' => true,
    ], $overrides);
}

it('skips a pattern rule whose source is the string zero and keeps scanning', function (): void {
    // A `'0'` source compiles to a pattern matching any path containing a zero, so without the
    // skip it would answer `/deck-10`; and the skip must continue the scan, not end it.
    $rules = [
        redirectRuleMatcherPatternRow('0', '/zero', ['order' => 1]),
        redirectRuleMatcherPatternRow('^\/deck-(\d+)$', '/decks/$1', ['order' => 2]),
    ];

    $result = (new RedirectRuleMatcher)->match($rules, '/deck-10', '');

    expect($result)->toHaveCount(1)
        ->and($result[0]['target_url'])->toBe('/decks/10');
});

it('reads no rules for a site the global set has no localization on', function (): void {
    // A global set not registered on the site returns null from in(); that is an empty list.
    /** @var GlobalSetContract&MockInterface $global */
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->with('excelsior')->once()->andReturnNull();

    expect((new RedirectRuleMatcher)->redirectsForSite($global, 'excelsior'))->toBe([]);
});

it('serves the stored target and logs when the capture substitution cannot complete', function (): void {
    // preg_match() stops at the first match, the cheap `a`. preg_replace() keeps scanning the
    // rest of the subject, where the nested quantifier backtracks past the bounded limit and
    // returns null; the rule is then served with its stored target.
    $log = Log::spy();
    $uri = '/a'.str_repeat('x', 30);
    $rules = [
        redirectRuleMatcherPatternRow('#a|(x+x+)+y#', '/bridge', ['id' => 'ncc-1701']),
    ];

    $result = (new RedirectRuleMatcher)->match($rules, $uri, '');

    expect($result)->toHaveCount(1)
        ->and($result[0]['target_url'])->toBe('/bridge');
    $log->shouldHaveReceived('warning')->once()->with(
        'Redirect target substitution failed; serving the stored target.',
        [
            'redirect_id' => 'ncc-1701',
            'preg_last_error' => PREG_BACKTRACK_LIMIT_ERROR,
            'preg_last_error_msg' => 'Backtrack limit exhausted',
        ]
    );
});

it('runs a pattern of exactly the byte limit and skips one a byte longer', function (): void {
    // Both patterns match `/any`, so only the 1024-byte bound decides between them.
    $atLimit = '^\/any'.str_repeat('x?', 509);
    $overLimit = $atLimit.'$';

    expect(strlen($atLimit))->toBe(1024)
        ->and(strlen($overLimit))->toBe(1025);

    $matcher = new RedirectRuleMatcher;

    expect($matcher->match([redirectRuleMatcherPatternRow($atLimit, '/served')], '/any', ''))->toHaveCount(1)
        ->and($matcher->match([redirectRuleMatcherPatternRow($overLimit, '/refused')], '/any', ''))->toBe([]);
});
