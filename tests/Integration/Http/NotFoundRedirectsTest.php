<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
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
 */
function notFoundDestination(bool $published = true): StatamicEntry
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
    $entry->slug('giving');
    $entry->published($published);
    $entry->data(['title' => 'Giving']);
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

it('answers a plain abort(404) from a route the same way', function (): void {
    Route::get('/aborts', fn () => abort(404));
    notFoundRules([notFoundRule('/aborts')]);

    $this->get('/aborts')->assertStatus(301)->assertRedirect('https://uamshealth.com/destination');
});

it('leaves a plain abort(404) with no rule to the framework', function (): void {
    Route::get('/aborts', fn () => abort(404));
    notFoundRules([notFoundRule('/elsewhere')]);

    $this->get('/aborts')->assertStatus(404)->assertHeaderMissing('Location');
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
