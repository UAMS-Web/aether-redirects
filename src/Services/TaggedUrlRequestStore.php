<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;
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
     * The field the chosen channel option's id is stored in (UAMS-Web/aether-redirects#26).
     */
    public const string CHANNEL_FIELD = 'channel';

    /**
     * The field the option chosen at each cascade step is stored in, as ids by step.
     */
    public const string STEPS_FIELD = 'steps';

    /**
     * The field the options chosen from other lookup lists are stored in, as ids by list id.
     */
    public const string LISTS_FIELD = 'lists';

    /**
     * The field the free-text custom values are stored in, by step or list id.
     */
    public const string CUSTOM_FIELD = 'custom_values';

    /**
     * The field the request field values are stored in, by handle.
     *
     * Kept apart from the entry's own fields, so a handle a site's parameter reads can never
     * overwrite one of them (`status`, `reviewed_by` and the like).
     */
    public const string REQUEST_FIELDS_FIELD = 'request_fields';

    /**
     * @param  TaggedUrlLookupReader  $reader  Reads the host's parameters, to compose the URL at approval
     */
    public function __construct(private TaggedUrlLookupReader $reader) {}

    /**
     * The request fields a request carried before its fields followed the site's parameters
     * (UAMS-Web/aether-redirects#26), which were stored as fields of the entry itself.
     *
     * A row saved then still reads through {@see choices()}; new requests store their
     * request fields under {@see REQUEST_FIELDS_FIELD}.
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
     * Whether the host defines the parameters a tagged URL carries, without which approval would issue the bare destination.
     */
    public static function canIssue(): bool
    {
        return GlobalSet::findByHandle(TaggedUrlLookup::PARAMETERS_GLOBAL) !== null;
    }

    /**
     * Every entry of the collection as a row, whatever its status, ordered by title (the destination when it was requested).
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
     * @param  TaggedUrlRequest  $request  The destination, choices, request fields and suppress flag
     * @param  string|null  $requester  Free-text requester attribution
     * @param  string|null  $notes  Free-text notes
     * @param  string|null  $submittedBy  The Control Panel user id creating the request
     * @param  bool  $creativeServices  Whether the request involves Creative Services (UAMS-Web/aether-redirects#25)
     * @param  string|null  $jobNumber  The Creative Services job number, when it does
     * @return array<string, mixed> The stored row, including the entry id
     *
     * @throws RuntimeException When the collection does not exist.
     */
    public function create(
        TaggedUrlRequest $request,
        ?string $requester,
        ?string $notes,
        ?string $submittedBy,
        bool $creativeServices,
        ?string $jobNumber,
    ): array {
        if (! self::available()) {
            throw new RuntimeException(sprintf('The "%s" collection does not exist.', self::COLLECTION));
        }

        /** @var StatamicEntry $entry */
        $entry = Entry::make();
        $entry->collection(self::COLLECTION);
        $entry->data([
            'title' => $request->destination,
            'destination' => $request->destination,
            self::CHANNEL_FIELD => $request->channel,
            self::STEPS_FIELD => $request->steps,
            self::LISTS_FIELD => $request->lists,
            self::CUSTOM_FIELD => $request->custom,
            self::REQUEST_FIELDS_FIELD => $request->fields,
            self::SUPPRESS_FIELD => $request->suppressUtm,
            'creative_services' => $creativeServices,
            'job_number' => $jobNumber,
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
     * Only text is read back: ids and typed values are kept as the strings they were saved
     * as, and anything else a hand-edited entry holds is ignored. A row saved before the
     * choices were stored has its request fields as fields of the entry, and reads the same.
     *
     * @param  array<string, mixed>  $row  A stored row.
     */
    public static function choices(array $row): TaggedUrlRequest
    {
        // The fields of an older row sit on the entry; a stored value of the same handle wins
        $fields = [];

        foreach (self::requestFields() as $field) {
            $value = $row[$field] ?? null;

            if (\is_string($value)) {
                $fields[$field] = $value;
            }
        }

        $fields = [...$fields, ...self::texts($row[self::REQUEST_FIELDS_FIELD] ?? null)];

        $destination = $row['destination'] ?? null;
        $channel = $row[self::CHANNEL_FIELD] ?? null;

        return new TaggedUrlRequest(
            destination: \is_string($destination) ? $destination : '',
            channel: \is_string($channel) && $channel !== '' ? $channel : null,
            steps: self::texts($row[self::STEPS_FIELD] ?? null),
            lists: self::texts($row[self::LISTS_FIELD] ?? null),
            custom: self::texts($row[self::CUSTOM_FIELD] ?? null),
            fields: $fields,
            suppressUtm: ($row[self::SUPPRESS_FIELD] ?? false) === true,
        );
    }

    /**
     * The choices of a stored row as an approver reads them: each option's label beside what it is for.
     *
     * The channel comes first, then each step in cascade order, then each list choice named
     * for the parameter that reads it. A choice whose option has since been removed shows its id,
     * since the label is gone (UAMS-Web/aether-redirects#120).
     *
     * @param  array<string, mixed>  $row  A stored row.
     * @return list<array{name: string, option: string, custom: string|null}>
     */
    public function choiceSummary(array $row): array
    {
        $request = self::choices($row);
        $catalog = $this->reader->catalog();

        // The parameter that reads each list names it
        $listNames = [];

        foreach ($this->reader->parameterList() as $parameter) {
            if ($parameter->from === TaggedUrlParameter::FROM_LIST) {
                $listNames[$parameter->source] = $parameter->name;
            }
        }

        $label = static fn (string $id): string => $catalog->option($id)?->label ?? $id;
        $summary = [];

        if ($request->channel !== null) {
            $summary[] = ['name' => self::CHANNEL_FIELD, 'option' => $label($request->channel), 'custom' => null];
        }

        foreach (TaggedUrlLookup::steps() as $step) {
            if (isset($request->steps[$step])) {
                $summary[] = ['name' => $step, 'option' => $label($request->steps[$step]), 'custom' => $request->custom[$step] ?? null];
            }
        }

        foreach ($request->lists as $listId => $optionId) {
            $summary[] = ['name' => $listNames[$listId] ?? sprintf('%s', $listId), 'option' => $label($optionId), 'custom' => $request->custom[$listId] ?? null];
        }

        return $summary;
    }

    /**
     * The text entries of a stored map.
     *
     * @param  mixed  $value  The stored value
     * @return array<array-key, string> The entries whose value is text, in stored order (a numeric key reads back as a number)
     */
    private static function texts(mixed $value): array
    {
        return array_filter(\is_array($value) ? $value : [], \is_string(...));
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
