<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Symfony\Component\Yaml\Yaml;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves its own collections and entries.
uses(IsolatesStache::class);

covers(TaggedUrlRequestStore::class);

/**
 * Integration tests for {@see TaggedUrlRequestStore}: tagged-only requests kept as
 * entries of the host's `tagged_url_requests` collection (UAMS-Web/aether-redirects#18).
 *
 * @command  vendor/bin/pest tests/Integration/Services/TaggedUrlRequestStoreTest.php
 */

/**
 * Save a collection with the given handle.
 */
function turStoreCollection(string $handle = TaggedUrlRequestStore::COLLECTION): void
{
    $collection = Collection::make($handle);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();
}

/**
 * The store, as the container builds it.
 */
function turStore(): TaggedUrlRequestStore
{
    $store = app(TaggedUrlRequestStore::class);
    Assert::assertInstanceOf(TaggedUrlRequestStore::class, $store);

    return $store;
}

it('has nothing and offers nothing when the host has no collection', function (): void {
    expect(TaggedUrlRequestStore::available())->toBeFalse()
        ->and(turStore()->all())->toBe([])
        ->and(turStore()->pending())->toBe([]);
});

it('refuses to create a request when the host has no collection', function (): void {
    turStore()->create(new TaggedUrlRequest('https://www.uams.edu/'), null, null, null);
})->throws(RuntimeException::class, 'The "tagged_url_requests" collection does not exist.');

it('finds nothing for an unknown id or an entry of another collection', function (): void {
    turStoreCollection();
    turStoreCollection('pages');

    $other = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $other);
    $other->collection('pages');
    $other->data(['title' => 'A page', 'status' => 'pending']);
    $other->save();

    $id = $other->id();
    Assert::assertIsString($id);

    expect(turStore()->find('missing'))->toBeNull()
        ->and(turStore()->find($id))->toBeNull()
        ->and(turStore()->approve($id, 'approver'))->toBeNull()
        ->and(turStore()->reject($id, 'approver', 'No'))->toBeNull()
        ->and(turStore()->all())->toBe([]);

    // The other collection's entry is left as it was
    $after = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $after);
    expect($after->get('status'))->toBe('pending');
});

it('stores only the request fields it knows, and lists pending requests only', function (): void {
    turStoreCollection();

    $row = turStore()->create(
        new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'flu', 'utm_source' => 'not-a-request-field']),
        'Comms',
        'Notes',
        'creator',
    );

    expect($row)->toHaveKey('id')
        ->and($row['utm_campaign'])->toBe('flu')
        ->and($row['utm_id'])->toBeNull()
        ->and($row)->not->toHaveKey('utm_source')
        ->and($row['requester'])->toBe('Comms')
        ->and($row['notes'])->toBe('Notes');

    $id = $row['id'];
    Assert::assertIsString($id);

    $second = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/other'), null, null, 'creator');
    $secondId = $second['id'];
    Assert::assertIsString($secondId);
    turStore()->reject($secondId, 'approver', 'No');

    expect(turStore()->all())->toHaveCount(2)
        ->and(array_column(turStore()->pending(), 'id'))->toBe([$id]);
});

it('reads the choices of a stored row, ignoring values that are not text and a suppress flag that is not true', function (array $row, TaggedUrlRequest $expected): void {
    /** @var array<string, mixed> $row */
    expect(TaggedUrlRequestStore::choices($row))->toEqual($expected);
})->with([
    'every value' => [
        ['destination' => 'https://www.uams.edu/', 'utm_campaign' => 'flu', 'utm_id' => '1', 'utm_marketing_tactic' => 'paid', 'suppress_utm' => true],
        new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'flu', 'utm_id' => '1', 'utm_marketing_tactic' => 'paid'], suppressUtm: true),
    ],
    'values that are not text' => [
        ['destination' => 7, 'utm_campaign' => 3, 'utm_id' => null, 'suppress_utm' => 'true'],
        new TaggedUrlRequest(''),
    ],
    'nothing stored' => [[], new TaggedUrlRequest('')],
]);

it('names every field the store writes in the reference blueprint the host copies', function (): void {
    turStoreCollection();

    $row = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/'), null, null, null);

    /** @var array{tabs: array<string, array{sections: list<array{fields: list<array{handle: string}>}>}>} $blueprint */
    $blueprint = Yaml::parseFile(__DIR__.'/../../../docs/blueprints/tagged_url_request.yaml');

    $handles = [];

    foreach ($blueprint['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $handles[] = $field['handle'];
            }
        }
    }

    $written = array_values(array_diff(array_keys($row), ['id', 'title']));
    sort($written);
    sort($handles);

    expect($handles)->toBe($written);
});

it('lists the pending requests as a list, ordered by destination', function (): void {
    turStoreCollection();

    $first = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/a'), null, null, null);
    $second = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/b'), null, null, null);
    $firstId = $first['id'];
    Assert::assertIsString($firstId);
    turStore()->reject($firstId, 'approver', 'No');

    expect(array_column(turStore()->all(), 'destination'))->toBe(['https://www.uams.edu/a', 'https://www.uams.edu/b'])
        ->and(array_keys(turStore()->pending()))->toBe([0])
        ->and(turStore()->pending()[0]['id'])->toBe($second['id']);
});

it('writes a review to the entry file, and clears an earlier rejection reason on approval', function (): void {
    turStoreCollection();

    $row = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'flu']), null, null, null);
    $id = $row['id'];
    Assert::assertIsString($id);

    turStore()->reject($id, 'approver', 'Not yet');
    $approved = turStore()->approve($id, 'approver');

    expect($approved['rejection_reason'] ?? null)->toBeNull()
        ->and($approved['status'] ?? null)->toBe('approved');

    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $file = File::get($entry->path());

    expect($file)->toContain('status: approved')
        ->and($file)->toContain('tagged_url:')
        ->and($file)->not->toContain('Not yet');
});
