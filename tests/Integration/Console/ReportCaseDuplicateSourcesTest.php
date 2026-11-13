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
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

uses(IsolatesStache::class);

/**
 * Integration coverage for `redirects:report-case-duplicates` (UAMS-Web/aether-redirects#17):
 * it names the sources on a host that differ only in case, counts only rules that can serve,
 * changes nothing, and fails so a script can stop on a pair.
 *
 * @command  vendor/bin/pest tests/Integration/Console/ReportCaseDuplicateSourcesTest.php
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
});

/**
 * Save a published rule on a host.
 *
 * @param  array<string, mixed>  $data  The entry's fields, over the defaults.
 */
function caseDupRule(string $host, string $source, array $data = []): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data(RedirectDomainRecords::refer([
        'host' => $host,
        'source' => $source,
        'enabled' => true,
        'whole_domain' => false,
        'use_regex' => false,
        'target_type' => 'url',
        'target_url' => 'https://www.uams.edu/flu',
        'response' => '301',
        'order' => 30,
        ...$data,
    ]));
    $entry->save();

    return $entry;
}

it('names each source written in more than one case on a host, and fails', function (): void {
    caseDupRule('uams.health', '/aB3xY9q');
    caseDupRule('uams.health', '/AB3XY9Q');
    caseDupRule('uams.health', '/ab3xy9q');
    caseDupRule('uams.info', '/Flu');
    caseDupRule('uams.info', '/flu');
    caseDupRule('uams.info', '/unique');

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain("uams.health: /aB3xY9q, /AB3XY9Q, /ab3xy9q\n")
        ->and($output)->toContain("uams.info: /Flu, /flu\n")
        ->and($output)->toMatch('/(?<![-\d])2 source\(s\) differ only in case\./')
        ->and($output)->not->toContain('/unique');
});

it('succeeds when no two sources differ only in case', function (): void {
    caseDupRule('uams.health', '/one');
    caseDupRule('uams.health', '/two');

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(0)
        ->and(Artisan::output())->toBe("No two sources differ only in case.\n");
});

it('does not count a pair that spells the source the same, or the same spelling on two hosts', function (): void {
    caseDupRule('uams.health', '/same');
    caseDupRule('uams.health', '/same');
    caseDupRule('uams.health', '/Other');
    caseDupRule('uams.info', '/other');

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(0);
});

it('leaves out rules that cannot serve or are not exact back-halves', function (array $second): void {
    /** @var array<string, mixed> $second */
    caseDupRule('uams.health', '/Flu');
    caseDupRule('uams.health', '/flu', $second);

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(0);
})->with([
    'disabled' => [['enabled' => false]],
    'rejected' => [['status' => 'rejected']],
    'a regular expression' => [['use_regex' => true]],
]);

it('leaves out a wildcard source', function (): void {
    caseDupRule('uams.health', '/Flu/*');
    caseDupRule('uams.health', '/flu/*');

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(0);
});

it('counts a pending request, which may serve once approved', function (): void {
    caseDupRule('uams.health', '/Flu');
    caseDupRule('uams.health', '/flu', ['status' => 'pending']);

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(1);
});

it('reads only, leaving the entries as they were', function (): void {
    $first = caseDupRule('uams.health', '/Flu');
    $second = caseDupRule('uams.health', '/flu');

    Artisan::call('redirects:report-case-duplicates');

    expect(RedirectDomainRecords::stored($first, 'source'))->toBe('/Flu')
        ->and(RedirectDomainRecords::stored($second, 'source'))->toBe('/flu')
        ->and(RedirectDomainRecords::entries(RedirectDomainRules::COLLECTION))->toHaveCount(2);
});

it('keeps looking after a rule it leaves out', function (): void {
    // Entries read in title order, so the disabled rule comes first
    caseDupRule('uams.health', '/0-off', ['enabled' => false]);
    caseDupRule('uams.health', '/Flu');
    caseDupRule('uams.health', '/flu');

    expect(Artisan::call('redirects:report-case-duplicates'))->toBe(1)
        ->and(Artisan::output())->toContain("uams.health: /Flu, /flu\n");
});
