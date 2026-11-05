<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * The approval status of a marketing/vanity redirect row (UAMS-Web/uams-statamic-archive#1357).
 *
 * A redirect created through the CP utility enters the queue as {@see Pending}
 * and only serves once an approver promotes it to {@see Approved}; a
 * {@see Rejected} request is retained (with its reason) but never serves. This
 * gates serving *beside* the existing per-row `enabled` flag — both must pass in
 * {@see FindRedirectRule}.
 */
enum RedirectStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /**
     * Resolve the approval status of a stored redirect row.
     *
     * A row created through the utility carries an explicit `status`. Legacy
     * globals-editor rows and WordPress-importer-created rows predate the field
     * and carry no `status` key — they are grandfathered as {@see Approved} so
     * they keep serving exactly as before (the field is additive, never a silent
     * regression). An unrecognized value is likewise treated as approved rather
     * than silently pulling a live redirect out of rotation.
     *
     * @param  array<string, mixed>  $row  A stored redirect replicator row
     * @return self The row's approval status (approved for legacy/importer rows)
     */
    public static function fromRow(array $row): self
    {
        // A missing or non-string status is a pre-#1357 row: grandfather it.
        $value = $row['status'] ?? null;

        if (! \is_string($value)) {
            return self::Approved;
        }

        // An unknown string is a data error — fail open so a live redirect stays live.
        return self::tryFrom($value) ?? self::Approved;
    }

    /**
     * Whether a redirect in this status is allowed to serve traffic.
     *
     * @return bool True only for {@see Approved}; pending and rejected are withheld
     */
    public function servesTraffic(): bool
    {
        // Only an approved (or grandfathered) redirect serves.
        return $this === self::Approved;
    }
}
