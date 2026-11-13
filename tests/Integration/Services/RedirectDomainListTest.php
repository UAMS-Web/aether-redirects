<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

uses(IsolatesStache::class);

/**
 * Integration coverage for {@see RedirectDomainList} (UAMS-Web/aether-redirects#24): which
 * records the list holds, and how a stored `host` value resolves against them.
 *
 * @command  vendor/bin/pest tests/Integration/Services/RedirectDomainListTest.php
 */
beforeEach(function (): void {
    RedirectDomainRecords::register();
});

/**
 * Save a domain record directly, past the guard, as the data says.
 *
 * @param  array<string, mixed>  $data  The record's fields.
 */
function domainListRecord(array $data, bool $published = true): StatamicEntry
{
    $record = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $record);

    $record->collection(RedirectDomainList::COLLECTION);
    $record->locale('default');
    $record->published($published);
    $record->data($data);
    $record->saveQuietly();

    return $record;
}

it('lists the published records, keyed by id, and leaves out an unpublished one and an invalid host', function (): void {
    $kept = domainListRecord(['host' => 'uams.info']);
    domainListRecord(['host' => 'draft.org'], published: false);
    domainListRecord(['host' => 'not a domain']);

    expect(RedirectDomainList::records())->toBe([RedirectDomainRecords::id($kept) => 'uams.info']);
});

it('normalizes the host a record names', function (): void {
    $record = domainListRecord(['host' => ' UAMS.Health. ']);

    expect(RedirectDomainList::records())->toBe([RedirectDomainRecords::id($record) => 'uams.health']);
});

it('has an empty list when the collection does not exist', function (): void {
    foreach (RedirectDomainRecords::entries(RedirectDomainList::COLLECTION) as $entry) {
        $entry->delete();
    }

    expect(RedirectDomainList::records())->toBe([]);
});

it('lists the trusted hosts sorted, once each', function (): void {
    domainListRecord(['host' => 'zeta.org']);
    domainListRecord(['host' => 'alpha.org']);
    domainListRecord(['host' => 'ALPHA.org.']);

    expect(RedirectDomainList::hosts())->toBe(['alpha.org', 'zeta.org']);
});

it('finds the id of the record for a host, and null for a host nobody added', function (): void {
    $record = domainListRecord(['host' => 'uams.info']);

    expect(RedirectDomainList::idFor('uams.info'))->toBe(RedirectDomainRecords::id($record))
        ->and(RedirectDomainList::idFor('uams.health'))->toBeNull();
});

it('resolves a stored value to the host of its record', function (mixed $value): void {
    $record = domainListRecord(['host' => 'uams.info']);
    $stored = match ($value) {
        'id' => RedirectDomainRecords::id($record),
        'list' => [RedirectDomainRecords::id($record)],
        default => $value,
    };

    expect(RedirectDomainList::resolve($stored, RedirectDomainList::records()))->toBe('uams.info');
})->with([
    'a bare id' => ['id'],
    'a list of one id' => ['list'],
    'a host string a record names' => [' UAMS.info. '],
]);

it('resolves a stored value that names no trusted domain to null', function (mixed $value): void {
    domainListRecord(['host' => 'uams.info']);

    expect(RedirectDomainList::resolve($value, RedirectDomainList::records()))->toBeNull();
})->with([
    'an id nobody holds' => ['00000000-0000-0000-0000-000000000000'],
    'a host string no record names' => ['uams.health'],
    'text that is not a domain' => ['not a domain'],
    'nothing' => [null],
    'an empty list' => [[]],
    'a list holding a list' => [[['uams.info']]],
    'a number' => [42],
]);

it('knows a stored value is a reference only when it is the id of a published record', function (): void {
    $record = domainListRecord(['host' => 'uams.info']);
    $draft = domainListRecord(['host' => 'draft.org'], published: false);

    expect(RedirectDomainList::isReference(RedirectDomainRecords::id($record)))->toBeTrue()
        ->and(RedirectDomainList::isReference([RedirectDomainRecords::id($record)]))->toBeTrue()
        ->and(RedirectDomainList::isReference(RedirectDomainRecords::id($draft)))->toBeFalse()
        ->and(RedirectDomainList::isReference('uams.info'))->toBeFalse()
        ->and(RedirectDomainList::isReference(null))->toBeFalse();
});
