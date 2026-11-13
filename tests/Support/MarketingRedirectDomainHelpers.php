<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Pest\Support\HigherOrderTapProxy;
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
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;
use UAMSWeb\Aether\Redirects\Tests\TestCase;

/*
 * Helpers and setup shared by the HTTP tests of the approval workflow on the
 * `redirect_domains` collection (UAMS-Web/aether-redirects#83) and of the
 * generated back-half (UAMS-Web/aether-redirects#15). They are two classes
 * because paratest gives each class to one worker, and one class holding both
 * bounded the whole run. Each test file loads this file with `require_once`.
 */

/**
 * The running test, typed as the suite's test case: analysis cannot infer it
 * outside a test file, and at run time `test()` returns a proxy around it.
 */
function mrdTest(): TestCase
{
    $proxy = test();
    Assert::assertInstanceOf(HigherOrderTapProxy::class, $proxy);

    $test = $proxy->target;
    Assert::assertInstanceOf(TestCase::class, $test);

    return $test;
}

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
 * Submit a request as the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function mrdSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
{
    $test = mrdTest();
    $test->actingAs($user);

    return $test->postJson(mrdRoute('marketing-redirects.store'), [
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

/**
 * Prepare a test: the Pro edition, a role file of the test's own, the host's
 * redirect domain collection and blueprint, and the domain list. Each test file
 * also turns off CSRF and Vite, which only a test case can do.
 */
function mrdSetUp(): void
{
    config()->set('statamic.editions.pro', true);

    // A role file of this test's own, so persisting roles never writes into the skeleton
    $rolesDir = storage_path('framework/testing/marketing-redirect-domain-roles/'.uniqid('', true));
    File::ensureDirectoryExists($rolesDir);
    app(RoleRepositoryContract::class)->path($rolesDir.'/roles.yaml');

    // The host owns the collection, its blueprint and its configuration; copy its fixtures
    $blueprint = 'collections/redirect_domains/redirect_domain.yaml';
    File::ensureDirectoryExists(dirname(BlueprintFacade::directory().'/'.$blueprint));
    File::copy(__DIR__.'/../Fixtures/blueprints/'.$blueprint, BlueprintFacade::directory().'/'.$blueprint);

    File::ensureDirectoryExists(Fieldset::directory());
    File::copy(__DIR__.'/../Fixtures/fieldsets/uamsweb_common.yaml', Fieldset::directory().'/uamsweb_common.yaml');

    $path = __DIR__.'/../Fixtures/redirect_domains.yaml';
    $store = Stache::store('collections');
    Assert::assertInstanceOf(CollectionsStore::class, $store);

    $collection = $store->makeItemFromFile($path, File::get($path));
    Assert::assertInstanceOf(StatamicCollection::class, $collection);

    $collection->save();

    // The domain list: the vanity domains and the short-link domain
    foreach (['uams.info', 'uams.health', 'uamsbaptist.health', 'go.uams.edu'] as $host) {
        RedirectDomainRecords::idFor($host);
    }
}
