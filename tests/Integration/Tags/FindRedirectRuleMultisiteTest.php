<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\Variables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * End-to-end multisite serving of the shared (non-localizable) redirects list
 * (UAMS-Web/uams-statamic-archive#1110, decision C).
 *
 * The `redirects` global field is `localizable: false` — one list edited on the
 * default site, intended to apply everywhere — but production registers every
 * site with `origin: null`, so a raw per-site read (`in($site)->get('redirects')`)
 * returns [] on a non-default site and the redirect silently 404s there. This
 * test shapes the global exactly like production (registered on ≥2 sites, field
 * non-localizable, origins null) and proves the read-time merge in
 * {@see FindRedirectRule} serves the default list on a non-default site, keeps a
 * per-site redirect (the UAMS-Web/uams-statamic-archive#1088 people-merge write) isolated to its own site, and
 * lets a per-site entry win over a same-source default entry.
 *
 * The add-on has no content of its own, so each test configures two sites and
 * keeps every save off disk.
 *
 * @command  vendor/bin/pest tests/Integration/Tags/FindRedirectRuleMultisiteTest.php
 */
uses(PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    config()->set('statamic.system.multisite', true);
    Site::setSites([
        'enterprise' => ['name' => 'Enterprise', 'url' => '/', 'locale' => 'en_US'],
        'excelsior' => ['name' => 'Excelsior', 'url' => '/excelsior/', 'locale' => 'en_US'],
    ]);
});

/**
 * Two distinct configured site handles (default first).
 *
 * @return array{0: string, 1: string}
 */
function frmTwoSites(): array
{
    /** @var Collection<int|string, Statamic\Sites\Site> $sites */
    $sites = Site::all();
    /** @var list<string> $handles */
    $handles = $sites->map(fn (Statamic\Sites\Site $site) => $site->handle())->values()->all();
    Assert::assertGreaterThanOrEqual(2, \count($handles), 'This test requires a multisite configuration.');

    /** @var Statamic\Sites\Site $defaultSite */
    $defaultSite = Site::default();
    /** @var string $default */
    $default = $defaultSite->handle();

    return [$default, $handles[0] === $default ? $handles[1] : $handles[0]];
}

/**
 * Persist the given redirect rows on the given site's redirects localization.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function frmSetRedirects(string $site, array $rows): void
{
    $set = GlobalSet::findByHandle('redirects');
    Assert::assertNotNull($set);

    $variables = $set->in($site);
    Assert::assertNotNull($variables);

    /** @var Variables $variables */
    $variables->data(['redirects' => $rows]);
    $variables->save();
}

/**
 * Build a single redirect row with sensible defaults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frmRedirect(string $source, string $target, array $overrides = []): array
{
    return array_merge([
        'id' => $source,
        'type' => 'redirect',
        'source' => $source,
        'target_type' => 'url',
        'target_url' => $target,
        'response' => '301',
        'order' => 30,
        'enabled' => true,
    ], $overrides);
}

/**
 * Run find_redirect_rule for a URI as if a 404 occurred on the given site.
 *
 * @return list<array<string, mixed>>
 */
function frmResolve(string $uri, string $site): array
{
    $tag = new FindRedirectRule;
    $tag->setContext(['current_uri' => $uri]);
    $tag->setParameters(['in' => $site]);

    return $tag->index();
}

beforeEach(function (): void {
    [$default, $other] = frmTwoSites();

    // The redirects field is non-localizable, mirroring
    // resources/blueprints/globals/redirects.yaml.
    $redirectsBlueprint = Blueprint::makeFromFields([
        'redirects' => ['type' => 'replicator', 'localizable' => false],
    ]);
    $redirectsBlueprint->setHandle('redirects');
    $redirectsBlueprint->setNamespace('globals');
    $redirectsBlueprint->save();

    // Redirects global registered on ≥2 sites with origins null, exactly like the
    // production content/globals/redirects.yaml config — no origin cascade.
    /** @var Statamic\Globals\GlobalSet $set */
    $set = GlobalSet::make('redirects');
    $set->title('Redirects');
    $set->sites([$default => null, $other => null]);
    $set->save();

    foreach ([$default, $other] as $site) {
        /** @var Variables $variables */
        $variables = $set->makeLocalization($site);
        $variables->data(['redirects' => []]);
        $variables->save();
    }
});

// A blueprint save is not a Stache item, so it reaches the Testbench skeleton's
// resources/blueprints; delete it so no later test reads it.
afterEach(function (): void {
    Blueprint::find('globals.redirects')?->delete();
});

it('serves a redirect edited on the default site when a non-default site 404s', function (): void {
    [$default, $other] = frmTwoSites();

    // Editor adds a redirect on the default site only (the shared list).
    frmSetRedirects($default, [frmRedirect('/shared-old', '/shared-new')]);

    // It serves on the default site ...
    $onDefault = frmResolve('/shared-old', $default);
    expect($onDefault)->toHaveCount(1)
        ->and($onDefault[0]['target_url'])->toBe('/shared-new');

    // ... and now, via the read-time merge, on the non-default site too.
    $onOther = frmResolve('/shared-old', $other);
    expect($onOther)->toHaveCount(1)
        ->and($onOther[0]['source'])->toBe('/shared-old')
        ->and($onOther[0]['target_url'])->toBe('/shared-new');
});

it('keeps a per-site redirect isolated to its own site (the #1088 merge write)', function (): void {
    [$default, $other] = frmTwoSites();

    // A people-merge redirect written to the non-default site's localization.
    frmSetRedirects($other, [frmRedirect('/loser-url', '/survivor-url')]);

    // Serves on the site it was written to ...
    $onOther = frmResolve('/loser-url', $other);
    expect($onOther)->toHaveCount(1)
        ->and($onOther[0]['target_url'])->toBe('/survivor-url');

    // ... but does NOT leak to the default site (default reads its own list only).
    expect(frmResolve('/loser-url', $default))->toBe([]);
});

it('lets a per-site redirect win over a same-source default redirect', function (): void {
    [$default, $other] = frmTwoSites();

    // Same source defined on both sites; the non-default site overrides, even
    // though the default row's lower order would otherwise sort it first.
    frmSetRedirects($default, [frmRedirect('/dup', '/default-target', ['order' => 10])]);
    frmSetRedirects($other, [frmRedirect('/dup', '/other-target', ['order' => 30])]);

    $onOther = frmResolve('/dup', $other);
    expect($onOther)->toHaveCount(1)
        ->and($onOther[0]['target_url'])->toBe('/other-target');

    // The default site still serves its own entry.
    $onDefault = frmResolve('/dup', $default);
    expect($onDefault)->toHaveCount(1)
        ->and($onDefault[0]['target_url'])->toBe('/default-target');
});
