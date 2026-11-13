<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\CP\Navigation\NavItem;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Globals\Variables;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\ServiceProvider;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

// Stache-isolated: every Stache store, users included, is re-rooted into this
// process's throwaway directory, and roles go to a per-test path below.
uses(IsolatesStache::class);

/**
 * What the provider registers for the marketing-redirect workflow
 * (UAMS-Web/uams-statamic#398): its two permissions, and the Tools nav items
 * each shown only to a user holding the matching permission, the review queue
 * labelled with the number of requests awaiting approval.
 *
 * @command  vendor/bin/pest tests/Integration/MarketingRedirectRegistrationTest.php
 */
beforeEach(function (): void {
    // A role-holding user alongside others needs Statamic Pro, the edition the host runs
    config()->set('statamic.editions.pro', true);

    // Repoint the file role repository at a throwaway path so persisting a test
    // role never writes into the Testbench skeleton's roles file
    $rolesDir = storage_path('framework/testing/marketing-redirect-roles/'.uniqid('', true));
    File::ensureDirectoryExists($rolesDir);
    app(RoleRepositoryContract::class)->path($rolesDir.'/roles.yaml');

    // Provide the shared redirects global the pending count reads
    /** @var Statamic\Globals\GlobalSet $set */
    $set = GlobalSet::make('redirects');
    $set->title('Redirects');
    $set->save();

    /** @var Statamic\Sites\Site $site */
    $site = Site::default();
    /** @var Variables $vars */
    $vars = $set->makeLocalization($site->handle());
    $vars->data(['redirects' => []]);
    $vars->save();

    // Provide the redirect domains collection the pending count also reads
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
});

/**
 * Persist a user holding `access cp` and the given permissions, through a role
 * of their own, and sign them in.
 *
 * @param  list<string>  $permissions  The permissions the user's role grants.
 * @return FileStatamicUser The signed-in user.
 */
function marketingRedirectRegistrationSignIn(array $permissions): FileStatamicUser
{
    // Grant the permissions through a role named for this user
    $handle = 'marketing-redirect-registration-'.Str::lower(Str::random(8));
    $role = Role::make($handle);
    $role->permissions(array_merge(['access cp'], $permissions));
    $role->save();

    // Persist the user with that role
    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);
    $user->id((string) Str::uuid());
    $user->email($handle.'@example.test');
    $user->assignRole($handle);
    $user->save();

    // Sign the user in for the nav build
    test()->actingAs($user);

    return $user;
}

/**
 * The Tools nav items the add-on adds for the signed-in user, keyed by label.
 *
 * Statamic's `AddonTestCase` replaces the `Nav` facade with a mock once the
 * application has booted, so the extension registered at boot cannot be read
 * back. The provider's boot is run again against that mock instead, which must
 * receive exactly one extension, and the extension is applied to a real nav.
 *
 * @return array<string, NavItem> The items the extension adds, keyed by label.
 */
function marketingRedirectRegistrationNavItems(): array
{
    $extension = null;

    // Capture the one extension the provider's boot registers
    Nav::shouldReceive('extend')->once()->andReturnUsing(function (Closure $callback) use (&$extension): void {
        $extension = $callback;
    });

    $provider = app()->getProvider(ServiceProvider::class);
    Assert::assertInstanceOf(ServiceProvider::class, $provider);
    $provider->bootAddon();
    Assert::assertInstanceOf(Closure::class, $extension);

    // Apply it to a real nav for the signed-in user
    $nav = new Navigation;
    $extension($nav);

    $items = [];

    // Key every item the extension added by its label
    foreach ($nav->items() as $item) {
        Assert::assertInstanceOf(NavItem::class, $item);

        /** @var string $label */
        $label = $item->display();
        $items[$label] = $item;
    }

    return $items;
}

it('registers the permission to manage redirect domains with a label', function (): void {
    $permission = Permission::get(RedirectDomainList::PERMISSION);

    Assert::assertNotNull($permission);
    expect($permission->label())->toBe('Manage redirect domains');
});

it('registers the request and approval permissions', function (): void {
    expect(Permission::get(MarketingRedirectController::PERMISSION))->not->toBeNull()
        ->and(Permission::get(MarketingRedirectController::APPROVE_PERMISSION))->not->toBeNull();
});

it('shows a requester the creation utility, linked and iconed, and not the review queue', function (): void {
    marketingRedirectRegistrationSignIn([MarketingRedirectController::PERMISSION]);

    $items = marketingRedirectRegistrationNavItems();

    expect($items)->toHaveKey('Marketing Redirects')
        ->and($items)->not->toHaveKey('Review Redirects')
        ->and($items['Marketing Redirects']->section())->toBe('Tools')
        ->and($items['Marketing Redirects']->url())->toBe(cp_route('marketing-redirects.create'))
        ->and($items['Marketing Redirects']->icon())->toContain('hyperlink');
});

it('shows an approver the review queue, linked and iconed, and not the creation utility', function (): void {
    marketingRedirectRegistrationSignIn([MarketingRedirectController::APPROVE_PERMISSION]);

    $items = marketingRedirectRegistrationNavItems();

    expect($items)->toHaveKey('Review Redirects')
        ->and($items)->not->toHaveKey('Marketing Redirects')
        ->and($items['Review Redirects']->section())->toBe('Tools')
        ->and($items['Review Redirects']->url())->toBe(cp_route('marketing-redirects.review'))
        ->and($items['Review Redirects']->icon())->toContain('flag');
});

it('shows neither item to a user holding neither permission', function (): void {
    marketingRedirectRegistrationSignIn([]);

    $items = marketingRedirectRegistrationNavItems();

    expect($items)->not->toHaveKey('Marketing Redirects')
        ->and($items)->not->toHaveKey('Review Redirects');
});

it('labels the review queue with the pending count only when requests await approval', function (int $pending, string $label): void {
    // Store the given number of pending requests
    for ($i = 0; $i < $pending; $i++) {
        app(MarketingRedirectStore::class)->create(
            MarketingRedirect::fromInput(['source' => '/pending-'.$i, 'destination' => '/target'])
        );
    }

    marketingRedirectRegistrationSignIn([MarketingRedirectController::APPROVE_PERMISSION]);

    expect(array_keys(marketingRedirectRegistrationNavItems()))->toContain($label);
})->with([
    'none pending' => [0, 'Review Redirects'],
    'one pending' => [1, 'Review Redirects (1)'],
    'two pending' => [2, 'Review Redirects (2)'],
]);

it('adds the pending domain requests to the pending count', function (int $global, int $domain, string $label): void {
    // Store the given number of pending requests on each path
    for ($i = 0; $i < $global; $i++) {
        app(MarketingRedirectStore::class)->create(
            MarketingRedirect::fromInput(['source' => '/pending-'.$i, 'destination' => '/target'])
        );
    }

    RedirectDomainRecords::idFor('uams.info');

    for ($i = 0; $i < $domain; $i++) {
        app(RedirectDomainStore::class)->create(
            MarketingRedirect::fromInput(['source' => '/domain-'.$i, 'destination' => '/target', 'host' => 'uams.info'])
        );
    }

    marketingRedirectRegistrationSignIn([MarketingRedirectController::APPROVE_PERMISSION]);

    expect(array_keys(marketingRedirectRegistrationNavItems()))->toContain($label);
})->with([
    'domain only' => [0, 2, 'Review Redirects (2)'],
    'both' => [1, 2, 'Review Redirects (3)'],
]);
