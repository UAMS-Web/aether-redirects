<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

uses(IsolatesStache::class);

/**
 * Integration coverage for `redirects:migrate-domain-hosts` (UAMS-Web/aether-redirects#24):
 * one record per distinct stored host, each entry rewritten to its record, the same rules
 * served before and after, and nothing changed on a second run.
 *
 * @command  vendor/bin/pest tests/Integration/Console/MigrateRedirectDomainHostsTest.php
 */
beforeEach(function (): void {
    $blueprint = 'collections/redirect_domains/redirect_domain.yaml';
    File::ensureDirectoryExists(dirname(BlueprintFacade::directory().'/'.$blueprint));
    File::copy(__DIR__.'/../../Fixtures/blueprints/'.$blueprint, BlueprintFacade::directory().'/'.$blueprint);
    File::ensureDirectoryExists(Fieldset::directory());
    File::copy(__DIR__.'/../../Fixtures/fieldsets/uamsweb_common.yaml', Fieldset::directory().'/uamsweb_common.yaml');

    $path = __DIR__.'/../../Fixtures/redirect_domains.yaml';
    $store = Stache::store('collections');
    Assert::assertInstanceOf(CollectionsStore::class, $store);

    $collection = $store->makeItemFromFile($path, File::get($path));
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $collection->save();

    RedirectDomainRecords::register();
});

/**
 * Save a redirect domain entry holding the given host exactly as stored, the way an
 * entry written before the domain list holds it.
 *
 * @param  mixed  $host  The stored host.
 * @param  string  $source  The rule's source path.
 */
function legacyDomainEntry(mixed $host, string $source): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data(['host' => $host, 'source' => $source, 'target_type' => 'url', 'target_url' => 'https://uams.edu'.$source, 'enabled' => true, 'response' => '301', 'order' => 30]);
    $entry->saveQuietly();

    return $entry;
}

/**
 * The rules the engine serves, as the edge manifests carry them, keyed by host.
 *
 * @return array<string, array<string, mixed>>
 */
function migrateServedManifests(): array
{
    $read = (new RedirectDomainRules)->read();
    $manifests = [];

    foreach ($read['rules'] as $host => $rules) {
        $manifests[$host] = app(EdgeRedirectManifestBuilder::class)->forHost($host, $rules);
    }

    return $manifests;
}

/**
 * The host stored on every redirect domain entry, in a stable order.
 *
 * @return list<mixed>
 */
function migrateStoredHosts(): array
{
    $hosts = array_map(fn (StatamicEntry $entry): mixed => $entry->get('host'), RedirectDomainRecords::entries(RedirectDomainRules::COLLECTION));
    sort($hosts);

    return $hosts;
}

it('creates one record per distinct host and rewrites every entry to its record', function (): void {
    $a = legacyDomainEntry('agec.org', '/a');
    $b = legacyDomainEntry('AGEC.org.', '/b');
    $c = legacyDomainEntry('uams.info', '/c');

    expect(Artisan::call('redirects:migrate-domain-hosts'))->toBe(0)
        ->and(consoleOutput())->toBe("2 domain record(s) created, 3 entries rewritten, 0 skipped.\n");

    RedirectDomainRecords::reload();

    $records = RedirectDomainList::records();

    expect(array_values($records))->toEqualCanonicalizing(['agec.org', 'uams.info']);

    foreach ([[$a, 'agec.org'], [$b, 'agec.org'], [$c, 'uams.info']] as [$entry, $host]) {
        $stored = RedirectDomainRecords::stored($entry, 'host');
        Assert::assertIsString($stored);

        expect($records[$stored])->toBe($host);
    }
});

it('serves the same redirects before and after', function (): void {
    legacyDomainEntry('agec.org', '/a');
    legacyDomainEntry('agec.org', '/b');
    legacyDomainEntry('uams.info', '/c');

    // Before the migration no domain is trusted, so seed the records the migration would
    // create and take the manifests from the string entries, which still resolve
    RedirectDomainRecords::idFor('agec.org');
    RedirectDomainRecords::idFor('uams.info');
    $before = migrateServedManifests();

    Artisan::call('redirects:migrate-domain-hosts');

    expect($before)->not->toBe([])
        ->and(migrateServedManifests())->toBe($before);
});

it('changes nothing when it runs twice', function (): void {
    legacyDomainEntry('agec.org', '/a');

    Artisan::call('redirects:migrate-domain-hosts');
    $records = RedirectDomainList::records();
    $stored = migrateStoredHosts();

    expect(Artisan::call('redirects:migrate-domain-hosts'))->toBe(0)
        ->and(consoleOutput())->toContain('0 domain record(s) created, 0 entries rewritten, 0 skipped.')
        ->and(RedirectDomainList::records())->toBe($records)
        ->and(migrateStoredHosts())->toBe($stored);
});

it('reuses a record the list already holds', function (): void {
    $existing = RedirectDomainRecords::idFor('agec.org');
    $entry = legacyDomainEntry('agec.org', '/a');

    Artisan::call('redirects:migrate-domain-hosts');

    expect(array_keys(RedirectDomainList::records()))->toBe([$existing])
        ->and(RedirectDomainRecords::stored($entry, 'host'))->toBe($existing)
        ->and(consoleOutput())->toContain('0 domain record(s) created, 1 entry rewritten');
});

it('reports an entry whose host is not a valid domain and leaves it as it is', function (mixed $host): void {
    $entry = legacyDomainEntry($host, '/a');

    expect(Artisan::call('redirects:migrate-domain-hosts'))->toBe(0);

    $output = consoleOutput();

    expect($output)->toContain('Entry '.RedirectDomainRecords::id($entry).' has a host that is not a valid domain')
        ->and($output)->toContain('0 domain record(s) created, 0 entries rewritten, 1 skipped.')
        ->and(RedirectDomainRecords::stored($entry, 'host'))->toBe($host)
        ->and(RedirectDomainList::records())->toBe([]);
})->with([
    'text that is not a domain' => ['not a domain'],
    'a missing host' => [null],
]);

it('reports what it would do on a dry run and changes nothing', function (): void {
    $entry = legacyDomainEntry('agec.org', '/a');

    expect(Artisan::call('redirects:migrate-domain-hosts', ['--dry-run' => true]))->toBe(0)
        ->and(consoleOutput())->toContain('Dry run: 1 domain record(s) created, 1 entry rewritten, 0 skipped.')
        ->and(RedirectDomainList::records())->toBe([])
        ->and(RedirectDomainRecords::stored($entry, 'host'))->toBe('agec.org');
});

it('migrates the entries after one that is already migrated', function (): void {
    // Entries are visited by title: the migrated one first, then the two that are not
    $migrated = legacyDomainEntry(RedirectDomainRecords::idFor('agec.org'), 'aaa');
    $first = legacyDomainEntry('agec.org', 'mmm');
    $second = legacyDomainEntry('uams.info', 'zzz');

    Artisan::call('redirects:migrate-domain-hosts');
    RedirectDomainRecords::reload();

    $records = RedirectDomainList::records();

    expect(consoleOutput())->toBe("1 domain record(s) created, 2 entries rewritten, 0 skipped.\n")
        ->and($records[RedirectDomainRecords::storedHost($migrated)])->toBe('agec.org')
        ->and($records[RedirectDomainRecords::storedHost($first)])->toBe('agec.org')
        ->and($records[RedirectDomainRecords::storedHost($second)])->toBe('uams.info');
});

it('migrates the entries after one it skips', function (): void {
    $bad = legacyDomainEntry('not a domain', 'aaa');
    $good = legacyDomainEntry('agec.org', 'zzz');

    Artisan::call('redirects:migrate-domain-hosts');
    RedirectDomainRecords::reload();

    expect(RedirectDomainRecords::stored($bad, 'host'))->toBe('not a domain')
        ->and(array_values(RedirectDomainList::records()))->toBe(['agec.org'])
        ->and(RedirectDomainList::resolve(RedirectDomainRecords::stored($good, 'host'), RedirectDomainList::records()))->toBe('agec.org');
});
