<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Listeners\GuardRedirectHosts;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

uses(IsolatesStache::class);

/**
 * Integration coverage for the guard on the domain list (UAMS-Web/aether-redirects#24):
 * a domain record is normalized and needs its own permission, and a redirect rule can
 * only name a published record.
 *
 * @command  vendor/bin/pest tests/Integration/Listeners/GuardRedirectHostsTest.php
 */
beforeEach(function (): void {
    config()->set('statamic.editions.pro', true);

    // A role file of this test's own, so persisting roles never writes into the skeleton
    $rolesDir = storage_path('framework/testing/guard-redirect-hosts-roles/'.uniqid('', true));
    File::ensureDirectoryExists($rolesDir);
    app(RoleRepositoryContract::class)->path($rolesDir.'/roles.yaml');

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

    RedirectDomainRecords::register();
});

/**
 * Persist a user holding `access cp` and the given permissions, and sign them in.
 *
 * @param  list<string>  $permissions  The permissions the user's role grants.
 */
function guardSignIn(array $permissions, bool $super = false): FileStatamicUser
{
    $handle = 'guard-redirect-hosts-'.Str::lower(Str::random(8));
    $role = Role::make($handle);
    $role->permissions(array_merge(['access cp'], $permissions));
    $role->save();

    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);
    $user->id((string) Str::uuid());
    $user->email($handle.'@example.test');
    $user->assignRole($handle);
    if ($super) {
        $user->makeSuper();
    }

    $user->save();

    test()->actingAs($user);

    return $user;
}

/**
 * A domain record not yet saved.
 */
function guardRecord(mixed $host): StatamicEntry
{
    $record = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $record);

    $record->collection(RedirectDomainList::COLLECTION);
    $record->locale('default');
    $record->published(true);
    $record->data(['host' => $host]);

    return $record;
}

/**
 * A redirect domain entry not yet saved.
 */
function guardRule(mixed $host): StatamicEntry
{
    $rule = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $rule);

    $rule->collection(RedirectDomainRules::COLLECTION);
    $rule->locale('default');
    $rule->published(true);
    $rule->data(['host' => $host, 'source' => '/a', 'target_type' => 'url', 'target_url' => 'https://uams.edu/']);

    return $rule;
}

it('saves a domain record with its host lowercased and without a trailing dot', function (): void {
    $record = guardRecord(' UAMS.Info. ');
    $record->save();

    expect(RedirectDomainRecords::stored($record, 'host'))->toBe('uams.info');
});

it('refuses a domain record whose host is not a valid domain', function (mixed $host): void {
    expect(fn () => guardRecord($host)->save())->toThrow(ValidationException::class, 'Enter a valid domain');
    expect(RedirectDomainList::records())->toBe([]);
})->with([
    'text with spaces' => ['not a domain'],
    'a URL' => ['https://uams.info/'],
    'a single label' => ['localhost'],
    'nothing' => [null],
]);

it('refuses a second record for a host the list already holds', function (): void {
    guardRecord('uams.info')->save();

    expect(fn () => guardRecord('UAMS.INFO')->save())->toThrow(ValidationException::class, 'uams.info is already in the domain list');
    expect(RedirectDomainList::hosts())->toBe(['uams.info']);
});

it('lets a record be saved again under its own host', function (): void {
    $record = guardRecord('uams.info');
    $record->save();
    $record->set('title', 'Renamed');
    $record->save();

    expect(RedirectDomainList::hosts())->toBe(['uams.info']);
});

it('lets a user with the permission create a domain record, and a super user too', function (bool $super, string ...$permissions): void {
    guardSignIn(array_values($permissions), $super);

    guardRecord('uams.info')->save();

    expect(RedirectDomainList::hosts())->toBe(['uams.info']);
})->with([
    'the permission' => [false, RedirectDomainList::PERMISSION],
    'a super user' => [true],
]);

it('refuses a user who can publish a redirect entry but lacks the permission to create a domain record', function (): void {
    guardSignIn(['create redirect_domains entries', 'edit redirect_domains entries', 'publish redirect_domains entries']);

    expect(fn () => guardRecord('uams.info')->save())->toThrow(AuthorizationException::class);
    expect(RedirectDomainList::records())->toBe([]);
});

it('refuses that user a change to an existing record too, since the host is the trusted address', function (): void {
    $record = guardRecord('uams.info');
    $record->save();

    guardSignIn(['edit redirect_hosts entries']);
    $record->set('host', 'uams.health');

    expect(fn () => $record->save())->toThrow(AuthorizationException::class);
});

it('saves a rule that names a published record, as a bare id or a list of one', function (bool $asList): void {
    $record = guardRecord('uams.info');
    $record->save();

    $id = RedirectDomainRecords::id($record);

    $rule = guardRule($asList ? [$id] : $id);
    $rule->save();

    expect(Entry::find($rule->id()))->not->toBeNull();
})->with([
    'a bare id' => [false],
    'a list of one id' => [true],
]);

it('refuses a rule whose host is a string, even a valid domain', function (): void {
    expect(fn () => guardRule('agec.org')->save())->toThrow(ValidationException::class, 'Choose a domain from the domain list');

    // The refusal added no trusted address
    expect(RedirectDomainList::hosts())->toBe([])
        ->and(RedirectDomainRules::publishedHosts())->toBe([]);
});

it('refuses a rule whose host is not a record, an unpublished record or nothing', function (): void {
    $draft = guardRecord('draft.org');
    $draft->published(false);
    $draft->save();

    foreach (['00000000-0000-0000-0000-000000000000', RedirectDomainRecords::id($draft), null, []] as $host) {
        expect(fn () => guardRule($host)->save())->toThrow(ValidationException::class);
    }
});

it('does not guard an entry of another collection', function (): void {
    $pages = Collection::make('pages');
    $pages->routes('/{slug}');
    $pages->save();

    $other = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $other);
    $other->collection('pages');
    $other->locale('default');
    $other->published(true);
    $other->data(['title' => 'About', 'host' => 'whatever']);

    expect(fn () => $other->saveQuietly())->not->toThrow(Throwable::class);
});

it('trusts a new domain as soon as its record is published, with no deploy', function (): void {
    expect(RedirectDomainRules::publishedHosts())->toBe([]);

    guardRecord('agec.org')->save();

    expect(RedirectDomainRules::publishedHosts())->toBe(['agec.org']);
});

it('ignores a saving event that does not carry an entry', function (): void {
    // A stub that counts how often the guard asks it for a collection, which only an entry is asked
    $stub = new class
    {
        public int $asked = 0;

        public function collectionHandle(): string
        {
            $this->asked++;

            return 'unrelated';
        }
    };

    (new GuardRedirectHosts)->handle(new EntrySaving($stub));

    expect($stub->asked)->toBe(0);
});
