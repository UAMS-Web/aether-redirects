<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves the redirects global set, and some save entries.
uses(IsolatesStache::class);

/**
 * A request that is about to render a 404, answered with the `redirects` global
 * set's rule for its path (UAMS-Web/uams-statamic#398). Each test sends a real
 * request through the kernel, so the path, the query string, Statamic's own 404
 * and Laravel's exception handler all run as they do for a visitor. The error
 * views and the layout are fixtures standing in for the host's.
 *
 * @command  vendor/bin/pest tests/Integration/Http/NotFoundRedirectsTest.php
 */
beforeEach(function (): void {
    View::addLocation(__DIR__.'/../../Fixtures/views');
});

/**
 * Store redirect rows on the default site's `redirects` global set.
 *
 * @param  list<array<string, mixed>>  $rows  The rows.
 */
function notFoundRules(array $rows): void
{
    $global = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->title('Redirects');
    $global->save();

    $variables = $global->makeLocalization('default');
    Assert::assertInstanceOf(Variables::class, $variables);
    $variables->data(['redirects' => $rows]);
    $variables->save();
}

/**
 * A stored redirect row for a source, with overridable fields.
 *
 * @param  string  $source  The stored source.
 * @param  array<string, mixed>  $overrides  Fields to add or override.
 * @return array<string, mixed> The row.
 */
function notFoundRule(string $source, array $overrides = []): array
{
    return [
        'id' => 'rule-'.md5($source),
        'type' => 'redirect',
        'source' => $source,
        'target_type' => 'url',
        'target_url' => 'https://uamshealth.com/destination',
        'response' => '301',
        'order' => 30,
        'use_regex' => false,
        'enabled' => true,
        ...$overrides,
    ];
}

/**
 * Save an entry in a routed collection, for an entry target.
 *
 * @param  bool  $published  Whether the entry is published.
 * @param  string  $slug  The entry's slug.
 */
function notFoundDestination(bool $published = true, string $slug = 'giving'): StatamicEntry
{
    if (Collection::findByHandle('pages') === null) {
        $collection = Collection::make('pages');
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->routes('/{slug}');
        $collection->save();
    }

    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->collection('pages');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->published($published);
    $entry->data(['title' => ucfirst($slug)]);
    $entry->save();

    return $entry;
}

it('redirects a missing path with each redirect status a rule stores', function (string $response): void {
    notFoundRules([notFoundRule('/old', ['response' => $response])]);

    $this->get('/old')
        ->assertStatus((int) $response)
        ->assertRedirect('https://uamshealth.com/destination');
})->with(['301', '302', '303', '307', '308']);

it('moves permanently for a stored status that is neither a redirect nor targetless', function (mixed $response): void {
    notFoundRules([notFoundRule('/old', ['response' => $response])]);

    $this->get('/old')->assertStatus(301);
})->with([
    'a success status' => ['200'],
    'a value that is not a number' => ['gone'],
    'none stored' => [null],
    'a stored integer' => [301],
]);

it('redirects with a stored integer status', function (): void {
    notFoundRules([notFoundRule('/old', ['response' => 307])]);

    $this->get('/old')->assertStatus(307);
});

it('redirects to a relative target on the request host', function (): void {
    notFoundRules([notFoundRule('/old', ['target_url' => '/destination'])]);

    $this->get('/old')->assertRedirect('http://localhost/destination');
});

it('matches the request with a trailing slash and in any case', function (string $request): void {
    notFoundRules([notFoundRule('/old-page')]);

    $this->get($request)->assertRedirect('https://uamshealth.com/destination');
})->with(['/old-page/', '/OLD-Page']);

it('forwards the request query string onto the target, in the order it arrived', function (): void {
    notFoundRules([notFoundRule('/old')]);

    $this->get('/old?b=2&a=1')->assertRedirect('https://uamshealth.com/destination?b=2&a=1');
});

it('joins the forwarded query to a target that already carries one', function (): void {
    notFoundRules([notFoundRule('/old', ['target_url' => 'https://uamshealth.com/destination?x=1'])]);

    $this->get('/old?a=1')->assertRedirect('https://uamshealth.com/destination?x=1&a=1');
});

it('appends a configured target query string', function (): void {
    notFoundRules([notFoundRule('/old', ['target_query_string' => 'utm=1'])]);

    $this->get('/old')->assertRedirect('https://uamshealth.com/destination?utm=1');
});

it('serves a wildcard rule with the remainder appended', function (): void {
    notFoundRules([notFoundRule('/old/*', ['target_url' => 'https://uamshealth.com/new/*'])]);

    $this->get('/old/a/b')->assertRedirect('https://uamshealth.com/new/a/b');
});

it('redirects to an entry target', function (): void {
    $destination = notFoundDestination();
    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => [$destination->id()]])]);

    $this->get('/old?a=1')->assertStatus(301)->assertRedirect('http://localhost/giving?a=1');
});

it('accepts an entry target stored as a bare id', function (): void {
    $destination = notFoundDestination();
    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => $destination->id()])]);

    $this->get('/old')->assertRedirect('http://localhost/giving');
});

it('renders the 404 page for an entry target that is unpublished, missing or empty', function (Closure $target): void {
    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => $target()])]);

    $this->get('/old')->assertStatus(404)->assertSee('NOT FOUND PAGE');
})->with([
    'unpublished' => [fn (): array => [notFoundDestination(published: false)->id()]],
    'missing' => [fn (): array => ['no-such-entry']],
    'empty' => [fn (): array => []],
    'not an id' => [fn (): array => [42]],
]);

/**
 * Point an entry's own `redirect` field at another entry.
 *
 * @param  StatamicEntry  $from  The entry that redirects.
 * @param  StatamicEntry  $to  The entry it redirects to.
 */
function notFoundRedirectEntry(StatamicEntry $from, StatamicEntry $to): void
{
    $id = $to->id();
    Assert::assertIsString($id);

    $from->set('redirect', 'entry::'.$id);
    $from->save();
}

/**
 * Statamic 6.40.0 (statamic/cms#15746) resolves a looping entry `redirect` to null, where
 * 6.39.0 recursed without end (statamic/cms#15745). These cases need 6.40.0 or later.
 */
it('redirects an entry target that itself redirects to another entry, to that entry', function (): void {
    $archive = notFoundDestination(slug: 'archive');
    $giving = notFoundDestination();
    notFoundRedirectEntry($giving, $archive);
    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => [$giving->id()]])]);

    $this->get('/old')->assertStatus(301)->assertRedirect('http://localhost/archive');
});

it('stops at a looping entry redirect: the rule goes to the entry, which renders the 404 page', function (Closure $loop): void {
    $giving = $loop();
    Assert::assertInstanceOf(StatamicEntry::class, $giving);
    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => [$giving->id()]])]);

    // A loop resolves to no redirect, so the rule's target is the entry's own URL
    $this->get('/old')->assertStatus(301)->assertRedirect('http://localhost/giving');

    // The entry's own request then ends in Statamic's 404 and no rule matches it
    $this->get('/giving')->assertStatus(404)->assertSee('NOT FOUND PAGE');
})->with([
    'an entry redirecting to itself' => [function (): StatamicEntry {
        $giving = notFoundDestination();
        notFoundRedirectEntry($giving, $giving);

        return $giving;
    }],
    'two entries redirecting to each other' => [function (): StatamicEntry {
        $giving = notFoundDestination();
        $archive = notFoundDestination(slug: 'archive');
        notFoundRedirectEntry($giving, $archive);
        notFoundRedirectEntry($archive, $giving);

        return $giving;
    }],
]);

it('answers the 404 of an entry whose redirect loops with the rule for its path', function (): void {
    $giving = notFoundDestination();
    notFoundRedirectEntry($giving, $giving);
    notFoundRules([notFoundRule('/giving')]);

    $this->get('/giving')->assertStatus(301)->assertRedirect('https://uamshealth.com/destination');
});

it('renders the 404 page for a url rule with no target, or a target of no known type', function (array $overrides): void {
    /** @var array<string, mixed> $overrides */
    notFoundRules([notFoundRule('/old', $overrides)]);

    $this->get('/old')->assertStatus(404)->assertSee('NOT FOUND PAGE');
})->with([
    'none stored' => [['target_url' => null]],
    'an empty one' => [['target_url' => '']],
    'an unknown type' => [['target_type' => 'asset']],
]);

it('leaves a 404 rule the 404 page, without redirecting to its target', function (): void {
    notFoundRules([notFoundRule('/old', ['response' => '404', 'message' => 'Retired.'])]);

    $this->get('/old')
        ->assertStatus(404)
        ->assertHeaderMissing('Location')
        ->assertSee('NOT FOUND PAGE');
});

it('renders the site error view in the site layout for a targetless rule, with its message', function (string $response): void {
    notFoundRules([notFoundRule('/old', ['response' => $response, 'message' => 'Removed in 2025.'])]);

    $this->get('/old')
        ->assertStatus((int) $response)
        ->assertHeaderMissing('Location')
        ->assertSee('SITE LAYOUT')
        ->assertSee("SITE ERROR {$response}")
        ->assertSee('MESSAGE[Removed in 2025.]', escape: false);
})->with(['403', '410', '451']);

it('renders a targetless rule stored as an integer', function (): void {
    notFoundRules([notFoundRule('/old', ['response' => 410])]);

    $this->get('/old')->assertStatus(410)->assertSee('SITE ERROR 410');
});

it('carries no message when the rule has none, or one that is not text', function (mixed $message): void {
    notFoundRules([notFoundRule('/old', ['response' => '410', 'message' => $message])]);

    $this->get('/old')->assertStatus(410)->assertSee('NO MESSAGE');
})->with([
    'none stored' => [null],
    'an empty one' => [''],
    'a list' => [['Removed.']],
]);

it('escapes editor markup in the message and keeps its line breaks', function (): void {
    notFoundRules([notFoundRule('/old', ['response' => '410', 'message' => "<img src=x onerror=alert(1)>\nSecond line"])]);

    $this->get('/old')
        ->assertStatus(410)
        ->assertDontSee('<img src=x', escape: false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;<br />', escape: false)
        ->assertSee('Second line');
});

it('renders the 404 page when no rule matches, or there is no redirects global set', function (bool $withGlobal): void {
    if ($withGlobal) {
        notFoundRules([notFoundRule('/elsewhere')]);
    }

    $this->get('/old')->assertStatus(404)->assertSee('NOT FOUND PAGE');
})->with([
    'a rule for another path' => [true],
    'no global set' => [false],
]);

/**
 * Hand a plain Symfony 404, which is what `abort(404)` raises, to the real
 * exception handler for a request, as Laravel's kernel does. A route added at
 * run time cannot raise one here: Statamic's catch-all route was registered
 * first and answers the path before it.
 *
 * @param  string  $uri  The request URI.
 */
function plainNotFoundResponse(string $uri): Response
{
    $request = Request::create($uri);
    app()->instance('request', $request);

    // Typed as the contract, since a dev tool may bind its own adapter in its place
    /** @var ExceptionHandler $handler */
    $handler = app(ExceptionHandler::class);

    return $handler->render($request, new SymfonyNotFoundHttpException);
}

it('answers a plain abort(404) the same way', function (): void {
    notFoundRules([notFoundRule('/aborts')]);

    $response = plainNotFoundResponse('/aborts?a=1');

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('https://uamshealth.com/destination?a=1');
});

it('leaves a plain abort(404) with no rule to the framework', function (): void {
    notFoundRules([notFoundRule('/elsewhere')]);

    $response = plainNotFoundResponse('/aborts');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->has('Location'))->toBeFalse();
});

it('leaves a Control Panel 404 to Statamic, even with a rule for its path', function (): void {
    notFoundRules([notFoundRule('/cp/no-such-page')]);

    // An unauthenticated Control Panel request is sent to the login page instead
    expect($this->get('/cp/no-such-page')->headers->get('Location'))->not->toBe('https://uamshealth.com/destination');
});

it('leaves an API 404 to Statamic, even with a rule for its path', function (): void {
    config()->set(['statamic.api.enabled' => true, 'statamic.editions.pro' => true]);
    notFoundRules([notFoundRule('/api/no-such-thing')]);

    $this->get('/api/no-such-thing')->assertStatus(404)->assertHeaderMissing('Location');
});

it("redirects an entry target to its localization on the request's site, or else to the entry itself", function (): void {
    config()->set(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
    Site::setSites([
        'default' => ['name' => 'Enterprise', 'url' => '/', 'locale' => 'en_US'],
        'excelsior' => ['name' => 'Excelsior', 'url' => '/excelsior/', 'locale' => 'en_US'],
    ]);

    $collection = Collection::make('pages');
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->sites(['default', 'excelsior']);
    $collection->routes('/{slug}');
    $collection->save();

    // One destination is localized on the request's site; the other is not
    $localized = notFoundDestination();
    $localization = $localized->makeLocalization('excelsior');
    Assert::assertInstanceOf(StatamicEntry::class, $localization);
    $localization->save();

    $unlocalized = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $unlocalized);
    $unlocalized->collection('pages');
    $unlocalized->locale('default');
    $unlocalized->slug('archive');
    $unlocalized->data(['title' => 'Archive']);
    $unlocalized->save();

    notFoundRules([
        notFoundRule('/excelsior/localized', ['target_type' => 'entry', 'target_entry' => [$localized->id()]]),
        notFoundRule('/excelsior/unlocalized', ['target_type' => 'entry', 'target_entry' => [$unlocalized->id()]]),
    ]);

    $this->get('/excelsior/localized')->assertRedirect('http://localhost/excelsior/giving');
    $this->get('/excelsior/unlocalized')->assertRedirect('http://localhost/archive');
});

it('renders the 404 page for an entry target in a collection with no route', function (): void {
    $collection = Collection::make('unrouted');
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();

    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->collection('unrouted');
    $entry->locale('default');
    $entry->slug('nowhere');
    $entry->data(['title' => 'Nowhere']);
    $entry->save();

    notFoundRules([notFoundRule('/old', ['target_type' => 'entry', 'target_entry' => [$entry->id()]])]);

    $this->get('/old')->assertStatus(404)->assertSee('NOT FOUND PAGE');
});

it('adds nothing to the target for an empty stored query string', function (): void {
    notFoundRules([notFoundRule('/old', ['target_query_string' => ''])]);

    $this->get('/old')->assertRedirect('https://uamshealth.com/destination');
});
