<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;

/**
 * Reads, creates and reviews marketing redirect requests kept as entries of the
 * `redirect_domains` collection (UAMS-Web/aether-redirects#83).
 *
 * The collection holds the rules of the vanity redirect domains, one entry per
 * rule with the domain in its `host` field. A request is an entry with a
 * `status`, a validity window and the requester and reviewer ids, the same
 * fields a `redirects` global-set row carries, so {@see RedirectDomainRules} and
 * the edge manifest withhold a pending or rejected entry exactly as they do a row.
 * An entry with no `status` is grandfathered as approved ({@see RedirectStatus::fromRow()}).
 *
 * The host application owns the collection, its blueprint and its configuration.
 * The workflow fields do not need to be in the blueprint to be stored or read, so
 * this store works whether or not the host offers them in the entry editor.
 */
final class RedirectDomainStore implements RedirectRequestStore
{
    /**
     * Every entry of the collection as a row, whatever its status.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return self::rowsFor($this->query());
    }

    /**
     * Create a pending entry for a request.
     *
     * @param  MarketingRedirect  $redirect  The request, with its host set.
     * @return array<string, mixed> The stored row, including the entry id.
     *
     * @throws RuntimeException When the collection does not exist or the request has no host.
     */
    public function create(MarketingRedirect $redirect): array
    {
        $host = $redirect->host;

        if ($host === null) {
            throw new RuntimeException('A redirect domain request needs a host.');
        }

        if (CollectionFacade::findByHandle(RedirectDomainRules::COLLECTION) === null) {
            throw new RuntimeException(sprintf('The "%s" collection does not exist.', RedirectDomainRules::COLLECTION));
        }

        // The collection titles its entries from their fields and sets no slugs, and a new
        // entry is published on the default site, so none of those is set here
        /** @var StatamicEntry $entry */
        $entry = Entry::make();
        $entry->collection(RedirectDomainRules::COLLECTION);
        $entry->data($redirect->toDomainData($host));
        $entry->save();

        return self::rowFor($entry);
    }

    /**
     * The row with the given id, or null when no entry of the collection has it.
     *
     * @param  string  $id  The entry id.
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $entry = $this->entry($id);

        return $entry instanceof StatamicEntry ? self::rowFor($entry) : null;
    }

    /**
     * Every row still awaiting approval.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        // Statamic reserves `status` as an entry query filter, so the workflow status is read from the rows
        return array_values(array_filter($this->all(), static fn (array $row): bool => RedirectStatus::fromRow($row) === RedirectStatus::Pending));
    }

    /**
     * Approve a pending row so it begins serving, recording the reviewer.
     *
     * @param  string  $id  The entry id.
     * @param  string  $reviewerId  The Control Panel user id approving it.
     * @return array<string, mixed>|null The updated row, or null when no entry has that id.
     */
    public function approve(string $id, string $reviewerId): ?array
    {
        return $this->review($id, [
            'status' => RedirectStatus::Approved->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => null,
        ]);
    }

    /**
     * Reject a pending row, retaining the reason for the record.
     *
     * @param  string  $id  The entry id.
     * @param  string  $reviewerId  The Control Panel user id rejecting it.
     * @param  string  $reason  Why it was rejected.
     * @return array<string, mixed>|null The updated row, or null when no entry has that id.
     */
    public function reject(string $id, string $reviewerId, string $reason): ?array
    {
        return $this->review($id, [
            'status' => RedirectStatus::Rejected->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Merge review fields into an entry and save it.
     *
     * @param  string  $id  The entry id.
     * @param  array<string, mixed>  $fields  The fields to set.
     * @return array<string, mixed>|null The updated row, or null when no entry has that id.
     */
    private function review(string $id, array $fields): ?array
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        $entry->merge($fields);
        $entry->save();

        return self::rowFor($entry);
    }

    /**
     * The collection's entry with the given id, or null.
     *
     * An id that belongs to an entry of another collection is not a redirect
     * request, so it finds nothing here.
     *
     * @param  string  $id  The entry id.
     */
    private function entry(string $id): ?StatamicEntry
    {
        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        return $entry->collectionHandle() === RedirectDomainRules::COLLECTION ? $entry : null;
    }

    /**
     * An entry query over the collection, in a stable order.
     */
    private function query(): EntryQueryBuilder
    {
        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', RedirectDomainRules::COLLECTION);
        /** @var EntryQueryBuilder $query */
        $query = $query->orderBy('title');

        return $query;
    }

    /**
     * The rows of the entries a query matches.
     *
     * @param  EntryQueryBuilder  $query  The query.
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(EntryQueryBuilder $query): array
    {
        /** @var Collection<int, mixed> $entries */
        $entries = $query->get();

        $rows = [];

        foreach ($entries as $entry) {
            // An entry query yields entries only, so this guard narrows the type for
            // static analysis and never skips anything
            // @pest-mutate-ignore:InstanceOfToTrue,ContinueToBreak
            if (! $entry instanceof StatamicEntry) {
                continue;
            }

            $rows[] = self::rowFor($entry);
        }

        return $rows;
    }

    /**
     * An entry's data with its id, in the shape of a global-set row.
     *
     * @param  StatamicEntry  $entry  The redirect domain entry.
     * @return array<string, mixed>
     */
    private static function rowFor(StatamicEntry $entry): array
    {
        /** @var Collection<string, mixed> $data */
        $data = $entry->data();

        return ['id' => $entry->id(), ...$data->all()];
    }
}
