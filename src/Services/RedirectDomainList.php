<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;

/**
 * The domain list: one published record per domain the application trusts
 * (UAMS-Web/aether-redirects#24).
 *
 * A redirect domain entry's `host` refers to a record of the `redirect_hosts`
 * collection instead of holding a domain as free text. A domain becomes trusted
 * when someone creates its record, and creating one needs its own permission, so
 * publishing a redirect rule can no longer add a trusted address. A stored host
 * string that has not been migrated still resolves while a published record names
 * that domain, so the migration does not change what is served.
 */
final readonly class RedirectDomainList
{
    /**
     * The handle of the collection that holds the domain records.
     */
    public const string COLLECTION = 'redirect_hosts';

    /**
     * The permission to create or change a domain record.
     */
    public const string PERMISSION = 'manage redirect domains';

    /**
     * The published records, as the host each one names keyed by the record id.
     *
     * A record whose host is not a valid domain is left out.
     *
     * @return array<string, string> The normalized host of each record, keyed by id.
     */
    public static function records(): array
    {
        // A host without the collection has an empty list; its entry query would fail
        if (CollectionFacade::findByHandle(self::COLLECTION) === null) {
            return [];
        }

        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', self::COLLECTION);
        /** @var EntryQueryBuilder $query */
        $query = $query->whereStatus('published');

        // The store returns loosely typed items; the `instanceof` below narrows each one
        /** @var Collection<int, mixed> $entries */
        $entries = $query->get();

        $records = [];

        foreach ($entries as $entry) {
            // An entry query yields entries only, so this guard narrows the type for
            // static analysis and never skips anything
            // @pest-mutate-ignore:InstanceOfToTrue,ContinueToBreak
            if (! $entry instanceof StatamicEntry) {
                continue;
            }

            $host = RedirectDomainRules::normalizeHost($entry->get('host'));
            $id = $entry->id();

            if ($host !== null && is_string($id)) {
                $records[$id] = $host;
            }
        }

        return $records;
    }

    /**
     * The trusted hosts, sorted, once each.
     *
     * @return list<string> The hosts of the published records.
     */
    public static function hosts(): array
    {
        $hosts = array_unique(self::records());
        sort($hosts);

        return $hosts;
    }

    /**
     * The id of the published record for a host, or null when none names it.
     *
     * @param  string  $host  A normalized host.
     */
    public static function idFor(string $host): ?string
    {
        $id = array_search($host, self::records(), true);

        return $id === false ? null : $id;
    }

    /**
     * The host a stored `host` value stands for, or null when it names no trusted domain.
     *
     * A record id resolves to the record's host. A host string resolves to itself
     * while a published record names it, which keeps an entry that has not been
     * migrated serving.
     *
     * @param  mixed  $value  The stored `host` value.
     * @param  array<string, string>  $records  The published records, from {@see records()}.
     */
    public static function resolve(mixed $value, array $records): ?string
    {
        // The field stores a list of one id, or a bare id
        $stored = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($stored)) {
            return null;
        }

        if (isset($records[$stored])) {
            return $records[$stored];
        }

        $host = RedirectDomainRules::normalizeHost($stored);

        return $host !== null && in_array($host, $records, true) ? $host : null;
    }

    /**
     * Whether a stored `host` value is the id of a published record.
     *
     * @param  mixed  $value  The stored `host` value.
     */
    public static function isReference(mixed $value): bool
    {
        $stored = is_array($value) ? ($value[0] ?? null) : $value;

        return is_string($stored) && isset(self::records()[$stored]);
    }
}
