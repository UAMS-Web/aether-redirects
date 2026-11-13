<?php

declare(strict_types=1);

use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;
use UAMSWeb\Aether\Redirects\Tests\Support\StacheIsolation;
use UAMSWeb\Aether\Redirects\Tests\TestCase;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * Coverage for UAMS-Web/uams-statamic-archive#2678: redirects for domains that are not Statamic sites, authored
 * in the `redirect_domains` collection and written as one edge manifest per host,
 * as UAMS-Web/uams-statamic-archive#2594 decided.
 *
 * The collection is built from a copy of the host's config file and blueprint,
 * so the title format and slug setting under test are the ones the host ships. The parity case
 * compares a source entry's manifest rule with the rule the `redirects` global set
 * produces for the same fields, through the same builder, so the two authoring
 * surfaces cannot drift apart.
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/RedirectDomainManifestTest.php
 */
beforeEach(function (): void {
    // Copy the host's blueprint into the isolated blueprint directory; the host
    // owns it, like the collection's config, and these are the fields read here
    $blueprint = 'collections/redirect_domains/redirect_domain.yaml';
    File::ensureDirectoryExists(dirname(BlueprintFacade::directory().'/'.$blueprint));
    File::copy(__DIR__.'/../../Fixtures/blueprints/'.$blueprint, BlueprintFacade::directory().'/'.$blueprint);

    // The blueprint's target URL field imports the host's common URL field
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
 * Save a published redirect domain entry.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 * @param  bool  $published  Whether the entry is published.
 */
function redirectDomainEntry(array $data, bool $published = true): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published($published);
    $entry->data(RedirectDomainRecords::refer([
        'enabled' => true,
        'whole_domain' => false,
        'use_regex' => false,
        'target_type' => 'url',
        'response' => '301',
        'order' => 30,
        ...$data,
    ]));
    $entry->save();

    return $entry;
}

/**
 * Save a published entry in a routed destination collection, creating the
 * collection the first time.
 *
 * @param  string  $slug  The entry's slug.
 */
function redirectDestinationEntry(string $slug): StatamicEntry
{
    // Create the routed collection the destination lives in
    if (Collection::findByHandle('redirect_destinations') === null) {
        $collection = Collection::make('redirect_destinations');
        Assert::assertInstanceOf(StatamicCollection::class, $collection);

        $collection->sites(['default']);
        $collection->routes('/{slug}');
        $collection->save();
    }

    // Save the destination entry
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection('redirect_destinations');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(['title' => ucfirst($slug)]);
    $entry->save();

    return $entry;
}

/**
 * The `redirects:edge-manifest` command, pending, for expectations on its output.
 *
 * @param  TestCase  $case  The running test.
 * @param  array<string, mixed>  $arguments  The command's arguments and options.
 */
function redirectDomainCommand(TestCase $case, array $arguments): PendingCommand
{
    $pending = $case->artisan('redirects:edge-manifest', $arguments);
    Assert::assertInstanceOf(PendingCommand::class, $pending);

    return $pending;
}

/**
 * The manifest for one host, from the collection as it stands.
 *
 * @param  string  $host  The host.
 * @return array{host: string, reserved_prefixes: list<string>, exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>, prefix_complete: bool, withheld: array<string, int>} The manifest.
 */
function redirectDomainManifest(string $host): array
{
    $read = app(RedirectDomainRules::class)->read();

    expect($read['rules'])->toHaveKey($host);

    /** @var array{host: string, reserved_prefixes: list<string>, exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>, prefix_complete: bool, withheld: array<string, int>} $manifest */
    $manifest = app(EdgeRedirectManifestBuilder::class)->forHost($host, $read['rules'][$host]);

    return $manifest;
}

/**
 * One rule from a manifest's exact or prefix list.
 *
 * @param  list<array<string, mixed>>  $rules  The list.
 * @param  int  $index  The rule's position.
 * @return array<string, mixed> The rule.
 */
function redirectDomainRule(array $rules, int $index = 0): array
{
    expect($rules)->toHaveKey($index);

    return $rules[$index];
}

it('ships a default-site collection without slugs, titled by host', function (): void {
    $collection = Collection::findByHandle(RedirectDomainRules::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $sites = $collection->sites();
    Assert::assertInstanceOf(SupportCollection::class, $sites);

    expect($sites->all())->toBe(['default'])
        ->and($collection->requiresSlugs())->toBeFalse()
        ->and($collection->route('default'))->toBeNull();

    // The title is generated from the host and the source, which is what makes a host searchable
    $whole = redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'target_url' => 'https://agec.uams.edu']);
    $source = redirectDomainEntry(['host' => 'uams.health', 'source' => '/flu', 'target_url' => 'https://uamshealth.com/flu']);

    $wholeId = $whole->id();
    $sourceId = $source->id();
    Assert::assertIsString($wholeId);
    Assert::assertIsString($sourceId);

    $wholeFresh = Entry::find($wholeId);
    $sourceFresh = Entry::find($sourceId);
    Assert::assertInstanceOf(StatamicEntry::class, $wholeFresh);
    Assert::assertInstanceOf(StatamicEntry::class, $sourceFresh);

    expect($wholeFresh->get('title'))->toBe('agec.org (whole domain)')
        ->and($sourceFresh->get('title'))->toBe('uams.health/flu');
});

it('gives the blueprint every field the ticket names, with the host listable and filterable', function (): void {
    $collection = Collection::findByHandle(RedirectDomainRules::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $blueprint = $collection->entryBlueprint();
    Assert::assertInstanceOf(Blueprint::class, $blueprint);

    foreach (['host', 'whole_domain', 'source', 'use_regex', 'target_type', 'target_entry', 'target_url', 'target_query_string', 'response', 'enabled', 'order'] as $handle) {
        expect($blueprint->hasField($handle))->toBeTrue();
    }

    $host = $blueprint->field('host');
    Assert::assertInstanceOf(Field::class, $host);

    expect($host->isListable())->toBeTrue()
        ->and($host->isFilterable())->toBeTrue();
});

it('lets an editor find every entry for one host by searching and by filtering on the host', function (): void {
    foreach (['/flu', '/covid', '/clinics/*'] as $source) {
        redirectDomainEntry(['host' => 'uams.health', 'source' => $source, 'target_url' => 'https://uamshealth.com/']);
    }

    redirectDomainEntry(['host' => 'uams.info', 'source' => '/flu', 'target_url' => 'https://uams.edu/']);
    redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'target_url' => 'https://agec.uams.edu']);

    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->email('redirect-domains@example.com');
    $user->makeSuper();
    $user->save();

    /**
     * The sorted titles the CP entry listing returns for a query.
     *
     * @param  array<string, string>  $query  The listing's query parameters.
     * @return list<string> The titles.
     */
    $listing = function (array $query) use ($user): array {
        $url = cp_route('collections.entries.index', RedirectDomainRules::COLLECTION);
        Assert::assertIsString($url);

        $data = $this->actingAs($user)
            ->getJson($url.'?'.http_build_query($query))
            ->assertOk()
            ->json('data');
        Assert::assertIsArray($data);

        $titles = array_map(fn (mixed $row): string => is_array($row) && is_string($row['title'] ?? null) ? $row['title'] : '', $data);
        sort($titles);

        return $titles;
    };

    $expected = ['uams.health/clinics/*', 'uams.health/covid', 'uams.health/flu'];

    // Searching the listing matches on the generated title, which leads with the host
    expect($listing(['search' => 'uams.health']))->toBe($expected);

    // Filtering on the host field returns the same set and nothing from another host
    $filters = base64_encode((string) json_encode(['fields' => ['host' => ['operator' => '=', 'value' => [RedirectDomainRecords::idFor('uams.health')]]]]));

    expect($listing(['filters' => $filters]))->toBe($expected);
});

it('writes a whole-domain entry as exactly one rule that sends every path to its target', function (): void {
    // A trailing wildcard on a whole-domain target is dropped, so nothing is appended
    redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'source' => '/ignored', 'target_url' => 'https://agec.uams.edu/about/*']);

    $manifest = redirectDomainManifest('agec.org');

    expect($manifest['host'])->toBe('agec.org')
        ->and($manifest)->not->toHaveKey('site')
        ->and($manifest['reserved_prefixes'])->toBe([])
        ->and($manifest['exact'])->toBe([])
        ->and($manifest['prefix'])->toBe([[
            'base' => '/',
            'action' => 'redirect',
            'status' => 301,
            'target' => 'https://agec.uams.edu/about/',
            'append_remainder' => false,
            'query_string' => '',
            'forward_query' => true,
        ]]);
});

it('writes a source entry as the same rule the redirects global set writes for those fields', function (array $fields): void {
    /** @var array<string, mixed> $fields */
    redirectDomainEntry(['host' => 'uams.health', ...$fields]);

    /** @var array<string, mixed> $fromGlobalSet */
    $fromGlobalSet = app(EdgeRedirectManifestBuilder::class)->build([[
        'enabled' => true,
        'use_regex' => false,
        'target_type' => 'url',
        'response' => '301',
        'order' => 30,
        ...$fields,
    ]], 'default');

    $fromCollection = redirectDomainManifest('uams.health');

    expect($fromCollection['exact'])->toBe($fromGlobalSet['exact'])
        ->and($fromCollection['prefix'])->toBe($fromGlobalSet['prefix'])
        ->and($fromCollection['prefix_complete'])->toBe($fromGlobalSet['prefix_complete'])
        ->and($fromCollection['withheld'])->toBe($fromGlobalSet['withheld']);
})->with([
    'an exact source' => [['source' => '/Flu-Shots', 'target_url' => 'https://uamshealth.com/flu', 'response' => '302']],
    'a wildcard with a remainder target' => [['source' => '/clinics/*', 'target_url' => 'https://uamshealth.com/locations/*']],
    'a source with its own query' => [['source' => '/go?id=7', 'target_url' => 'https://uams.edu/giving']],
    'a regex source, withheld' => [['source' => '^/old/(.*)$', 'use_regex' => true, 'target_url' => 'https://uams.edu/$1']],
    'a disabled source, withheld' => [['source' => '/gone', 'enabled' => false, 'target_url' => 'https://uams.edu/']],
]);

it('resolves an entry target to its current URL, and follows the entry when it moves', function (): void {
    $destination = redirectDestinationEntry('myeloma');

    redirectDomainEntry([
        'host' => 'myeloma.org',
        'whole_domain' => true,
        'target_type' => 'entry',
        'target_entry' => [$destination->id()],
    ]);

    $before = redirectDomainRule(redirectDomainManifest('myeloma.org')['prefix']);

    expect($before['action'])->toBe('redirect')
        ->and($before['target'])->toBe(url('/myeloma'));

    // Moving the destination changes the next manifest, with no edit to the redirect
    $destination->slug('myeloma-program');
    $destination->save();

    expect(redirectDomainRule(redirectDomainManifest('myeloma.org')['prefix'])['target'])->toBe(url('/myeloma-program'));
});

it("carries an entry target's query string as the global set does", function (): void {
    $destination = redirectDestinationEntry('giving');

    redirectDomainEntry([
        'host' => 'giving.uams.org',
        'source' => '/give',
        'target_type' => 'entry',
        'target_entry' => [$destination->id()],
        'target_query_string' => '?utm_source=giving',
    ]);

    expect(redirectDomainRule(redirectDomainManifest('giving.uams.org')['exact']))->toMatchArray([
        'action' => 'redirect',
        'target' => url('/giving'),
        'query_string' => 'utm_source=giving',
    ]);
});

it('leaves an entry target it cannot resolve to the origin, and says so', function (): void {
    redirectDomainEntry([
        'host' => 'dining.uams.edu',
        'whole_domain' => true,
        'target_type' => 'entry',
        'target_entry' => ['no-such-entry'],
    ]);

    $read = app(RedirectDomainRules::class)->read();

    expect($read['unresolved_targets'])->toBe(['dining.uams.edu' => 1])
        ->and(redirectDomainRule(redirectDomainManifest('dining.uams.edu')['prefix'])['action'])->toBe('origin');

    redirectDomainCommand($this, ['--host' => 'dining.uams.edu'])
        ->expectsOutputToContain('1 entry target(s) could not be resolved')
        ->assertSuccessful();
});

it("writes one manifest per host, each carrying only that host's rules", function (): void {
    redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'target_url' => 'https://agec.uams.edu']);
    redirectDomainEntry(['host' => 'uams.health', 'source' => '/flu', 'target_url' => 'https://uamshealth.com/flu']);
    redirectDomainEntry(['host' => 'UAMS.Health.', 'source' => '/covid', 'target_url' => 'https://uamshealth.com/covid']);
    redirectDomainEntry(['host' => 'uams.health', 'source' => '/draft', 'target_url' => 'https://uamshealth.com/'], published: false);
    // The domain list refuses a host that is not a domain, so the stored value is changed behind its back
    $bad = redirectDomainEntry(['host' => 'agec.org', 'source' => '/x', 'target_url' => 'https://uams.edu/']);
    $bad->set('host', 'https://bad host/');
    $bad->saveQuietly();

    $directory = storage_path('framework/testing/redirect-domain-manifests/'.StacheIsolation::segment(getenv('TEST_TOKEN')));
    File::deleteDirectory($directory);
    StacheIsolation::ensureDirectory(dirname($directory));

    try {
        expect(Artisan::call('redirects:edge-manifest', ['--hosts' => true, '--output' => $directory]))->toBe(0)
            ->and(Artisan::output())->toContain('its domain is not valid');

        $files = collect(File::files($directory))->map(fn (SplFileInfo $file): string => $file->getFilename())->sort()->values()->all();

        expect($files)->toBe(['agec.org.json', 'uams.health.json']);

        /** @var array{host: string, exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>} $agec */
        $agec = json_decode(File::get($directory.'/agec.org.json'), true, flags: JSON_THROW_ON_ERROR);
        /** @var array{host: string, exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>} $health */
        $health = json_decode(File::get($directory.'/uams.health.json'), true, flags: JSON_THROW_ON_ERROR);

        // Each host's manifest holds its own rules and no other host's
        expect($agec['host'])->toBe('agec.org')
            ->and($agec['exact'])->toBe([])
            ->and(array_column($agec['prefix'], 'target'))->toBe(['https://agec.uams.edu'])
            ->and($health['host'])->toBe('uams.health')
            ->and($health['prefix'])->toBe([])
            // Rules with the same order are listed by title
            ->and(array_column($health['exact'], 'source'))->toBe(['/covid', '/flu']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it("prints one host's manifest, and refuses a host with no entries", function (): void {
    redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'target_url' => 'https://agec.uams.edu']);

    expect(Artisan::call('redirects:edge-manifest', ['--host' => 'AGEC.org']))->toBe(0);

    /** @var array<string, mixed> $manifest */
    $manifest = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['host'])->toBe('agec.org');

    redirectDomainCommand($this, ['--host' => 'nowhere.org'])
        ->expectsOutputToContain('No redirect domain entries for [nowhere.org]')
        ->assertFailed();
});

it('takes exactly one of a site, --host and --hosts', function (array $arguments): void {
    /** @var array<string, mixed> $arguments */
    redirectDomainCommand($this, $arguments)
        ->expectsOutputToContain('Pass a site handle, --host=<domain>, or --hosts.')
        ->assertFailed();
})->with([
    'none' => [[]],
    'a site and a host' => [['site' => 'default', '--host' => 'agec.org']],
    'a host and every host' => [['--host' => 'agec.org', '--hosts' => true]],
]);

it('needs a directory to write every host into', function (): void {
    redirectDomainEntry(['host' => 'agec.org', 'whole_domain' => true, 'target_url' => 'https://agec.uams.edu']);

    redirectDomainCommand($this, ['--hosts' => true])
        ->expectsOutputToContain('needs --output=<directory>')
        ->assertFailed();
});
