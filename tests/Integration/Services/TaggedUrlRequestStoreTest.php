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
    turStore()->create(new TaggedUrlRequest('https://www.uams.edu/'), null, null, null, false, null);
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

it('stores the choices it is given apart from its own fields, and lists pending requests only', function (): void {
    turStoreCollection();

    $row = turStore()->create(
        new TaggedUrlRequest(
            'https://www.uams.edu/',
            channel: 'ch1',
            steps: ['source' => 's1', 'medium' => 'm1'],
            lists: ['l1' => 'o1'],
            custom: ['medium' => 'Extra'],
            fields: ['utm_campaign' => 'flu', 'status' => 'approved'],
        ),
        'Comms',
        'Notes',
        'creator',
        false,
        null,
    );

    expect($row)->toHaveKey('id')
        ->and($row['channel'])->toBe('ch1')
        ->and($row['steps'])->toBe(['source' => 's1', 'medium' => 'm1'])
        ->and($row['lists'])->toBe(['l1' => 'o1'])
        ->and($row['custom_values'])->toBe(['medium' => 'Extra'])
        ->and($row['request_fields'])->toBe(['utm_campaign' => 'flu', 'status' => 'approved'])
        ->and($row['status'])->toBe('pending')
        ->and($row['requester'])->toBe('Comms')
        ->and($row['notes'])->toBe('Notes');

    $id = $row['id'];
    Assert::assertIsString($id);

    $second = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/other'), null, null, 'creator', false, null);
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
    'a row saved before the choices were stored' => [
        ['destination' => 'https://www.uams.edu/', 'utm_campaign' => 'flu'],
        new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'flu']),
    ],
    'the stored request fields win over an older field of the same handle' => [
        ['destination' => 'https://www.uams.edu/', 'utm_campaign' => 'old', 'request_fields' => ['utm_campaign' => 'new', 'vanity_path' => '/flu']],
        new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'new', 'vanity_path' => '/flu']),
    ],
    'every choice' => [
        ['destination' => 'https://www.uams.edu/', 'channel' => 'ch1', 'steps' => ['source' => 's1'], 'lists' => ['l1' => 'o1'], 'custom_values' => ['source' => 'x']],
        new TaggedUrlRequest('https://www.uams.edu/', 'ch1', ['source' => 's1'], ['l1' => 'o1'], ['source' => 'x']),
    ],
    'choices that are not text, and a blank channel' => [
        ['destination' => 'https://www.uams.edu/', 'channel' => '', 'steps' => ['source' => 5, 'medium' => 'm1'], 'lists' => 'l1', 'custom_values' => ['source' => ['x']], 'request_fields' => 'x'],
        new TaggedUrlRequest('https://www.uams.edu/', steps: ['medium' => 'm1']),
    ],
    'a channel that is not text' => [
        ['destination' => 'https://www.uams.edu/', 'channel' => 7],
        new TaggedUrlRequest('https://www.uams.edu/'),
    ],
]);

it('names every field the store writes in the reference blueprint the host copies', function (): void {
    turStoreCollection();

    $row = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/'), null, null, null, false, null);

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

    // The three fixed fields stay in the blueprint for the requests saved before the request fields moved into their own map
    $legacy = TaggedUrlRequestStore::requestFields();
    $current = array_values(array_diff($handles, $legacy));
    sort($written);
    sort($current);

    expect($current)->toBe($written)
        ->and(array_values(array_intersect($handles, $legacy)))->toEqualCanonicalizing($legacy);
});

it('lists the pending requests as a list, ordered by destination', function (): void {
    turStoreCollection();

    $first = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/a'), null, null, null, false, null);
    $second = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/b'), null, null, null, false, null);
    $firstId = $first['id'];
    Assert::assertIsString($firstId);
    turStore()->reject($firstId, 'approver', 'No');

    expect(array_column(turStore()->all(), 'destination'))->toBe(['https://www.uams.edu/a', 'https://www.uams.edu/b'])
        ->and(array_keys(turStore()->pending()))->toBe([0])
        ->and(turStore()->pending()[0]['id'])->toBe($second['id']);
});

it('writes a review to the entry file, and clears an earlier rejection reason on approval', function (): void {
    turStoreCollection();

    $row = turStore()->create(new TaggedUrlRequest('https://www.uams.edu/', fields: ['utm_campaign' => 'flu']), null, null, null, false, null);
    $id = $row['id'];
    Assert::assertIsString($id);

    turStore()->reject($id, 'approver', 'Not yet');
    $approved = turStore()->approve($id, 'approver');

    expect($approved['rejection_reason'] ?? null)->toBeNull()
        ->and($approved['status'] ?? null)->toBe('approved');

    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $path = $entry->path();
    Assert::assertIsString($path);
    $file = File::get($path);

    expect($file)->toContain('status: approved')
        ->and($file)->toContain('tagged_url:')
        ->and($file)->not->toContain('Not yet');
});
