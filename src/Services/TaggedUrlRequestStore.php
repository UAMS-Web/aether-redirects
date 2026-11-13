<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

/**
 * Reads, creates and reviews requests for a tagged URL alone, with no vanity URL
 * (UAMS-Web/aether-redirects#18).
 *
 * A request is an entry of the host's `tagged_url_requests` collection. It carries
 * the destination, the request fields a tagged URL takes its values from, the
 * suppress flag, and the same workflow fields a redirect request carries
 * (`status`, `submitted_by`, `reviewed_by`, `rejection_reason`), so the review queue
 * treats it like any other request. It is not a redirect: nothing serves from the
 * collection. Approving it composes its tagged URL from the host's parameters and
 * stores it on the entry as `tagged_url`, so a later change to a parameter or a
 * lookup list does not change a URL already issued.
 *
 * The host owns the collection and its blueprint; the reference files are
 * `docs/blueprints/tagged_url_requests_collection.yaml` and
 * `docs/blueprints/tagged_url_request.yaml`.
 */
final readonly class TaggedUrlRequestStore implements RedirectRequestStore
{
    /**
     * The handle of the collection that holds the requests.
     */
    public const string COLLECTION = 'tagged_url_requests';

    /**
     * The field the issued tagged URL is stored in.
     */
    public const string TAGGED_URL_FIELD = 'tagged_url';

    /**
     * The field the suppress flag is stored in.
     */
    public const string SUPPRESS_FIELD = 'suppress_utm';

    /**
     * @param  TaggedUrlLookupReader  $reader  Reads the host's parameters, to compose the URL at approval
     */
    public function __construct(private TaggedUrlLookupReader $reader) {}

    /**
     * The request fields a tagged-only request carries, which the standard
     * parameters of the same name take their values from (UAMS-Web/aether-redirects#26).
     *
     * A method rather than a constant: PCOV attributes no coverage to a constant's
     * initializer, so a mutation run could not select a test for it.
     *
     * @return list<string>
     */
    public static function requestFields(): array
    {
        return ['utm_campaign', 'utm_id', 'utm_marketing_tactic'];
    }

    /**
     * Whether the host has the collection, without which no request can be saved.
     */
    public static function available(): bool
    {
        return CollectionFacade::findByHandle(self::COLLECTION) !== null;
    }

    /**
     * Every entry of the collection as a row, whatever its status, ordered by destination.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // A host without the collection matches no entries, so no guard is needed
        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', self::COLLECTION);
        /** @var EntryQueryBuilder $query */
        $query = $query->orderBy('title');

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
     * Create a pending entry for a request.
     *
     * @param  TaggedUrlRequest  $request  The destination, request fields and suppress flag
     * @param  string|null  $requester  Free-text requester attribution
     * @param  string|null  $notes  Free-text notes
     * @param  string|null  $submittedBy  The Control Panel user id creating the request
     * @return array<string, mixed> The stored row, including the entry id
     *
     * @throws RuntimeException When the collection does not exist.
     */
    public function create(TaggedUrlRequest $request, ?string $requester, ?string $notes, ?string $submittedBy): array
    {
        if (! self::available()) {
            throw new RuntimeException(sprintf('The "%s" collection does not exist.', self::COLLECTION));
        }

        $fields = [];

        foreach (self::requestFields() as $field) {
            $fields[$field] = $request->fields[$field] ?? null;
        }

        /** @var StatamicEntry $entry */
        $entry = Entry::make();
        $entry->collection(self::COLLECTION);
        $entry->data([
            'title' => $request->destination,
            'destination' => $request->destination,
            ...$fields,
            self::SUPPRESS_FIELD => $request->suppressUtm,
            'requester' => $requester,
            'notes' => $notes,
            'status' => RedirectStatus::Pending->value,
            'submitted_by' => $submittedBy,
            'reviewed_by' => null,
            'rejection_reason' => null,
            self::TAGGED_URL_FIELD => null,
        ]);
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
     * Approve a pending row, issuing its tagged URL and recording the reviewer.
     *
     * @param  string  $id  The entry id.
     * @param  string  $reviewerId  The Control Panel user id approving it.
     * @return array<string, mixed>|null The updated row, or null when no entry has that id.
     */
    public function approve(string $id, string $reviewerId): ?array
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        return $this->review($entry, [
            'status' => RedirectStatus::Approved->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => null,
            self::TAGGED_URL_FIELD => $this->reader->composer()->compose(self::choices(self::rowFor($entry))),
        ]);
    }

    /**
     * Reject a pending row, retaining the reason for the record. No tagged URL is issued.
     *
     * @param  string  $id  The entry id.
     * @param  string  $reviewerId  The Control Panel user id rejecting it.
     * @param  string  $reason  Why it was rejected.
     * @return array<string, mixed>|null The updated row, or null when no entry has that id.
     */
    public function reject(string $id, string $reviewerId, string $reason): ?array
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        return $this->review($entry, [
            'status' => RedirectStatus::Rejected->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * The choices a stored row carries, as the composer reads them.
     *
     * @param  array<string, mixed>  $row  A stored row.
     */
    public static function choices(array $row): TaggedUrlRequest
    {
        $fields = [];

        foreach (self::requestFields() as $field) {
            $value = $row[$field] ?? null;

            if (\is_string($value)) {
                $fields[$field] = $value;
            }
        }

        $destination = $row['destination'] ?? null;

        return new TaggedUrlRequest(
            destination: \is_string($destination) ? $destination : '',
            fields: $fields,
            suppressUtm: ($row[self::SUPPRESS_FIELD] ?? false) === true,
        );
    }

    /**
     * Merge review fields into an entry and save it.
     *
     * @param  StatamicEntry  $entry  The request entry.
     * @param  array<string, mixed>  $fields  The fields to set.
     * @return array<string, mixed> The updated row.
     */
    private function review(StatamicEntry $entry, array $fields): array
    {
        $entry->merge($fields);
        $entry->save();

        return self::rowFor($entry);
    }

    /**
     * The collection's entry with the given id, or null.
     *
     * An id that belongs to an entry of another collection is not a tagged URL
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

        return $entry->collectionHandle() === self::COLLECTION ? $entry : null;
    }

    /**
     * An entry's data with its id.
     *
     * @param  StatamicEntry  $entry  The request entry.
     * @return array<string, mixed>
     */
    private static function rowFor(StatamicEntry $entry): array
    {
        /** @var Collection<string, mixed> $data */
        $data = $entry->data();

        return ['id' => $entry->id(), ...$data->all()];
    }
}
