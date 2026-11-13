<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use Statamic\Sites\Site;
use Statamic\Stache\Stores\CollectionsStore;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

/**
 * HTTP coverage for the approval workflow on the `redirect_domains` collection
 * (UAMS-Web/aether-redirects#83): a request for a vanity domain is an entry that
 * does not serve until an approver approves it, domains are chosen to suit the
 * destination, and collisions are scoped to the domain with a 12-week reuse buffer.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/MarketingRedirectDomainTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

/**
 * Resolve a Control Panel route to its URL string.
 *
 * @param  array<string, mixed>  $params
 */
function mrdRoute(string $route, array $params = []): string
{
    /** @var string $url */
    $url = cp_route($route, $params);

    return $url;
}

/**
 * Persist a user holding the given permissions through a role of their own.
 *
 * @param  list<string>  $permissions
 */
function mrdUser(array $permissions): FileStatamicUser
{
    $handle = 'mrd-'.Str::random(8);

    $role = Role::make($handle);
    $role->permissions(['access cp', ...$permissions]);
    $role->save();

    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->id((string) Str::uuid());
    $user->email($handle.'@example.test');
    $user->assignRole($handle);
    $user->save();

    return $user;
}

/**
 * A user who can create requests but not approve them.
 */
function mrdCreator(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::PERMISSION]);
}

/**
 * A user who can approve requests but not create them.
 */
function mrdApprover(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::APPROVE_PERMISSION]);
}

/**
 * Save a published redirect domain entry directly, as an approved rule unless the data says otherwise.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function mrdSeed(array $data): StatamicEntry
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
 * Submit a request as the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function mrdSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
{
    test()->actingAs($user);

    return test()->postJson(mrdRoute('marketing-redirects.store'), [
        'host' => 'uams.info',
        'source' => '/flu-'.Str::lower(Str::random(6)),
        'destination' => 'https://www.uams.edu/clinics/flu',
        'response' => '301',
        ...$overrides,
    ]);
}

/**
 * The status code the middleware answers a request with, or 200 when it passes the request on.
 */
function mrdServe(string $url): Response
{
    $middleware = app(ServeRedirectDomainRequests::class);
    Assert::assertInstanceOf(ServeRedirectDomainRequests::class, $middleware);

    return $middleware->handle(
        Request::create($url),
        fn (Request $request): Response => response('SITE CONTENT', 200),
    );
}

/**
 * A field of the redirect domain entry with the given id.
 */
function mrdField(string $id, string $field): mixed
{
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    return $entry->get($field);
}

/**
 * How many entries the collection holds.
 */
function mrdEntryCount(): int
{
    return count(app(RedirectDomainStore::class)->all());
}

/**
 * How many exact and prefix rules the edge manifest carries for a host.
 */
function mrdManifestRuleCount(string $host): int
{
    $rules = app(RedirectDomainRules::class)->read()['rules'][$host] ?? [];

    /** @var array{exact: list<array<string, mixed>>, prefix: list<array<string, mixed>>} $manifest */
    $manifest = app(EdgeRedirectManifestBuilder::class)->forHost($host, $rules);

    return count($manifest['exact']) + count($manifest['prefix']);
}

/**
 * An entry's id as a string.
 */
function mrdId(StatamicEntry $entry): string
{
    $id = $entry->id();
    Assert::assertIsString($id);

    return $id;
}

/**
 * Draw back-halves from a small alphabet, so a test can make every draw collide.
 */
function mrdGenerator(string $alphabet, int $length, int $attempts): void
{
    app()->bind(BackHalf::GENERATOR, static fn (): RandomCode => new RandomCode($alphabet, $length, $attempts));
}

/**
 * Seed a pending request whose source is a generated back-half, as the utility stores one.
 *
 * @param  array<string, mixed>  $data  Fields to add or override.
 */
function mrdGeneratedRequest(string $source, array $data = []): StatamicEntry
{
    return mrdSeed([
        'host' => 'uams.info',
        'source' => $source,
        'target_url' => 'https://www.uams.edu/clinics/flu',
        'status' => 'pending',
        'submitted_by' => 'someone-else',
        BackHalf::FIELD => true,
        ...$data,
    ]);
}

/**
 * Provide the shared `redirects` global the main-site path writes into.
 */
function mrdMainSiteGlobal(): void
{
    $global = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->title('Redirects');
    $global->save();

    /** @var Site $site */
    $site = Statamic\Facades\Site::default();
    /** @var Variables $vars */
    $vars = $global->makeLocalization($site->handle());
    $vars->data(['redirects' => []]);
    $vars->save();
}

beforeEach(function (): void {
    config()->set('statamic.editions.pro', true);

    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    // A role file of this test's own, so persisting roles never writes into the skeleton
    $rolesDir = storage_path('framework/testing/marketing-redirect-domain-roles/'.uniqid('', true));
    File::ensureDirectoryExists($rolesDir);
    app(RoleRepositoryContract::class)->path($rolesDir.'/roles.yaml');

    // The host owns the collection, its blueprint and its configuration; copy its fixtures
    $blueprint = 'collections/redirect_domains/redirect_domain.yaml';
    File::ensureDirectoryExists(dirname(BlueprintFacade::directory().'/'.$blueprint));
    File::copy(__DIR__.'/../../../../Fixtures/blueprints/'.$blueprint, BlueprintFacade::directory().'/'.$blueprint);

    File::ensureDirectoryExists(Fieldset::directory());
    File::copy(__DIR__.'/../../../../Fixtures/fieldsets/uamsweb_common.yaml', Fieldset::directory().'/uamsweb_common.yaml');

    $path = __DIR__.'/../../../../Fixtures/redirect_domains.yaml';
    $store = Stache::store('collections');
    Assert::assertInstanceOf(CollectionsStore::class, $store);

    $collection = $store->makeItemFromFile($path, File::get($path));
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $collection->save();
});

it('saves a request as a pending entry that does not serve until an approver approves it', function (): void {
    $creator = mrdCreator();

    $response = mrdSubmit($creator, ['source' => '/flu', 'requester' => 'Communications']);

    $response->assertCreated()
        ->assertJsonPath('message', 'Redirect submitted for review. It serves on uams.info once an approver approves it.');

    expect($response->json('redirect.status'))->toBe('pending');

    /** @var string $id */
    $id = $response->json('redirect.id');
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->collectionHandle())->toBe('redirect_domains')
        ->and($entry->get('host'))->toBe('uams.info')
        ->and($entry->get('source'))->toBe('/flu')
        ->and($entry->get('status'))->toBe('pending')
        ->and($entry->get('submitted_by'))->toBe($creator->id())
        ->and($entry->get('requester'))->toBe('Communications');

    // Pending: the domain is known but the rule is withheld, so the application answers 404
    expect(mrdServe('https://uams.info/flu')->getStatusCode())->toBe(404)
        ->and(mrdManifestRuleCount('uams.info'))->toBe(0);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertOk()
        ->assertJsonPath('message', 'Redirect approved. It now serves on its domain.');

    expect(mrdManifestRuleCount('uams.info'))->toBe(1);

    $served = mrdServe('https://uams.info/flu');

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu')
        ->and(mrdField($id, 'status'))->toBe('approved');
});

it('forbids the creator approving their own request on a domain', function (): void {
    $user = mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]);

    /** @var string $id */
    $id = mrdSubmit($user)->json('redirect.id');

    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertForbidden();

    expect(mrdField($id, 'status'))->toBe('pending');
});

it('retains a rejected request with its reason and never serves it', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/nope']);
    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'Not a marketing piece'])->assertOk();

    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->get('status'))->toBe('rejected')
        ->and($entry->get('rejection_reason'))->toBe('Not a marketing piece')
        ->and(mrdServe('https://uams.info/nope')->getStatusCode())->toBe(404);

    // A rejected request does not reserve its path
    mrdSubmit(mrdCreator(), ['source' => '/nope'])->assertCreated();
});

it('lists pending domain requests in the review queue with their domain', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/queued'])->assertCreated();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 1)
            ->where('redirects.0.host', 'uams.info')
            ->where('redirects.0.source', '/queued'));
});

it('refuses a request from a user without the create permission', function (): void {
    mrdSubmit(mrdApprover())->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
});

it('keeps serving an entry that has no status', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/old', 'target_url' => 'https://www.uams.edu/old']);

    expect(mrdServe('https://uams.info/old')->getStatusCode())->toBe(301)
        ->and(mrdManifestRuleCount('uams.info'))->toBe(1);
});

it('accepts the domain each destination calls for', function (string $host, string $destination): void {
    mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => $destination])->assertCreated();
})->with([
    'uams.edu page on uams.info' => ['uams.info', 'https://www.uams.edu/news'],
    'uams.edu subdomain on uams.info' => ['uams.info', 'https://research.uams.edu/'],
    'uamshealth.com page on uams.health' => ['uams.health', 'https://uamshealth.com/clinics'],
    'uamshealth.com page on uamsbaptist.health' => ['uamsbaptist.health', 'https://www.uamshealth.com/baptist'],
]);

it('refuses a mismatched domain from a creator and allows it from an approver', function (string $host, string $destination): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => $destination]);

    $response->assertStatus(422);

    expect($response->json('errors.host.0'))->toBe($host.' does not serve this destination. An approver can save the exception.');

    mrdSubmit(mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]), ['host' => $host, 'destination' => $destination])
        ->assertCreated();
})->with([
    'uamshealth.com page on uams.info' => ['uams.info', 'https://uamshealth.com/clinics'],
    'uams.edu page on uams.health' => ['uams.health', 'https://www.uams.edu/news'],
    'uams.edu page on uamsbaptist.health' => ['uamsbaptist.health', 'https://www.uams.edu/news'],
    'outside page on uams.info' => ['uams.info', 'https://example.org/'],
    'relative destination on uams.info' => ['uams.info', '/clinics'],
]);

it('refuses a domain that is not a redirect domain', function (string|array $host, string $message): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host]);

    $response->assertStatus(422);

    expect($response->json('errors.host.0'))->toBe($message)
        ->and(mrdEntryCount())->toBe(0);
})->with([
    'a valid but unknown domain' => ['example.org', 'example.org is not a redirect domain.'],
    'not a domain' => ['not a host', 'Enter a valid domain, such as uams.info.'],
    'a list' => [['uams.info'], 'Must be a string.'],
]);

it('treats a blank domain as a main-site request, whatever whitespace it carries', function (string $host): void {
    // Without the middleware that trims strings and nulls blanks, the value arrives as sent
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => '/clinics/flu'])->assertCreated();

    expect(mrdEntryCount())->toBe(0);
})->with(['an empty string' => [''], 'spaces' => ['   ']]);

it('accepts a domain the collection already serves', function (): void {
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/existing', 'target_url' => 'https://example.org/']);

    mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'destination' => 'https://example.org/else'])->assertCreated();
});

it('scopes collisions to the domain', function (): void {
    mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/shared'])->assertCreated();

    // The same path on another domain is a different rule
    mrdSubmit(mrdCreator(), ['host' => 'uams.health', 'source' => '/shared', 'destination' => 'https://uamshealth.com/'])->assertCreated();

    // The same path on the same domain collides, and the message names the conflicting rule
    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/Shared']);

    $response->assertStatus(422);

    expect($response->json('errors.source.0'))->toContain('uams.info/shared');
});

it('does not collide with a main-site redirect on the same path', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/flu', 'target_url' => 'https://www.uams.edu/flu']);

    // A request with no domain is a main-site request; it never meets the domain's entries
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/flu', 'destination' => '/clinics/flu'])->assertCreated();
});

it('reserves a path on a vanity domain for 12 weeks after its last day', function (string $host, string $validFrom, int $status): void {
    mrdSeed([
        'host' => $host,
        'source' => '/campaign',
        'target_url' => 'https://www.uams.edu/old',
        'status' => 'approved',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-01-31',
    ]);

    mrdSubmit(mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]), [
        'host' => $host,
        'source' => '/campaign',
        'destination' => 'https://www.uams.edu/new',
        'valid_from' => $validFrom,
    ])->assertStatus($status);
})->with([
    'the day after it ended' => ['uams.info', '2026-02-01', 422],
    '83 days after the last day' => ['uams.info', '2026-04-24', 422],
    'exactly 12 weeks after the last day' => ['uams.info', '2026-04-25', 201],
    'a go.uams.edu path has no buffer' => ['go.uams.edu', '2026-02-01', 201],
]);

it('keeps a path with no end date reserved indefinitely', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/forever', 'target_url' => 'https://www.uams.edu/old', 'valid_from' => '2026-01-01']);

    mrdSubmit(mrdCreator(), ['source' => '/forever', 'valid_from' => '2030-01-01'])->assertStatus(422);
});

it('hands the create screen the domains a request can be made for', function (): void {
    mrdSeed(['host' => 'zz.example.org', 'source' => '/x', 'target_url' => 'https://example.org/']);
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/x', 'target_url' => 'https://example.org/']);
    mrdSeed(['host' => 'uams.health', 'source' => '/y', 'target_url' => 'https://uamshealth.com/']);

    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('hostOptions', ['uams.info', 'uams.health', 'uamsbaptist.health', 'go.uams.edu', 'zz.example.org'])
            ->where('canApprove', false));
});

it('creates a published entry on the default site that the collection titles by host and path', function (): void {
    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/Flu Shot']);
    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->locale())->toBe('default')
        ->and($entry->published())->toBeTrue()
        ->and($entry->get('title'))->toBe('uams.info/Flu Shot')
        ->and($response->json('redirect.id'))->toBe($entry->id());
});

it('approves a main-site request with the message for every site', function (): void {
    mrdMainSiteGlobal();

    /** @var string $id */
    $id = mrdSubmit(mrdCreator(), ['host' => null, 'destination' => '/clinics/flu'])->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertOk()
        ->assertJsonPath('message', 'Redirect approved. It now serves on a 404 for every site.');
});

it('reviews a domain request and a main-site request side by side', function (): void {
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/main', 'destination' => '/clinics/flu'])->assertCreated();
    mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/domain'])->assertCreated();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 2)
            ->where('redirects.0.host', null)
            ->where('redirects.1.host', 'uams.info'));
});

it('generates a back-half for a vanity domain request with no source, and serves it once approved', function (string $host, string $destination, array $source): void {
    // The submit helper fills in a source, so post the payload without one directly
    test()->actingAs(mrdCreator());
    $response = test()->postJson(mrdRoute('marketing-redirects.store'), [
        'host' => $host,
        'destination' => $destination,
        'response' => '301',
        ...$source,
    ]);

    $response->assertCreated();

    /** @var string $backHalf */
    $backHalf = $response->json('redirect.source');

    // A slash, then 7 characters from the base-62 alphabet, kept in their drawn case
    expect($backHalf)->toMatch('#^/[A-Za-z0-9]{7}$#')
        ->and($response->json('redirect.'.BackHalf::FIELD))->toBeTrue()
        ->and($response->json('message'))->toBe('Redirect submitted for review. It serves on '.$host.$backHalf.' once an approver approves it.');

    /** @var string $id */
    $id = $response->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    $served = mrdServe('https://'.$host.$backHalf);

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe($destination);
})->with([
    'uams.info' => ['uams.info', 'https://www.uams.edu/clinics/flu'],
    'uams.health' => ['uams.health', 'https://uamshealth.com/flu'],
    'uamsbaptist.health' => ['uamsbaptist.health', 'https://uamshealth.com/partnership'],
])->with([
    'source left out' => [[]],
    'source empty' => [['source' => '']],
    'source blank' => [['source' => '   ']],
    'source null' => [['source' => null]],
]);

it('marks a typed source as not generated', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/typed']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/typed')
        ->and($response->json('redirect.'.BackHalf::FIELD))->toBeFalse()
        ->and($response->json('message'))->toBe('Redirect submitted for review. It serves on uams.info once an approver approves it.');
});

it('requires a source on a domain that is not a vanity domain, and on the main site', function (?string $host): void {
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/existing', 'target_url' => 'https://example.org/']);
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => $host, 'source' => '', 'destination' => 'https://example.org/else'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect(mrdEntryCount())->toBe(1);
})->with([
    'a domain the collection serves' => ['go.uams.edu'],
    'the main site' => [null],
]);

it('redraws a back-half that a rule on the domain has issued in another case', function (array $issued): void {
    /** @var array<string, mixed> $issued */
    // Over the alphabet `ab`, every draw of `a` is taken by the `/A` rule, so only `/b` is free
    mrdGenerator('ab', 1, 50);
    mrdSeed(['host' => 'uams.info', 'source' => '/A', 'target_url' => 'https://www.uams.edu/old', ...$issued]);

    $response = mrdSubmit(mrdCreator(), ['source' => '']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/b');
})->with([
    'a live redirect' => [['status' => 'approved']],
    'a pending request' => [['status' => 'pending']],
    'a redirect with no status' => [[]],
    'a redirect whose window has ended' => [['status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('does not count a rejected request, or a rule on another domain, as issuing a back-half', function (array $taken): void {
    /** @var array<string, mixed> $taken */
    // One draw over `ab`: it succeeds only when neither `/a` nor `/b` counts as issued
    mrdGenerator('ab', 1, 1);
    mrdSeed(['host' => 'uams.info', 'source' => '/A', 'target_url' => 'https://www.uams.edu/old', ...$taken]);
    mrdSeed(['host' => 'uams.info', 'source' => '/B', 'target_url' => 'https://www.uams.edu/old', ...$taken]);

    mrdSubmit(mrdCreator(), ['source' => ''])->assertCreated();
})->with([
    'rejected requests' => [['status' => 'rejected', 'rejection_reason' => 'No']],
    'rules on another domain' => [['host' => 'uams.health', 'status' => 'approved']],
]);

it('tells the requester when every allowed draw was taken, and saves nothing', function (): void {
    mrdGenerator('ab', 1, 3);
    mrdSeed(['host' => 'uams.info', 'source' => '/a', 'target_url' => 'https://www.uams.edu/old']);
    mrdSeed(['host' => 'uams.info', 'source' => '/B', 'target_url' => 'https://www.uams.edu/old']);

    $response = mrdSubmit(mrdCreator(), ['source' => '']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect($response->json('errors.source.0'))->toBe('A unique code could not be generated after 3 attempts. Try again, and report it if this keeps happening.')
        ->and(mrdEntryCount())->toBe(2);
});

it('approves only the first of two pending back-halves that differ only in case', function (): void {
    $first = mrdGeneratedRequest('/aB3xY9q');
    $second = mrdGeneratedRequest('/ab3xy9q');

    test()->actingAs(mrdApprover());

    // The other request is only pending, so it does not block this one
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $first->id()]))->assertOk();

    $response = test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $second->id()]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect($response->json('errors.source.0'))->toBe('The back-half /ab3xy9q is already live on uams.info in another case. Reject this request so it can be submitted again for a new back-half.')
        ->and(mrdField(mrdId($first), 'status'))->toBe('approved')
        ->and(mrdField(mrdId($second), 'status'))->toBe('pending');

    // The refused request can still be rejected, so it can be submitted again
    test()->postJson(mrdRoute('marketing-redirects.reject', ['redirect' => $second->id()]), ['reason' => 'Back-half taken'])->assertOk();
});

it('refuses to approve a generated back-half a live rule on its domain has issued', function (array $live): void {
    /** @var array<string, mixed> $live */
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', ...$live]);
    $request = mrdGeneratedRequest('/aB3xY9q');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertStatus(422);

    expect(mrdField(mrdId($request), 'status'))->toBe('pending');
})->with([
    'an approved rule' => [['status' => 'approved']],
    'a rule with no status' => [[]],
    'an approved rule whose window has ended' => [['status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('approves a generated back-half that only a rejected request, a pending one or another domain shares', function (array $other): void {
    /** @var array<string, mixed> $other */
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', ...$other]);
    $request = mrdGeneratedRequest('/aB3xY9q');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();

    expect(mrdField(mrdId($request), 'status'))->toBe('approved');
})->with([
    'a rejected request' => [['status' => 'rejected', 'rejection_reason' => 'No']],
    'a pending request' => [['status' => 'pending']],
    'an approved rule on another domain' => [['host' => 'uams.health', 'status' => 'approved']],
]);

it('leaves a typed source to its own approval rules', function (): void {
    // A typed path is not a generated back-half, so a live rule differing in case does not block it here
    mrdSeed(['host' => 'uams.info', 'source' => '/Typed', 'target_url' => 'https://www.uams.edu/old', 'status' => 'approved']);
    $request = mrdGeneratedRequest('/typed', [BackHalf::FIELD => false]);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();
});

it('tells the create screen which domains generate a back-half', function (): void {
    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('generatedHosts', ['uams.info', 'uams.health', 'uamsbaptist.health']));
});
