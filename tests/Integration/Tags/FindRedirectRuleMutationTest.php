<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use Statamic\Tags\Context;
use Statamic\Tags\Parameters;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

covers(FindRedirectRule::class);

uses(MockeryPHPUnitIntegration::class);

/**
 * Narrow mutate coverage for UAMSWeb\Aether\Redirects\Tags\FindRedirectRule (tag wiring only).
 *
 * Rule selection lives in RedirectRuleMatcher; this suite pins the tag's
 * handle/site/URI/query wiring and the empty-global early return.
 *
 * @command  vendor/bin/pest tests/Integration/Tags/FindRedirectRuleMutationTest.php
 */

/**
 * @param  array<string, mixed>  $contextData
 * @param  array<string, mixed>  $params
 */
function findRedirectMutationTag(array $contextData = [], array $params = []): FindRedirectRule
{
    $context = Mockery::mock(Context::class);
    $context->shouldReceive('get')
        ->andReturnUsing(fn (string $key) => $contextData[$key] ?? null);

    $parameters = Mockery::mock(Parameters::class);
    $parameters->shouldReceive('get')
        ->andReturnUsing(fn (string $key, mixed $default = null) => $params[$key] ?? $default);

    $tag = new FindRedirectRule;
    $reflection = new ReflectionClass($tag);
    $reflection->getProperty('context')->setValue($tag, $context);
    $reflection->getProperty('params')->setValue($tag, $parameters);

    return $tag;
}

/**
 * @param  array<int, array<string, mixed>>  $redirects
 */
function findRedirectMutationSitesAndGlobal(
    string $siteHandle,
    string $defaultHandle,
    array $redirects,
): void {
    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->andReturn($siteHandle);
    Site::shouldReceive('current')->andReturn($current);

    $default = Mockery::mock(SiteObject::class);
    $default->shouldReceive('handle')->andReturn($defaultHandle);
    Site::shouldReceive('default')->andReturn($default);

    /** @var Variables&MockInterface $variables */
    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn($redirects);

    /** @var GlobalSetContract&MockInterface $global */
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->andReturn($variables);

    GlobalSet::shouldReceive('findByHandle')->andReturn($global);
}

it('returns empty when the redirects global set is missing', function (): void {
    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->andReturn('default');
    Site::shouldReceive('current')->andReturn($current);
    Site::shouldReceive('default')->andReturn($current);

    GlobalSet::shouldReceive('findByHandle')
        ->once()
        ->with('redirects')
        ->andReturnNull();

    expect(findRedirectMutationTag(
        ['current_uri' => '/gone'],
    )->index())->toBe([]);
});

it('defaults handle to redirects and site to Site::current', function (): void {
    $seenHandle = null;

    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->once()->andReturn('news');
    Site::shouldReceive('current')->andReturn($current);

    $default = Mockery::mock(SiteObject::class);
    $default->shouldReceive('handle')->andReturn('default');
    Site::shouldReceive('default')->andReturn($default);

    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn([
        [
            'id' => 'r1',
            'source' => '/from',
            'target_url' => '/to',
            'enabled' => true,
            'order' => 1,
        ],
    ]);
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->andReturn($variables);

    GlobalSet::shouldReceive('findByHandle')
        ->once()
        ->andReturnUsing(function (string $handle) use (&$seenHandle, $global) {
            $seenHandle = $handle;

            return $global;
        });

    $result = findRedirectMutationTag(
        ['current_uri' => '/from'],
    )->index();

    expect($seenHandle)->toBe('redirects')
        ->and($result)->not->toBeEmpty()
        ->and($result[0]['source'] ?? null)->toBe('/from');
});

it('uses an explicit handle and in site parameter', function (): void {
    $current = Mockery::mock(SiteObject::class);
    // TagParam::get('in', ...) supplies the site; current()->handle() is unused.
    $current->shouldReceive('handle')->zeroOrMoreTimes()->andReturn('ignored');
    Site::shouldReceive('current')->andReturn($current);

    $default = Mockery::mock(SiteObject::class);
    $default->shouldReceive('handle')->andReturn('default');
    Site::shouldReceive('default')->andReturn($default);

    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn([
        [
            'id' => 'r2',
            'source' => '/a',
            'target_url' => '/b',
            'enabled' => true,
            'order' => 1,
        ],
    ]);
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->with('custom')->andReturn($variables);
    $global->shouldReceive('in')->with('default')->andReturn($variables);

    GlobalSet::shouldReceive('findByHandle')
        ->once()
        ->with('custom_redirects')
        ->andReturn($global);

    $result = findRedirectMutationTag(
        ['current_uri' => '/a'],
        [
            'handle' => 'custom_redirects',
            'in' => 'custom',
        ],
    )->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['id'] ?? null)->toBe('r2');
});

it('prefers a string current_uri from context over the request path', function (): void {
    findRedirectMutationSitesAndGlobal(
        'default',
        'default',
        [
            [
                'id' => 'r3',
                'source' => '/context-path',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ],
    );

    GlobalSet::shouldReceive('findByHandle')->andReturnUsing(function () {
        $variables = Mockery::mock(Variables::class);
        $variables->shouldReceive('get')->with('redirects', [])->andReturn([
            [
                'id' => 'r3',
                'source' => '/context-path',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ]);
        $global = Mockery::mock(GlobalSetContract::class);
        $global->shouldReceive('in')->andReturn($variables);

        return $global;
    });

    app()->instance(
        'request',
        Request::create(
            'https://example.test/request-path',
            'GET',
        ),
    );

    $result = findRedirectMutationTag(
        ['current_uri' => '/context-path'],
    )->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['source'] ?? null)->toBe('/context-path');
});

it('falls back to the request path when context current_uri is absent', function (): void {
    findRedirectMutationSitesAndGlobal(
        'default',
        'default',
        [
            [
                'id' => 'r4',
                'source' => '/request-only',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ],
    );

    GlobalSet::shouldReceive('findByHandle')->andReturnUsing(function () {
        $variables = Mockery::mock(Variables::class);
        $variables->shouldReceive('get')->with('redirects', [])->andReturn([
            [
                'id' => 'r4',
                'source' => '/request-only',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ]);
        $global = Mockery::mock(GlobalSetContract::class);
        $global->shouldReceive('in')->andReturn($variables);

        return $global;
    });

    app()->instance(
        'request',
        Request::create(
            'https://example.test/request-only',
            'GET',
        ),
    );

    $result = findRedirectMutationTag()->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['source'] ?? null)->toBe('/request-only');
});

it('forwards the raw query string without sorting keys', function (): void {
    findRedirectMutationSitesAndGlobal(
        'default',
        'default',
        [
            [
                'id' => 'r5',
                'source' => '/q?b=2&a=1',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ],
    );

    GlobalSet::shouldReceive('findByHandle')->andReturnUsing(function () {
        $variables = Mockery::mock(Variables::class);
        $variables->shouldReceive('get')->with('redirects', [])->andReturn([
            [
                'id' => 'r5',
                'source' => '/q?b=2&a=1',
                'target_url' => '/dest',
                'enabled' => true,
                'order' => 1,
            ],
        ]);
        $global = Mockery::mock(GlobalSetContract::class);
        $global->shouldReceive('in')->andReturn($variables);

        return $global;
    });

    app()->instance(
        'request',
        Request::create(
            'https://example.test/q?b=2&a=1',
            'GET',
        ),
    );

    $result = findRedirectMutationTag()->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['id'] ?? null)->toBe('r5');
});

it('ignores a truthy non-string current_uri and uses the request path', function (): void {
    // BooleanAndToBooleanOr would enter normalize() with an int and TypeError.
    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->andReturn('default');
    Site::shouldReceive('current')->andReturn($current);
    Site::shouldReceive('default')->andReturn($current);

    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn([
        [
            'id' => 'r-non-string',
            'source' => '/from-request',
            'target_url' => '/dest',
            'enabled' => true,
            'order' => 1,
        ],
    ]);
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->andReturn($variables);
    GlobalSet::shouldReceive('findByHandle')->andReturn($global);

    app()->instance(
        'request',
        Request::create(
            'https://example.test/from-request',
            'GET',
        ),
    );

    $result = findRedirectMutationTag(
        ['current_uri' => 1],
    )->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['id'] ?? null)->toBe('r-non-string');
});

it('does not invent a target_query_string when the request has no query', function (): void {
    // EmptyStringToNotEmpty on the absent-query fallback would forward
    // "PEST Mutator was here!" into withComposedQueryString().
    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->andReturn('default');
    Site::shouldReceive('current')->andReturn($current);
    Site::shouldReceive('default')->andReturn($current);

    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn([
        [
            'id' => 'r-no-query',
            'source' => '/bare',
            'target_url' => '/dest',
            'enabled' => true,
            'order' => 1,
        ],
    ]);
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->andReturn($variables);
    GlobalSet::shouldReceive('findByHandle')->andReturn($global);

    app()->instance(
        'request',
        Request::create(
            'https://example.test/bare',
            'GET',
        ),
    );

    $result = findRedirectMutationTag()->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0])->not->toHaveKey('target_query_string');
});

it('tolerates a null default site when merging rules', function (): void {
    $current = Mockery::mock(SiteObject::class);
    $current->shouldReceive('handle')->andReturn('lone');
    Site::shouldReceive('current')->andReturn($current);
    Site::shouldReceive('default')->andReturnNull();

    $variables = Mockery::mock(Variables::class);
    $variables->shouldReceive('get')->with('redirects', [])->andReturn([
        [
            'id' => 'r6',
            'source' => '/lone',
            'target_url' => '/dest',
            'enabled' => true,
            'order' => 1,
        ],
    ]);
    $global = Mockery::mock(GlobalSetContract::class);
    $global->shouldReceive('in')->with('lone')->andReturn($variables);

    GlobalSet::shouldReceive('findByHandle')->once()->with('redirects')->andReturn($global);

    $result = findRedirectMutationTag(
        ['current_uri' => '/lone'],
    )->index();

    expect($result)->not->toBeEmpty()
        ->and($result[0]['id'] ?? null)->toBe('r6');
});
