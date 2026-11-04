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
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\StacheIsolation;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * How `redirects:edge-manifest` handles its redirect domain inputs and writes
 * its files: an empty option value, the directory it creates, the paths and
 * lines it reports, and the exact bytes of a manifest. The manifest test in
 * `Redirects/` covers what a manifest holds; these pin the command's own output.
 *
 * @command  vendor/bin/pest tests/Integration/Console/GenerateEdgeRedirectManifestTest.php
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

    File::deleteDirectory(edgeManifestDirectory());
});

afterEach(function (): void {
    File::deleteDirectory(edgeManifestDirectory());
});

/**
 * This worker's own directory to write manifests into.
 */
function edgeManifestDirectory(): string
{
    return storage_path('framework/testing/edge-manifest-command/'.StacheIsolation::segment(getenv('TEST_TOKEN')));
}

/**
 * Save a published whole-domain entry for a host.
 *
 * @param  string  $host  The entry's host.
 * @param  array<string, mixed>  $data  Fields to add or override.
 */
function commandEntry(string $host, array $data = []): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data([
        'host' => $host,
        'whole_domain' => true,
        'target_type' => 'url',
        'target_url' => 'https://uamshealth.com/a/b',
        ...$data,
    ]);
    $entry->save();

    return $entry;
}

/**
 * Run the command and return its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $arguments  The command's arguments and options.
 * @return array{0: int, 1: string} The exit code and the output.
 */
function runEdgeManifest(array $arguments): array
{
    $code = Artisan::call('redirects:edge-manifest', $arguments);

    return [$code, Artisan::output()];
}

it('reads an empty --host as no host at all', function (): void {
    commandEntry('agec.org');

    [$code, $output] = runEdgeManifest(['--host' => '']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Pass a site handle, --host=<domain>, or --hosts.')
        ->and($output)->not->toContain('No redirect domain entries');
});

it('stops at the first refusal when no input is given', function (): void {
    commandEntry('agec.org');

    [$code, $output] = runEdgeManifest([]);

    expect($code)->toBe(1)
        ->and($output)->not->toContain('needs --output=<directory>');
});

it('reads an empty --output as no directory for every host', function (): void {
    commandEntry('agec.org');

    [$code, $output] = runEdgeManifest(['--hosts' => true, '--output' => '']);

    expect($code)->toBe(1)
        ->and($output)->toContain('--hosts writes one file per domain, so it needs --output=<directory>.');
});

it('creates a nested directory for every host, and names each file and the count it wrote', function (): void {
    commandEntry('agec.org');
    commandEntry('uams.flu');
    $nested = edgeManifestDirectory().'/deploy/edge';

    [$code, $output] = runEdgeManifest(['--hosts' => true, '--output' => $nested]);

    expect($code)->toBe(0)
        ->and(File::exists($nested.'/agec.org.json'))->toBeTrue()
        ->and(File::exists($nested.'/uams.flu.json'))->toBeTrue()
        ->and($output)->toContain("Wrote the edge redirect manifest for [agec.org] to [{$nested}/agec.org.json].")
        ->and($output)->toContain("Wrote 2 redirect domain manifest(s) to [{$nested}].");
});

it('writes into a directory that already exists', function (): void {
    commandEntry('agec.org');
    File::ensureDirectoryExists(edgeManifestDirectory());

    [$code, $output] = runEdgeManifest(['--hosts' => true, '--output' => edgeManifestDirectory()]);

    expect($code)->toBe(0)
        ->and($output)->not->toContain('Could not create')
        ->and(File::exists(edgeManifestDirectory().'/agec.org.json'))->toBeTrue();
});

it('joins a directory given with a trailing slash to each file name with one slash', function (): void {
    commandEntry('agec.org');
    $directory = edgeManifestDirectory();

    [, $output] = runEdgeManifest(['--hosts' => true, '--output' => $directory.'/']);

    expect($output)->toContain("to [{$directory}/agec.org.json].");
});

it('warns about unresolved entry targets on each host it writes, and only those', function (): void {
    commandEntry('agec.org', ['target_type' => 'entry', 'target_entry' => ['missing']]);
    commandEntry('uams.flu');

    [, $output] = runEdgeManifest(['--hosts' => true, '--output' => edgeManifestDirectory()]);

    expect($output)->toContain('[agec.org]: 1 entry target(s) could not be resolved to a URL, so the manifest sends them to the origin.')
        ->and($output)->not->toContain('[uams.flu]: ');
});

it('writes a manifest file as pretty-printed JSON with unescaped slashes and a final newline', function (): void {
    commandEntry('agec.org');
    $file = edgeManifestDirectory().'/agec.json';
    File::ensureDirectoryExists(edgeManifestDirectory());

    [$code, $output] = runEdgeManifest(['--host' => 'agec.org', '--output' => $file]);
    $written = File::get($file);

    $expected = json_encode(json_decode($written, true, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    expect($code)->toBe(0)
        ->and($written)->toBe($expected)
        ->and($written)->toContain('"https://uamshealth.com/a/b"')
        ->and($written)->toContain("\n    \"host\": \"agec.org\"")
        ->and($output)->toContain("Wrote the edge redirect manifest for [agec.org] to [{$file}].");
});

/**
 * Run the command with warnings left out of error reporting, so a failed
 * `mkdir()` or `file_put_contents()` returns false instead of throwing, as it
 * does where the host does not report warnings.
 *
 * @param  array<string, mixed>  $arguments  The command's arguments and options.
 * @return array{0: int, 1: string} The exit code and the output.
 */
function runEdgeManifestUnwarned(array $arguments): array
{
    $reporting = error_reporting(E_ALL & ~E_WARNING);

    try {
        return runEdgeManifest($arguments);
    } finally {
        error_reporting($reporting);
    }
}

it('fails, naming the directory, when it cannot create the directory for every host', function (): void {
    commandEntry('agec.org');
    File::ensureDirectoryExists(edgeManifestDirectory());
    File::put(edgeManifestDirectory().'/occupied', 'a file, not a directory');
    $blocked = edgeManifestDirectory().'/occupied/edge';

    [$code, $output] = runEdgeManifestUnwarned(['--hosts' => true, '--output' => $blocked]);

    expect($code)->toBe(1)
        ->and($output)->toContain("Could not create [{$blocked}].")
        ->and($output)->not->toContain('Could not write')
        ->and($output)->not->toContain('Wrote');
});

it('fails, naming the file, when it cannot write one host of every host', function (): void {
    commandEntry('agec.org');
    commandEntry('uams.flu');
    File::ensureDirectoryExists(edgeManifestDirectory().'/agec.org.json');

    [$code, $output] = runEdgeManifestUnwarned(['--hosts' => true, '--output' => edgeManifestDirectory()]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not write ['.edgeManifestDirectory().'/agec.org.json].')
        ->and($output)->not->toContain('Wrote')
        ->and(File::exists(edgeManifestDirectory().'/uams.flu.json'))->toBeFalse();
});
