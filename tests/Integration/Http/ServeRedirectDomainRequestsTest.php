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
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionsStore;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves redirect domain entries.
uses(IsolatesStache::class);

/**
 * How `ServeRedirectDomainRequests` answers: which hosts it leaves to the Statamic
 * sites, which status each stored response becomes, and when a rule with nowhere
 * to send the visitor is a 404. The serving test in `Redirects/` covers the engine
 * parity; these pin the middleware's own decisions.
 *
 * @command  vendor/bin/pest tests/Integration/Http/ServeRedirectDomainRequestsTest.php
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
 * Save a published source entry for `/clinic` on a host.
 *
 * @param  string  $host  The entry's host.
 * @param  array<string, mixed>  $data  Fields to add or override.
 */
function middlewareEntry(string $host, array $data = []): StatamicEntry
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->collection(RedirectDomainRules::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data([
        'host' => $host,
        'source' => '/clinic',
        'target_type' => 'url',
        'target_url' => 'https://uamshealth.com/clinic',
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
function middlewareResponse(string $url): Response
{
    $middleware = app(ServeRedirectDomainRequests::class);
    Assert::assertInstanceOf(ServeRedirectDomainRequests::class, $middleware);

    return $middleware->handle(Request::create($url), fn (Request $request): Response => response('SITE CONTENT', 200));
}

it("passes the application URL's host on, even when the collection names it", function (): void {
    config()->set('app.url', 'https://enterprise.org');
    middlewareEntry('enterprise.org');
    middlewareEntry('uams.flu');

    expect(middlewareResponse('https://enterprise.org/clinic')->getContent())->toBe('SITE CONTENT')
        ->and(middlewareResponse('https://uams.flu/clinic')->getStatusCode())->toBe(301);
});

it("passes a site's own host on, even when the collection names it", function (): void {
    Site::setSites([
        'default' => ['name' => 'Enterprise', 'url' => '/', 'locale' => 'en_US'],
        'excelsior' => ['name' => 'Excelsior', 'url' => 'https://excelsior.org/', 'locale' => 'en_US'],
    ]);
    middlewareEntry('excelsior.org');
    middlewareEntry('uams.flu');

    expect(middlewareResponse('https://excelsior.org/clinic')->getContent())->toBe('SITE CONTENT')
        ->and(middlewareResponse('https://uams.flu/clinic')->getStatusCode())->toBe(301);
});

it('redirects with each redirect status a rule stores', function (string $response): void {
    middlewareEntry('uams.flu', ['response' => $response]);

    $answer = middlewareResponse('https://uams.flu/clinic');

    expect($answer)->toBeInstanceOf(RedirectResponse::class)
        ->and($answer->getStatusCode())->toBe((int) $response);
})->with(['301', '302', '303', '307', '308']);

it('answers a targetless status with that status and no redirect, even with a target stored', function (string $response): void {
    middlewareEntry('uams.flu', ['response' => $response]);

    $answer = middlewareResponse('https://uams.flu/clinic');

    expect($answer->getStatusCode())->toBe((int) $response)
        ->and($answer->headers->has('Location'))->toBeFalse()
        ->and($answer->getContent())->toBe(Response::$statusTexts[(int) $response]);
})->with(['403', '404', '410', '451']);

it('redirects with a stored integer status', function (): void {
    middlewareEntry('uams.flu', ['response' => 302]);

    expect(middlewareResponse('https://uams.flu/clinic')->getStatusCode())->toBe(302);
});

it('moves permanently for a stored status that is neither a redirect nor targetless', function (mixed $response): void {
    middlewareEntry('uams.flu', ['response' => $response]);

    $answer = middlewareResponse('https://uams.flu/clinic');

    expect($answer)->toBeInstanceOf(RedirectResponse::class)
        ->and($answer->getStatusCode())->toBe(301);
})->with([
    'a success status' => ['200'],
    'a value that is not a number' => ['gone'],
]);

it('answers a 404 for a URL rule with no target', function (mixed $target): void {
    middlewareEntry('uams.flu', ['target_url' => $target]);

    $answer = middlewareResponse('https://uams.flu/clinic');

    expect($answer->getStatusCode())->toBe(404)
        ->and($answer->headers->has('Location'))->toBeFalse();
})->with([
    'none stored' => [null],
    'an empty one' => [''],
]);

it('adds nothing to the target for an empty stored query string', function (): void {
    middlewareEntry('uams.flu', ['target_query_string' => '']);

    $answer = middlewareResponse('https://uams.flu/clinic');
    Assert::assertInstanceOf(RedirectResponse::class, $answer);

    expect($answer->getTargetUrl())->toBe('https://uamshealth.com/clinic');
});
