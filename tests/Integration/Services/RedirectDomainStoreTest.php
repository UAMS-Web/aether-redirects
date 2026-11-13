<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

/**
 * Integration coverage for {@see RedirectDomainStore} (UAMS-Web/aether-redirects#83): creating a
 * pending request as an entry, and the approve and reject transitions on the `redirect_domains` collection.
 *
 * @command  vendor/bin/pest tests/Integration/Services/RedirectDomainStoreTest.php
 */
uses(IsolatesStache::class);

beforeEach(function (): void {
    // The host owns the collection and its blueprint; copy its fixtures
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

    // The domain the requests name
    RedirectDomainRecords::idFor('uams.info');
});

/**
 * An entry id as a string, failing the test when it is anything else.
 */
function domainStoreId(mixed $id): string
{
    Assert::assertIsString($id);

    return $id;
}

/**
 * A request for a domain, as the utility builds it.
 *
 * @param  array<string, mixed>  $overrides
 */
function domainStoreRequest(array $overrides = []): MarketingRedirect
{
    /** @var array{source: string, destination: string, host?: string|null} $input */
    $input = [
        'host' => 'uams.info',
        'source' => '/flu',
        'destination' => 'https://www.uams.edu/flu',
        ...$overrides,
    ];

    return MarketingRedirect::fromInput($input, 'user-1');
}

/**
 * The status of a stored row.
 *
 * @param  array<string, mixed>|null  $row
 */
function domainStoreStatus(?array $row): RedirectStatus
{
    Assert::assertIsArray($row);

    return RedirectStatus::fromRow($row);
}

it('creates a pending entry and finds it by id', function (): void {
    $store = new RedirectDomainStore;

    $row = $store->create(domainStoreRequest());

    expect($row['status'])->toBe('pending')
        ->and($row['host'])->toBe('uams.info')
        ->and($row['submitted_by'])->toBe('user-1')
        ->and($store->find(domainStoreId($row['id'])))->toBe($row)
        ->and($store->find('missing'))->toBeNull();
});

it('refuses to create a request with no host, or without the collection', function (): void {
    $store = new RedirectDomainStore;

    expect(fn (): array => $store->create(domainStoreRequest(['host' => null])))->toThrow(RuntimeException::class, 'needs a host');

    $collection = Collection::findByHandle(RedirectDomainRules::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->delete();

    expect(fn (): array => $store->create(domainStoreRequest()))->toThrow(RuntimeException::class, 'collection does not exist');
});

it('lists only pending rows, in a list', function (): void {
    $store = new RedirectDomainStore;

    // Entries read in title order, so the pending one comes last and keeps no gap in its position
    $approved = $store->create(domainStoreRequest(['source' => '/a']));
    $rejected = $store->create(domainStoreRequest(['source' => '/b']));
    $pending = $store->create(domainStoreRequest(['source' => '/c']));
    $store->approve(domainStoreId($approved['id']), 'reviewer');
    $store->reject(domainStoreId($rejected['id']), 'reviewer', 'No');

    $rows = $store->pending();

    expect(array_keys($rows))->toBe([0])
        ->and($rows[0]['id'])->toBe($pending['id'])
        ->and($store->all())->toHaveCount(3);
});

it('approves a pending entry, recording the reviewer and clearing a stale reason', function (): void {
    $store = new RedirectDomainStore;
    $row = $store->create(domainStoreRequest());
    $id = domainStoreId($row['id']);

    $store->reject($id, 'first-reviewer', 'Wrong destination');
    $approved = $store->approve($id, 'second-reviewer');

    Assert::assertIsArray($approved);
    expect($approved['status'])->toBe('approved')
        ->and($approved['reviewed_by'])->toBe('second-reviewer')
        ->and($approved['rejection_reason'])->toBeNull()
        ->and($store->find($id))->toBe($approved)
        ->and(domainStoreStatus($store->find($id))->servesTraffic())->toBeTrue();
});

it('rejects a pending entry, retaining the reviewer and reason', function (): void {
    $store = new RedirectDomainStore;
    $id = domainStoreId($store->create(domainStoreRequest())['id']);

    $rejected = $store->reject($id, 'reviewer', 'Not a marketing piece');

    Assert::assertIsArray($rejected);
    expect($rejected['status'])->toBe('rejected')
        ->and($rejected['reviewed_by'])->toBe('reviewer')
        ->and($rejected['rejection_reason'])->toBe('Not a marketing piece')
        ->and($store->find($id))->toBe($rejected);
});

it('returns null when reviewing an unknown id, and an entry of another collection', function (): void {
    $store = new RedirectDomainStore;

    Collection::make('other_collection')->save();

    $other = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $other);
    $other->collection('other_collection');
    $other->locale('default');
    $other->data(['title' => 'Elsewhere']);
    $other->save();

    expect($store->approve('missing', 'reviewer'))->toBeNull()
        ->and($store->reject('missing', 'reviewer', 'No'))->toBeNull()
        ->and($store->find(domainStoreId($other->id())))->toBeNull()
        ->and($store->approve(domainStoreId($other->id()), 'reviewer'))->toBeNull();
});

it('saves the entry on create, approve and reject', function (): void {
    Event::fake([EntrySaved::class]);
    $store = new RedirectDomainStore;

    $id = domainStoreId($store->create(domainStoreRequest())['id']);
    Event::assertDispatchedTimes(EntrySaved::class, 1);

    $store->approve($id, 'reviewer');
    Event::assertDispatchedTimes(EntrySaved::class, 2);

    $store->reject($id, 'reviewer', 'Changed my mind');
    Event::assertDispatchedTimes(EntrySaved::class, 3);

    $store->approve('missing', 'reviewer');
    Event::assertDispatchedTimes(EntrySaved::class, 3);
});
