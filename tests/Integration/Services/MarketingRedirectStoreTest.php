<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

/**
 * Feature coverage for {@see MarketingRedirectStore}'s approval mutations (UAMS-Web/uams-statamic-archive#1357):
 * creating a pending request, listing/counting pending rows, and the approve /
 * reject transitions on the shared `redirects` global.
 *
 * @command  vendor/bin/pest tests/Integration/Services/MarketingRedirectStoreTest.php
 */
uses(IsolatesStache::class);

beforeEach(function (): void {
    // Provide the shared redirects global the store reads and writes.
    /** @var Statamic\Globals\GlobalSet $set */
    $set = GlobalSet::make('redirects');
    $set->title('Redirects');
    $set->save();

    /** @var Statamic\Sites\Site $defaultSite */
    $defaultSite = Site::default();
    /** @var Variables $vars */
    $vars = $set->makeLocalization($defaultSite->handle());
    $vars->data(['redirects' => []]);
    $vars->save();
});

/**
 * The default site's handle, failing by name when there is no default site.
 *
 * @return string The default site's handle.
 */
function storeDefaultSiteHandle(): string
{
    $site = Site::default();

    // Fail by name rather than calling handle() on a non-site
    if (! $site instanceof Statamic\Sites\Site) {
        throw new RuntimeException('Expected the default site to be a Statamic site, got '.get_debug_type($site).'.');
    }

    $handle = $site->handle();

    // Fail by name rather than returning a non-string handle
    if (! is_string($handle)) {
        throw new RuntimeException('Expected the default site handle to be a string, got '.get_debug_type($handle).'.');
    }

    return $handle;
}

/**
 * The stored `redirects` field, failing by name when it is not an array.
 *
 * @param  mixed  $value  The field's stored value.
 * @return array<array-key, mixed> The stored rows.
 */
function storeRowsField(mixed $value): array
{
    // Fail by name rather than reading a non-array as rows
    if (! is_array($value)) {
        throw new RuntimeException('Expected the stored rows to be an array, got '.get_debug_type($value).'.');
    }

    return $value;
}

/**
 * Create a pending redirect through the store and return its stored id.
 */
function storeRedirect(string $source, ?string $submittedBy = null): string
{
    $row = app(MarketingRedirectStore::class)->create(
        MarketingRedirect::fromInput(['source' => $source, 'destination' => '/target'], $submittedBy)
    );

    /** @var string $id */
    $id = $row['id'];

    return $id;
}

it('creates a pending redirect and counts it as pending', function (): void {
    $store = app(MarketingRedirectStore::class);

    storeRedirect('/flu', 'user-1');

    expect($store->pendingCount())->toBe(1)
        ->and($store->pending())->toHaveCount(1)
        ->and(RedirectStatus::fromRow($store->all()[0]))->toBe(RedirectStatus::Pending);
});

it('finds a stored row by id and returns null for an unknown id', function (): void {
    $store = app(MarketingRedirectStore::class);

    $id = storeRedirect('/flu');

    /** @var array<string, mixed> $found */
    $found = $store->find($id);

    expect($found['source'])->toBe('/flu')
        ->and($store->find('does-not-exist'))->toBeNull();
});

it('approves a pending redirect, recording the reviewer and dropping it from pending', function (): void {
    $store = app(MarketingRedirectStore::class);

    $id = storeRedirect('/flu', 'author-1');

    /** @var array<string, mixed> $updated */
    $updated = $store->approve($id, 'reviewer-9');

    /** @var array<string, mixed> $found */
    $found = $store->find($id);

    expect($updated['status'])->toBe('approved')
        ->and($updated['reviewed_by'])->toBe('reviewer-9')
        ->and($updated['rejection_reason'])->toBeNull()
        ->and($store->pendingCount())->toBe(0)
        // The change is persisted, not just returned.
        ->and($found['status'])->toBe('approved');
});

it('rejects a pending redirect, retaining the reason', function (): void {
    $store = app(MarketingRedirectStore::class);

    $id = storeRedirect('/flu', 'author-1');

    /** @var array<string, mixed> $updated */
    $updated = $store->reject($id, 'reviewer-9', 'Points off-domain.');

    /** @var array<string, mixed> $found */
    $found = $store->find($id);

    expect($updated['status'])->toBe('rejected')
        ->and($updated['reviewed_by'])->toBe('reviewer-9')
        ->and($updated['rejection_reason'])->toBe('Points off-domain.')
        ->and($store->pendingCount())->toBe(0)
        ->and($found['rejection_reason'])->toBe('Points off-domain.');
});

it('clears a stale rejection reason when a rejected redirect is later approved', function (): void {
    $store = app(MarketingRedirectStore::class);

    $id = storeRedirect('/flu', 'author-1');
    $store->reject($id, 'reviewer-9', 'Points off-domain.');

    /** @var array<string, mixed> $updated */
    $updated = $store->approve($id, 'reviewer-9');

    /** @var array<string, mixed> $found */
    $found = $store->find($id);

    // Approving supersedes an earlier rejection — the reason must not linger.
    expect($updated['status'])->toBe('approved')
        ->and($updated['rejection_reason'])->toBeNull()
        ->and($found['rejection_reason'])->toBeNull();
});

it('returns null when mutating an unknown id and leaves the list untouched', function (): void {
    $store = app(MarketingRedirectStore::class);

    storeRedirect('/flu');

    expect($store->approve('nope', 'reviewer-9'))->toBeNull()
        ->and($store->reject('nope', 'reviewer-9', 'reason'))->toBeNull()
        ->and($store->pendingCount())->toBe(1);
});

it('only counts pending rows, ignoring approved, rejected and legacy rows', function (): void {
    $store = app(MarketingRedirectStore::class);

    // The pending row is deliberately NOT first, so pending() must reindex.
    $approved = storeRedirect('/a', 'author-1');
    $pending = storeRedirect('/b', 'author-1');
    $rejected = storeRedirect('/c', 'author-1');
    $store->approve($approved, 'reviewer-9');
    $store->reject($rejected, 'reviewer-9', 'no');

    // A legacy row written straight into the global carries no status key.
    /** @var Statamic\Sites\Site $defaultSite */
    $defaultSite = Site::default();
    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    /** @var Variables $vars */
    $vars = $globalSet->in($defaultSite->handle());

    /** @var list<array<string, mixed>> $rows */
    $rows = $vars->get('redirects');
    $rows[] = ['id' => 'legacy-1', 'type' => 'redirect', 'source' => '/legacy', 'target_url' => '/x', 'enabled' => true];
    $vars->set('redirects', $rows);
    $vars->save();

    expect($store->pendingCount())->toBe(1)
        ->and($store->pending()[0]['id'])->toBe($pending);
});

/**
 * Replace the redirects field on the default-site localization with the given value.
 */
function storeRedirectsField(mixed $value): void
{
    /** @var Statamic\Sites\Site $defaultSite */
    $defaultSite = Site::default();
    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    /** @var Variables $vars */
    $vars = $globalSet->in($defaultSite->handle());
    $vars->set('redirects', $value);
    $vars->save();
}

/**
 * A stored redirect row with the given id and source.
 *
 * @return array<string, mixed>
 */
function storeRedirectRow(string $id, string $source): array
{
    return MarketingRedirect::fromInput(['source' => $source, 'destination' => '/target'])->toRow($id);
}

it('reads no rows, and refuses every write, when the redirects global does not exist', function (): void {
    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    $globalSet->delete();

    $store = app(MarketingRedirectStore::class);
    $message = 'The "redirects" global set does not exist.';

    expect($store->all())->toBe([])
        ->and($store->find('any'))->toBeNull()
        ->and($store->pendingCount())->toBe(0)
        // Nothing to append needs no global
        ->and($store->appendMany([]))->toBe(0)
        ->and(fn () => $store->create(MarketingRedirect::fromInput(['source' => '/flu', 'destination' => '/target'])))->toThrow(RuntimeException::class, $message)
        ->and(fn () => $store->appendMany([storeRedirectRow('row-1', '/flu')]))->toThrow(RuntimeException::class, $message)
        ->and(fn () => $store->approve('row-1', 'reviewer-9'))->toThrow(RuntimeException::class, $message)
        ->and(fn () => $store->reject('row-1', 'reviewer-9', 'no'))->toThrow(RuntimeException::class, $message);
});

it('reads no rows when the redirects global has no default-site localization', function (): void {
    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    /** @var Variables $vars */
    $vars = $globalSet->in(storeDefaultSiteHandle());
    $vars->delete();

    expect(app(MarketingRedirectStore::class)->all())->toBe([]);
});

it('treats a redirects field that is not a list as empty, and writes over it', function (): void {
    storeRedirectsField('not a list');
    $store = app(MarketingRedirectStore::class);

    expect($store->all())->toBe([]);

    $created = $store->create(MarketingRedirect::fromInput(['source' => '/flu', 'destination' => '/target']));

    expect($store->all())->toBe([$created])
        ->and($store->approve('missing', 'reviewer-9'))->toBeNull();

    storeRedirectsField('not a list');

    expect($store->appendMany([storeRedirectRow('row-1', '/a')]))->toBe(1)
        ->and(array_column($store->all(), 'id'))->toBe(['row-1']);
});

it('reads a keyed field back as a list', function (): void {
    storeRedirectsField(['first' => storeRedirectRow('row-1', '/a'), 'second' => storeRedirectRow('row-2', '/b')]);

    expect(array_keys(app(MarketingRedirectStore::class)->all()))->toBe([0, 1]);
});

it('appends many rows after the existing ones, as a list, and counts what it added', function (): void {
    storeRedirectsField(['kept' => storeRedirectRow('row-1', '/a')]);
    $store = app(MarketingRedirectStore::class);

    $added = $store->appendMany([storeRedirectRow('row-2', '/b'), storeRedirectRow('row-3', '/c')]);

    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    /** @var Variables $vars */
    $vars = $globalSet->in(storeDefaultSiteHandle());

    expect($added)->toBe(2)
        ->and(array_keys(storeRowsField($vars->get('redirects'))))->toBe([0, 1, 2])
        ->and(array_column($store->all(), 'id'))->toBe(['row-1', 'row-2', 'row-3']);
});

it('skips rows that are not arrays or carry another id when approving', function (): void {
    storeRedirectsField(['junk', ['source' => '/no-id'], storeRedirectRow('row-1', '/a'), storeRedirectRow('row-2', '/b')]);
    $store = app(MarketingRedirectStore::class);

    $approved = $store->approve('row-2', 'reviewer-9');

    $rows = $store->all();

    expect($approved['id'] ?? null)->toBe('row-2')
        // The rows it passed over are left exactly as they were
        ->and($rows[0])->toBe('junk')
        ->and($rows[1])->toBe(['source' => '/no-id'])
        ->and(array_column($rows, 'status'))->toBe(['pending', 'approved']);
});

/**
 * Count the saves of the redirects global made while the callback runs.
 */
function storeRedirectSaves(Closure $callback): int
{
    $saves = 0;
    Event::listen(GlobalVariablesSaved::class, function () use (&$saves): void {
        $saves++;
    });

    $callback();

    return $saves;
}

/**
 * The stored redirects field, read straight from the default-site localization.
 *
 * @return array<array-key, mixed>
 */
function storeRedirectsStored(): array
{
    /** @var Statamic\Globals\GlobalSet $globalSet */
    $globalSet = GlobalSet::findByHandle('redirects');
    /** @var Variables $vars */
    $vars = $globalSet->in(storeDefaultSiteHandle());

    return storeRowsField($vars->get('redirects'));
}

it('saves the global once per write, and not for a change that finds no row', function (): void {
    $store = app(MarketingRedirectStore::class);
    $id = storeRedirect('/flu');

    expect(storeRedirectSaves(fn () => $store->create(MarketingRedirect::fromInput(['source' => '/b', 'destination' => '/target']))))->toBe(1)
        ->and(storeRedirectSaves(fn () => $store->appendMany([storeRedirectRow('row-1', '/c')])))->toBe(1)
        ->and(storeRedirectSaves(fn () => $store->approve($id, 'reviewer-9')))->toBe(1)
        ->and(storeRedirectSaves(fn () => $store->reject($id, 'reviewer-9', 'no')))->toBe(1)
        ->and(storeRedirectSaves(fn () => $store->approve('missing', 'reviewer-9')))->toBe(0);
});

it('stores a list after a create or a change over a keyed field', function (Closure $write): void {
    storeRedirectsField(['first' => storeRedirectRow('row-1', '/a'), 'second' => storeRedirectRow('row-2', '/b')]);

    $write(app(MarketingRedirectStore::class));

    expect(array_is_list(storeRedirectsStored()))->toBeTrue();
})->with([
    'create' => [fn (MarketingRedirectStore $store): array => $store->create(MarketingRedirect::fromInput(['source' => '/c', 'destination' => '/target']))],
    'approve' => [fn (MarketingRedirectStore $store): ?array => $store->approve('row-2', 'reviewer-9')],
]);

it('changes only the first row carrying the id', function (): void {
    storeRedirectsField([storeRedirectRow('row-1', '/a'), storeRedirectRow('row-1', '/b')]);

    app(MarketingRedirectStore::class)->approve('row-1', 'reviewer-9');

    expect(array_column(storeRedirectsStored(), 'status'))->toBe(['approved', 'pending']);
});
