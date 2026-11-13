<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Tests\Support;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Stores\CollectionsStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * Test helpers for the domain list (UAMS-Web/aether-redirects#24): registering the
 * `redirect_hosts` collection from its fixture and creating domain records, so a
 * test can write the reference a redirect domain entry now holds.
 */
final class RedirectDomainRecords
{
    /**
     * Register the domain list collection from the fixture copy of the host's config file.
     */
    public static function register(): void
    {
        if (Collection::findByHandle(RedirectDomainList::COLLECTION) !== null) {
            return;
        }

        $path = __DIR__.'/../Fixtures/redirect_hosts.yaml';
        $store = Stache::store('collections');
        Assert::assertInstanceOf(CollectionsStore::class, $store);

        $collection = $store->makeItemFromFile($path, File::get($path));
        Assert::assertInstanceOf(StatamicCollection::class, $collection);

        $collection->save();
    }

    /**
     * The id of the published record for a host, created when none names it.
     *
     * @param  string  $host  A valid host.
     */
    public static function idFor(string $host): string
    {
        self::register();

        $existing = RedirectDomainList::idFor(RedirectDomainRules::normalizeHost($host) ?? $host);

        if ($existing !== null) {
            return $existing;
        }

        $record = Entry::make();
        Assert::assertInstanceOf(StatamicEntry::class, $record);

        $record->collection(RedirectDomainList::COLLECTION);
        $record->locale('default');
        $record->published(true);
        $record->data(['host' => $host]);
        $record->save();

        return self::id($record);
    }

    /**
     * Entry data with its host string replaced by the id of the host's record.
     *
     * @param  array<string, mixed>  $data  The entry's fields.
     * @return array<string, mixed>
     */
    public static function refer(array $data): array
    {
        if (isset($data['host']) && is_string($data['host'])) {
            $data['host'] = self::idFor($data['host']);
        }

        return $data;
    }

    /**
     * Drop the Stache's in-memory items, so the next read comes from the files.
     */
    public static function reload(): void
    {
        Stache::clear();
    }

    /**
     * An entry's id as a string, failing the test when it is anything else.
     *
     * @param  StatamicEntry  $entry  A saved entry.
     */
    public static function id(StatamicEntry $entry): string
    {
        $id = $entry->id();
        Assert::assertIsString($id);

        return $id;
    }

    /**
     * A field of the stored copy of an entry.
     *
     * @param  StatamicEntry  $entry  A saved entry.
     * @param  string  $field  The field handle.
     */
    public static function stored(StatamicEntry $entry, string $field): mixed
    {
        $fresh = Entry::find($entry->id());
        Assert::assertInstanceOf(StatamicEntry::class, $fresh);

        return $fresh->get($field);
    }

    /**
     * The `host` of the stored copy of an entry, which must be a string.
     *
     * @param  StatamicEntry  $entry  A saved entry.
     */
    public static function storedHost(StatamicEntry $entry): string
    {
        $host = self::stored($entry, 'host');
        Assert::assertIsString($host);

        return $host;
    }

    /**
     * The entries of a collection.
     *
     * @param  string  $collection  The collection handle.
     * @return list<StatamicEntry>
     */
    public static function entries(string $collection): array
    {
        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', $collection);

        $entries = [];

        /** @var iterable<mixed> $found */
        $found = $query->get();

        foreach ($found as $entry) {
            Assert::assertInstanceOf(StatamicEntry::class, $entry);
            $entries[] = $entry;
        }

        return $entries;
    }
}
