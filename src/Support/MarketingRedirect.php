<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * A typed, immutable representation of a marketing/vanity redirect created
 * through the CP utility (UAMS-Web/uams-statamic-archive#1355).
 *
 * This is the stable shape the collision-detection (UAMS-Web/uams-statamic-archive#1356) and later
 * approval/public-intake slices consume, rather than reverse-engineering the raw
 * replicator row. The utility captures an explicit-URL destination (the vanity
 * use case); entry-target redirects remain available through the raw globals
 * editor and are out of this slice's scope.
 *
 * A redirect is written into the shared `redirects` global as one `redirect`
 * replicator row via {@see toRow()}, matching the keys the serving tag
 * ({@see FindRedirectRule}) reads.
 */
final readonly class MarketingRedirect
{
    /**
     * The replicator set handle every redirect row is stored under.
     */
    public const string SET_HANDLE = 'redirect';

    /**
     * The default `order` value for a utility-created redirect, matching the
     * blueprint default so page-specific redirects sort predictably.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the stored `order` assertion in `MarketingRedirectTest` pins it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int DEFAULT_ORDER = 30;

    /**
     * The HTTP status codes the utility offers (a marketing redirect is a move,
     * never a wildcard/regex or error response).
     *
     * @var list<string>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the allowed-responses test in `MarketingRedirectTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem
    public const array ALLOWED_RESPONSES = ['301', '302'];

    /**
     * @param  string  $source  Canonical stored source path (see {@see RedirectSource::normalize()})
     * @param  string  $destination  Explicit destination URL (relative or absolute)
     * @param  string  $response  HTTP redirect status — '301' or '302'
     * @param  ValidityWindow  $window  The optional date validity window
     * @param  string|null  $requester  Free-text requester attribution
     * @param  string|null  $notes  Free-text notes (e.g. why the redirect exists)
     * @param  string|null  $submittedBy  The Control Panel user id that created the request (for the no-self-approve gate, UAMS-Web/uams-statamic-archive#1357)
     * @param  string|null  $host  The redirect domain the rule serves on, or null for the main site's `redirects` global (UAMS-Web/aether-redirects#83)
     * @param  bool  $generatedBackHalf  Whether the source is a generated back-half rather than a path the requester typed (UAMS-Web/aether-redirects#15)
     */
    public function __construct(
        public string $source,
        public string $destination,
        public string $response = '301',
        public ValidityWindow $window = new ValidityWindow,
        public ?string $requester = null,
        public ?string $notes = null,
        public ?string $submittedBy = null,
        public ?string $host = null,
        public bool $generatedBackHalf = false,
    ) {}

    /**
     * Build a redirect from validated controller input.
     *
     * The source is canonicalized, the destination trimmed, and the optional
     * date/metadata fields normalized (blank strings become null). The submitter
     * is not request data — the controller supplies the authenticated user id so
     * the approval slice can forbid self-approval (UAMS-Web/uams-statamic-archive#1357).
     *
     * @param  array{source: string, destination: string, response?: string, valid_from?: string|null, valid_until?: string|null, requester?: string|null, notes?: string|null, host?: string|null}  $input  Validated request data
     * @param  string|null  $submittedBy  The Control Panel user id creating the request
     * @return self The typed marketing redirect
     */
    public static function fromInput(array $input, ?string $submittedBy = null): self
    {
        return new self(
            source: RedirectSource::normalize($input['source']),
            destination: trim($input['destination']),
            response: $input['response'] ?? '301',
            window: ValidityWindow::fromDates(
                $input['valid_from'] ?? null,
                $input['valid_until'] ?? null,
            ),
            requester: self::cleanOptional($input['requester'] ?? null),
            notes: self::cleanOptional($input['notes'] ?? null),
            submittedBy: self::cleanOptional($submittedBy),
            host: RedirectHosts::normalize($input['host'] ?? null),
        );
    }

    /**
     * Return this redirect with a generated back-half as its source
     * (UAMS-Web/aether-redirects#15).
     *
     * The back-half keeps its drawn case; the source gains the leading slash
     * every stored source carries.
     *
     * @param  string  $backHalf  The generated back-half
     * @return self The same redirect, sourced from the back-half and marked generated
     */
    public function withGeneratedBackHalf(string $backHalf): self
    {
        return new self(
            source: RedirectSource::normalize($backHalf),
            destination: $this->destination,
            response: $this->response,
            window: $this->window,
            requester: $this->requester,
            notes: $this->notes,
            submittedBy: $this->submittedBy,
            host: $this->host,
            generatedBackHalf: true,
        );
    }

    /**
     * Render this redirect as a `redirect` replicator row for the `redirects`
     * global.
     *
     * The caller supplies the row id so persistence stays deterministic (and
     * testable); every other key mirrors what {@see FindRedirectRule}
     * reads, with the validity window flattened back to `Y-m-d` date strings.
     *
     * A utility-created redirect always enters the queue as {@see RedirectStatus::Pending}
     * (UAMS-Web/uams-statamic-archive#1357) — it is `enabled` but does not serve until an approver promotes it,
     * so both flags are stamped here and the review metadata starts empty.
     *
     * @param  string  $id  The replicator row id (assigned by the store)
     * @return array<string, mixed> The stored redirect row
     */
    public function toRow(string $id): array
    {
        return [
            'id' => $id,
            'type' => self::SET_HANDLE,
            'enabled' => true,
            'status' => RedirectStatus::Pending->value,
            'source' => $this->source,
            'use_regex' => false,
            'target_type' => 'url',
            'target_url' => $this->destination,
            'response' => $this->response,
            'order' => self::DEFAULT_ORDER,
            'valid_from' => $this->window->from?->toDateString(),
            'valid_until' => $this->window->until?->toDateString(),
            'requester' => $this->requester,
            'notes' => $this->notes,
            'submitted_by' => $this->submittedBy,
            'reviewed_by' => null,
            'rejection_reason' => null,
        ];
    }

    /**
     * Render this redirect as the data of a `redirect_domains` entry
     * (UAMS-Web/aether-redirects#83).
     *
     * The same fields as {@see toRow()} without the replicator `id` and `type`, plus
     * the `host` the entry answers on (the id of its domain record), so the engine and
     * the edge manifest apply the same status and validity gates to it as to a
     * global-set row, and the {@see BackHalf::FIELD} flag the approval re-check reads.
     *
     * @param  string  $host  The id of the domain record the rule serves on
     * @return array<string, mixed> The entry data
     */
    public function toDomainData(string $host): array
    {
        // The id is dropped on the next line, so its value never reaches the data
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $row = $this->toRow('');
        unset($row['id'], $row['type']);

        return ['host' => $host, 'whole_domain' => false, ...$row, BackHalf::FIELD => $this->generatedBackHalf];
    }

    /**
     * Trim an optional free-text value, collapsing blank input to null.
     *
     * @param  string|null  $value  The raw optional value
     * @return string|null The trimmed value, or null when empty
     */
    private static function cleanOptional(?string $value): ?string
    {
        // A null or whitespace-only value carries no metadata.
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
