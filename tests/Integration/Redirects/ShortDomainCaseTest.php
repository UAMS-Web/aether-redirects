<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionsStore;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * Coverage for UAMS-Web/aether-redirects#17: a back-half on a short domain resolves
 * however its case is typed, in each place the add-on serves these redirects: the
 * application middleware, and the edge manifest an edge evaluates by its stated rules.
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/ShortDomainCaseTest.php
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
 * Save a published rule on a short domain.
 *
 * @param  array<string, mixed>  $data  The entry's fields, over the defaults.
 */
function shortCaseRule(string $host, string $source, array $data = []): StatamicEntry
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
        'target_url' => 'https://www.uams.edu/Clinics/Flu',
        'response' => '301',
        'order' => 30,
        ...$data,
    ]));
    $entry->save();

    return $entry;
}

/**
 * The target the application redirects a request to, or the status when it does not redirect.
 */
function shortCaseServed(string $url): string
{
    $middleware = app(ServeRedirectDomainRequests::class);
    Assert::assertInstanceOf(ServeRedirectDomainRequests::class, $middleware);

    $response = $middleware->handle(Request::create($url), fn (Request $request): Response => response('SITE CONTENT', 200));

    return $response instanceof RedirectResponse ? $response->getTargetUrl() : (string) $response->getStatusCode();
}

/**
 * The first exact manifest entry an edge would match for a path, evaluated by the manifest's own stated
 * rule: ASCII-lowercase both sides, and compare the path alone when the entry carries no query.
 *
 * @param  array{exact: list<array<string, mixed>>, ...}  $manifest
 * @return array<string, mixed>|null
 */
function shortCaseEdgeMatch(array $manifest, string $path): ?array
{
    foreach ($manifest['exact'] as $entry) {
        $source = $entry['source'] ?? null;

        if (\is_string($source) && strtolower($source) === strtolower($path)) {
            return $entry;
        }
    }

    return null;
}

/**
 * The same destination however the back-half is cased: the three kinds of back-half on each short domain.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function shortCaseBackHalves(): array
{
    $cases = [];

    foreach (['uams.info', 'uams.health', 'uamsbaptist.health', 'go.uams.edu'] as $host) {
        $cases[$host.' generated'] = [$host, '/aB3xY9q'];
        $cases[$host.' typed'] = [$host, '/Flu-Shot'];
        $cases[$host.' QR'] = [$host, '/campaign-QR'];
    }

    return $cases;
}

it('serves a back-half however its case is typed, with the destination and tracking unchanged', function (string $host, string $source): void {
    shortCaseRule($host, $source, ['target_query_string' => 'utm_source=Flyer&utm_medium=QR']);

    $expected = 'https://www.uams.edu/Clinics/Flu?utm_source=Flyer&utm_medium=QR';

    foreach ([$source, strtolower($source), strtoupper($source)] as $typed) {
        expect(shortCaseServed('https://'.$host.$typed))->toBe($expected);
    }

    // A request that carries its own query forwards it, whatever the case of the path
    expect(shortCaseServed('https://'.$host.strtoupper($source).'?ref=Mail'))->toBe($expected.'&ref=Mail')
        ->and(shortCaseServed('https://'.$host.'/other'))->toBe('404');
})->with(shortCaseBackHalves());

it('lists a back-half in the edge manifest once, and an edge evaluating its stated rule matches every case', function (string $host, string $source): void {
    shortCaseRule($host, $source, ['target_query_string' => 'utm_source=Flyer']);

    $rules = app(RedirectDomainRules::class)->read()['rules'][$host];
    $manifest = app(EdgeRedirectManifestBuilder::class)->forHost($host, $rules);

    expect($manifest['semantics']['exact'])->toContain('ASCII-lowercasing both sides')
        ->and($manifest['exact'])->toHaveCount(1);

    foreach ([$source, strtolower($source), strtoupper($source)] as $typed) {
        $matched = shortCaseEdgeMatch($manifest, $typed);

        Assert::assertIsArray($matched);
        expect($matched['source'])->toBe($source)
            ->and($matched['target'] ?? null)->toBe('https://www.uams.edu/Clinics/Flu')
            ->and($matched['query_string'] ?? null)->toBe('utm_source=Flyer');
    }

    expect(shortCaseEdgeMatch($manifest, '/other'))->toBeNull();
})->with(shortCaseBackHalves());

it('keeps serving the first rule when two sources differ only in case, and the manifest withholds the second', function (): void {
    shortCaseRule('uams.health', '/Flu', ['target_url' => 'https://uamshealth.com/first', 'order' => 10]);
    shortCaseRule('uams.health', '/FLU', ['target_url' => 'https://uamshealth.com/second', 'order' => 20]);

    $rules = app(RedirectDomainRules::class)->read()['rules']['uams.health'];
    $manifest = app(EdgeRedirectManifestBuilder::class)->forHost('uams.health', $rules);

    expect(shortCaseServed('https://uams.health/flu'))->toBe('https://uamshealth.com/first')
        ->and($manifest['exact'])->toHaveCount(1)
        ->and($manifest['withheld']['shadowed'])->toBe(1);
});
