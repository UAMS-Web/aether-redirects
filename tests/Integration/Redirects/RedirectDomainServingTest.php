<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Sites\Site as StatamicSite;
use Statamic\Stache\Stores\CollectionsStore;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * Coverage for UAMS-Web/uams-statamic-archive#2813: the application answers the requests the edge sends on for
 * the vanity redirect domains authored in the `redirect_domains` collection.
 *
 * The middleware is driven directly, with a downstream that stands in for the rest
 * of the application and marks its response as site content, because the isolated
 * Stache base fakes the item filesystem that a full kernel pass discards. One case
 * checks that the middleware is registered globally, so it runs before routing.
 *
 * Where a case claims the engine's behavior, it compares the answer with what
 * `RedirectRuleMatcher` returns for the same rule as a `redirects` global-set row.
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/RedirectDomainServingTest.php
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
 * Save a published redirect domain entry for the serving cases.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function servedRedirectDomainEntry(array $data): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data([
        'enabled' => true,
        'whole_domain' => false,
        'use_regex' => false,
        'target_type' => 'url',
        'response' => '301',
        'order' => 30,
        ...$data,
    ]);
    $entry->save();

    return $entry;
}

/**
 * Send a request through the middleware, with a downstream that answers with a
 * marker standing for site content.
 *
 * @param  string  $url  The absolute request URL.
 */
function serveRedirectDomainRequest(string $url): Response
{
    $middleware = app(ServeRedirectDomainRequests::class);
    Assert::assertInstanceOf(ServeRedirectDomainRequests::class, $middleware);

    return $middleware->handle(
        Request::create($url),
        fn (Request $request): Response => response('SITE CONTENT', 200),
    );
}

/**
 * What the engine returns for one rule written as a `redirects` global-set row.
 *
 * @param  array<string, mixed>  $row  The row.
 * @param  string  $path  The request path.
 * @return array<string, mixed>|null The matched rule, or null.
 */
function engineAnswerForRow(array $row, string $path): ?array
{
    return (new RedirectRuleMatcher)->match([$row], $path, '')[0] ?? null;
}

/**
 * The redirect target of a response, failing when it is not a redirect.
 *
 * @param  Response  $response  The response.
 */
function redirectDomainTarget(Response $response): string
{
    Assert::assertInstanceOf(RedirectResponse::class, $response);

    return $response->getTargetUrl();
}

it('runs before routing, as global middleware', function (): void {
    $kernel = app(HttpKernel::class);
    Assert::assertInstanceOf(Kernel::class, $kernel);

    expect($kernel->hasMiddleware(ServeRedirectDomainRequests::class))->toBeTrue();
});

it('redirects a regex rule exactly as the engine does for the main site', function (): void {
    $fields = [
        'source' => '^/news/(\d+)/(.+)$',
        'use_regex' => true,
        'target_url' => 'https://news.uams.edu/$2?id=$1',
        'response' => '302',
    ];
    servedRedirectDomainEntry(['host' => 'uams.news', ...$fields]);

    $response = serveRedirectDomainRequest('https://uams.news/news/42/flu-season');

    // Compare with the engine's answer for the same fields as a global-set row
    $engine = engineAnswerForRow([...$fields, 'target_type' => 'url', 'enabled' => true, 'order' => 30], '/news/42/flu-season');
    Assert::assertIsArray($engine);

    expect($response->getStatusCode())->toBe(302)
        ->and(redirectDomainTarget($response))->toBe($engine['target_url'])
        ->and(redirectDomainTarget($response))->toBe('https://news.uams.edu/flu-season?id=42');
});

it('redirects a rule listed after a regex rule', function (): void {
    // A regex rule first in order, which this path does not match
    servedRedirectDomainEntry([
        'host' => 'uams.news',
        'source' => '^/archive/(\d{4})$',
        'use_regex' => true,
        'target_url' => 'https://news.uams.edu/year/$1',
        'order' => 10,
    ]);

    // A wildcard rule after it, which the edge hands to the origin
    servedRedirectDomainEntry([
        'host' => 'uams.news',
        'source' => '/stories/*',
        'target_url' => 'https://news.uams.edu/stories/*',
        'order' => 20,
    ]);

    $response = serveRedirectDomainRequest('https://uams.news/stories/spring/flu');

    expect($response->getStatusCode())->toBe(301)
        ->and(redirectDomainTarget($response))->toBe('https://news.uams.edu/stories/spring/flu');

    // The regex rule still answers its own path ahead of the wildcard
    expect(redirectDomainTarget(serveRedirectDomainRequest('https://uams.news/archive/2019')))
        ->toBe('https://news.uams.edu/year/2019');
});

it('serves a windowed rule only inside its window, as the engine does', function (): void {
    CarbonImmutable::setTestNow('2026-10-06 12:00:00');

    try {
        servedRedirectDomainEntry([
            'host' => 'uams.flu',
            'source' => '/clinic',
            'target_url' => 'https://uamshealth.com/flu-clinic',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-10-31',
        ]);
        servedRedirectDomainEntry([
            'host' => 'uams.flu',
            'source' => '/expired',
            'target_url' => 'https://uamshealth.com/old',
            'valid_until' => '2026-09-30',
        ]);

        $inside = serveRedirectDomainRequest('https://uams.flu/clinic');
        $outside = serveRedirectDomainRequest('https://uams.flu/expired');

        // Compare with the engine for the expired rule as a global-set row
        expect(engineAnswerForRow(['source' => '/expired', 'target_type' => 'url', 'target_url' => 'https://uamshealth.com/old', 'valid_until' => '2026-09-30'], '/expired'))->toBeNull();

        expect(redirectDomainTarget($inside))->toBe('https://uamshealth.com/flu-clinic')
            ->and($outside->getStatusCode())->toBe(404)
            ->and($outside->getContent())->not->toContain('SITE CONTENT');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('withholds a rule awaiting approval, as the engine does', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/pending', 'target_url' => 'https://uamshealth.com/', 'status' => 'pending']);

    expect(serveRedirectDomainRequest('https://uams.flu/pending')->getStatusCode())->toBe(404);
});

it('answers an unmatched path with a 404 that carries no site content', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic']);

    $response = serveRedirectDomainRequest('https://uams.flu/not-a-rule');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('Not Found')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

it('answers each host from its own entries only', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic']);
    servedRedirectDomainEntry(['host' => 'uams.news', 'source' => '/clinic', 'target_url' => 'https://news.uams.edu/clinic']);
    servedRedirectDomainEntry(['host' => 'uams.news', 'source' => '/press', 'target_url' => 'https://news.uams.edu/press']);

    expect(redirectDomainTarget(serveRedirectDomainRequest('https://uams.flu/clinic')))->toBe('https://uamshealth.com/flu-clinic')
        ->and(redirectDomainTarget(serveRedirectDomainRequest('https://uams.news/clinic')))->toBe('https://news.uams.edu/clinic')
        ->and(serveRedirectDomainRequest('https://uams.flu/press')->getStatusCode())->toBe(404);
});

it('never answers a collection host from the main site redirects global set', function (): void {
    // Seed a main-site rule for a path the collection host has no rule for
    $set = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Redirects');
    $set->save();

    $site = Site::default();
    Assert::assertInstanceOf(StatamicSite::class, $site);
    $vars = $set->makeLocalization($site->handle());
    Assert::assertInstanceOf(Variables::class, $vars);
    $vars->data(['redirects' => [['id' => 'main-1', 'source' => '/giving', 'target_type' => 'url', 'target_url' => '/give', 'response' => '301']]]);
    $vars->save();

    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic']);

    expect(serveRedirectDomainRequest('https://uams.flu/giving')->getStatusCode())->toBe(404);
});

it('answers a targetless rule with its own status and no site content', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/retired', 'response' => '410']);

    $response = serveRedirectDomainRequest('https://uams.flu/retired');

    expect($response->getStatusCode())->toBe(410)
        ->and($response->getContent())->toBe('Gone');
});

it('answers a 404 for an entry target that could not be resolved', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/moved', 'target_type' => 'entry', 'target_entry' => ['missing-entry-id']]);

    expect(serveRedirectDomainRequest('https://uams.flu/moved')->getStatusCode())->toBe(404);
});

it('forwards the request query string as the engine does', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic', 'target_query_string' => 'src=vanity']);

    expect(redirectDomainTarget(serveRedirectDomainRequest('https://uams.flu/clinic?b=2&a=1')))
        ->toBe('https://uamshealth.com/flu-clinic?src=vanity&b=2&a=1');
});

it('passes a request for a Statamic site host on, even when the collection names that host', function (): void {
    $appHost = parse_url(config()->string('app.url'), PHP_URL_HOST);
    Assert::assertIsString($appHost);

    servedRedirectDomainEntry(['host' => $appHost, 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic']);

    $response = serveRedirectDomainRequest(config()->string('app.url').'/clinic');

    expect($response->getContent())->toBe('SITE CONTENT');
});

it('passes a request for a host with no entries on', function (): void {
    servedRedirectDomainEntry(['host' => 'uams.flu', 'source' => '/clinic', 'target_url' => 'https://uamshealth.com/flu-clinic']);

    expect(serveRedirectDomainRequest('https://other.example.org/clinic')->getContent())->toBe('SITE CONTENT');
});
