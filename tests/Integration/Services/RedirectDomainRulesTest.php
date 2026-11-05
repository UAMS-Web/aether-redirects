<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * How `RedirectDomainRules` turns the collection's entries into rules: the fields
 * it defaults, the counts it reports, the order it returns hosts in, and how it
 * resolves an entry target. The manifest and serving tests cover the rules end to
 * end; these pin the details neither of them reads.
 *
 * @command  vendor/bin/pest tests/Integration/Services/RedirectDomainRulesTest.php
 */
beforeEach(function (): void {
    // Copy the host's blueprint, and the common URL field it imports, into the
    // isolated directories
    $blueprint = 'collections/redirect_domains/redirect_domain.yaml';
    File::ensureDirectoryExists(dirname(BlueprintFacade::directory().'/'.$blueprint));
    File::copy(__DIR__.'/../../Fixtures/blueprints/'.$blueprint, BlueprintFacade::directory().'/'.$blueprint);
    File::ensureDirectoryExists(Fieldset::directory());
    File::copy(__DIR__.'/../../Fixtures/fieldsets/uamsweb_common.yaml', Fieldset::directory().'/uamsweb_common.yaml');

    // Build the collection from a copy of the host's config file
    $path = __DIR__.'/../../Fixtures/redirect_domains.yaml';
    $store = Stache::store('collections');
    Assert::assertInstanceOf(CollectionsStore::class, $store);

    $collection = $store->makeItemFromFile($path, File::get($path));
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $collection->save();
});

/**
 * Save a published redirect domain entry carrying exactly the given fields.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function domainRulesEntry(array $data): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data($data);
    $entry->save();

    return $entry;
}

/**
 * Save an entry in a collection of the given route and sites, for an entry target.
 *
 * @param  string  $site  The entry's site.
 * @param  string|null  $route  The collection's route, or null for none.
 * @param  list<string>  $sites  The collection's sites.
 */
function domainRulesDestination(string $site, ?string $route, array $sites = ['default']): StatamicEntry
{
    $handle = $route === null ? 'unrouted_destinations' : 'routed_destinations';

    if (Collection::findByHandle($handle) === null) {
        $collection = Collection::make($handle);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);

        $collection->sites($sites);

        if ($route !== null) {
            $collection->routes($route);
        }

        $collection->save();
    }

    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection($handle);
    $entry->locale($site);
    $entry->slug('giving');
    $entry->data(['title' => 'Giving']);
    $entry->save();

    return $entry;
}

/**
 * The one rule read for a host.
 *
 * @param  string  $host  The host.
 * @return array<string, mixed> The rule.
 */
function domainRuleFor(string $host): array
{
    $rules = (new RedirectDomainRules)->read()['rules'][$host] ?? [];
    Assert::assertCount(1, $rules);

    return $rules[0];
}

it('carries a validity window and an approval status only when the entry has them', function (): void {
    domainRulesEntry([
        'host' => 'agec.org',
        'source' => '/gated',
        'target_url' => 'https://agec.uams.edu',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'status' => 'approved',
    ]);
    domainRulesEntry(['host' => 'uams.flu', 'source' => '/open', 'target_url' => 'https://uamshealth.com']);

    expect(domainRuleFor('agec.org'))->toMatchArray([
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'status' => 'approved',
    ])
        ->and(domainRuleFor('uams.flu'))->not->toHaveKeys(['valid_from', 'valid_until', 'status']);
});

it('defaults an entry with no stored enabled, response or order to enabled, 301 and 30', function (): void {
    domainRulesEntry(['host' => 'agec.org', 'source' => '/bare', 'target_url' => 'https://agec.uams.edu']);

    expect(domainRuleFor('agec.org'))->toMatchArray(['enabled' => true, 'response' => '301', 'order' => 30]);
});

it('keeps a source entry its own order, and tries a whole-domain entry last', function (): void {
    domainRulesEntry(['host' => 'agec.org', 'source' => '/early', 'order' => 10, 'target_url' => 'https://agec.uams.edu']);
    domainRulesEntry(['host' => 'uams.flu', 'whole_domain' => true, 'order' => 10, 'target_url' => 'https://uamshealth.com']);

    expect(domainRuleFor('agec.org')['order'])->toBe(10)
        ->and(domainRuleFor('uams.flu')['order'])->toBe(RedirectDomainRules::WHOLE_DOMAIN_ORDER);
});

it('returns the hosts sorted, whatever order the entry titles sort in', function (): void {
    // The host's title format sorts titles in host order, so read a collection
    // titled by hand, where the first title names the last host
    $collection = Collection::make('hand_titled_domains');
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();

    foreach (['Alpha' => 'zeta.org', 'Beta' => 'agec.org'] as $title => $host) {
        $entry = Entry::make();
        Assert::assertInstanceOf(StatamicEntry::class, $entry);
        $entry->collection('hand_titled_domains');
        $entry->locale('default');
        $entry->published(true);
        $entry->data(['title' => $title, 'host' => $host, 'source' => '/a', 'target_url' => 'https://uams.edu']);
        $entry->save();
    }

    expect(array_keys((new RedirectDomainRules)->read('hand_titled_domains')['rules']))->toBe(['agec.org', 'zeta.org']);
});

it('normalizes a host with surrounding space, capitals and a trailing dot, and refuses a non-string', function (): void {
    expect(RedirectDomainRules::normalizeHost('  Agec.Org. '))->toBe('agec.org')
        ->and(RedirectDomainRules::normalizeHost(['agec.org']))->toBeNull();
});

it('names an entry whose host is not a domain, and leaves it out', function (): void {
    $entry = domainRulesEntry(['host' => 'not a domain', 'source' => '/a', 'target_url' => 'https://agec.uams.edu']);

    $read = (new RedirectDomainRules)->read();

    expect($read['invalid_hosts'])->toBe([$entry->id()])
        ->and($read['rules'])->toBe([]);
});

it('counts every unresolved entry target on a host', function (): void {
    domainRulesEntry(['host' => 'agec.org', 'source' => '/a', 'target_type' => 'entry', 'target_entry' => ['missing-1']]);
    domainRulesEntry(['host' => 'agec.org', 'source' => '/b', 'target_type' => 'entry', 'target_entry' => ['missing-2']]);

    expect((new RedirectDomainRules)->read()['unresolved_targets'])->toBe(['agec.org' => 2]);
});

it('leaves a URL target alone even when a stale target entry is stored beside it', function (): void {
    $destination = domainRulesDestination('default', '/{slug}');

    domainRulesEntry([
        'host' => 'agec.org',
        'source' => '/a',
        'target_type' => 'url',
        'target_url' => 'https://agec.uams.edu',
        'target_entry' => [$destination->id()],
    ]);

    expect(domainRuleFor('agec.org'))->toMatchArray(['target_type' => 'url', 'target_url' => 'https://agec.uams.edu']);
});

it('leaves an entry target with no URL to the origin, and counts it', function (): void {
    $destination = domainRulesDestination('default', null);

    domainRulesEntry(['host' => 'agec.org', 'source' => '/a', 'target_type' => 'entry', 'target_entry' => [$destination->id()]]);

    $read = (new RedirectDomainRules)->read();

    expect($read['rules']['agec.org'][0])->toMatchArray(['target_type' => 'entry', 'target_url' => null])
        ->and($read['unresolved_targets'])->toBe(['agec.org' => 1]);
});

it("resolves an entry target to its localization on the redirect's site, or else to the entry itself", function (): void {
    config()->set('statamic.system.multisite', true);
    Site::setSites([
        'default' => ['name' => 'Enterprise', 'url' => '/', 'locale' => 'en_US'],
        'excelsior' => ['name' => 'Excelsior', 'url' => '/excelsior/', 'locale' => 'en_US'],
    ]);

    // One destination is localized on the redirect's own site; the other is not
    $localized = domainRulesDestination('excelsior', '/{slug}', ['default', 'excelsior']);
    $localization = $localized->makeLocalization('default');
    Assert::assertInstanceOf(StatamicEntry::class, $localization);
    $localization->save();

    $unlocalized = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $unlocalized);
    $unlocalized->collection('routed_destinations');
    $unlocalized->locale('excelsior');
    $unlocalized->slug('archive');
    $unlocalized->data(['title' => 'Archive']);
    $unlocalized->save();

    domainRulesEntry(['host' => 'agec.org', 'source' => '/a', 'target_type' => 'entry', 'target_entry' => [$localized->id()]]);
    domainRulesEntry(['host' => 'uams.flu', 'source' => '/a', 'target_type' => 'entry', 'target_entry' => [$unlocalized->id()]]);

    expect(domainRuleFor('agec.org')['target_url'])->toBe($localization->absoluteUrl())
        ->and(domainRuleFor('agec.org')['target_url'])->not->toBe($localized->absoluteUrl())
        ->and(domainRuleFor('uams.flu')['target_url'])->toBe($unlocalized->absoluteUrl());
});
