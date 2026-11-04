<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * An immutable, optional date validity window for a redirect.
 *
 * A window has an inclusive start (`from`) and inclusive end (`until`); either
 * bound may be null, meaning "open" on that side. An entirely open window
 * (both null) is always valid. Bounds are normalized to day granularity —
 * `from` to the start of its day, `until` to the end of its day — so a redirect
 * that is valid "until 2026-01-10" serves for the whole of the 10th.
 *
 * This is the single source of truth for the three places a redirect's window
 * matters: capturing it on creation (UAMS-Web/uams-statamic-archive#1355), detecting overlapping windows on
 * the same source ({@see overlaps()}, UAMS-Web/uams-statamic-archive#1356), and gating whether the serving tag
 * honors it today ({@see contains()}, {@see FindRedirectRule}).
 */
final readonly class ValidityWindow
{
    /**
     * @param  CarbonImmutable|null  $from  Inclusive start of the window (start of day), or null for open-start
     * @param  CarbonImmutable|null  $until  Inclusive end of the window (end of day), or null for open-end
     */
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
    ) {}

    /**
     * Build a window from two optional date strings (e.g. `'2026-01-10'`).
     *
     * Blank or null values become open bounds. `from` snaps to the start of its
     * day and `until` to the end of its day so an inclusive end date serves for
     * the entire day.
     *
     * @param  string|null  $from  The start date string, or null/blank for open-start
     * @param  string|null  $until  The end date string, or null/blank for open-end
     * @return self The parsed validity window
     */
    public static function fromDates(?string $from, ?string $until): self
    {
        // Parse each optional bound, snapping to the correct day boundary.
        return new self(
            self::parseBound($from, endOfDay: false),
            self::parseBound($until, endOfDay: true),
        );
    }

    /**
     * Build a window from a stored redirect row's `valid_from` / `valid_until`
     * keys, tolerating absent or non-string values (legacy rows carry neither).
     *
     * @param  array<string, mixed>  $row  A stored redirect replicator row
     * @return self The row's validity window (open on both sides for legacy rows)
     */
    public static function fromRow(array $row): self
    {
        // Read each bound only when it is a usable string; anything else is open.
        return self::fromDates(
            self::stringOrNull($row['valid_from'] ?? null),
            self::stringOrNull($row['valid_until'] ?? null),
        );
    }

    /**
     * Whether this window overlaps another on any day.
     *
     * Two windows are disjoint only when one ends strictly before the other
     * begins; an open bound can never be the earlier side, so a fully-open
     * window overlaps everything (a no-window redirect collides with any other
     * on the same source, per UAMS-Web/uams-statamic-archive#1356). Touching endpoints count as an overlap.
     *
     * @param  self  $other  The window to test against
     * @return bool True when the two windows share at least one day
     */
    public function overlaps(self $other): bool
    {
        // Disjoint if this window ends before the other one starts.
        if ($this->until instanceof CarbonImmutable && $other->from instanceof CarbonImmutable && $this->until->lt($other->from)) {
            return false;
        }

        // Disjoint if the other window ends before this one starts.
        // Neither window ends before the other begins, so they overlap.
        return ! ($other->until instanceof CarbonImmutable && $this->from instanceof CarbonImmutable && $other->until->lt($this->from));
    }

    /**
     * Whether the given moment falls within this window (inclusive).
     *
     * @param  CarbonInterface  $moment  The instant to test (typically now)
     * @return bool True when the moment is on or after `from` and on or before `until`
     */
    public function contains(CarbonInterface $moment): bool
    {
        // Before an open-ended start bound: not yet valid.
        if ($this->from instanceof CarbonImmutable && $moment->lt($this->from)) {
            return false;
        }

        // After an open-ended end bound: no longer valid.
        // Within both bounds (or unbounded): valid.
        return ! $this->until instanceof CarbonImmutable || ! $moment->gt($this->until);
    }

    /**
     * Parse an optional date-string bound, snapping to the requested day edge.
     *
     * @param  string|null  $value  The date string, or null/blank for an open bound
     * @param  bool  $endOfDay  Snap to end-of-day (true) or start-of-day (false)
     * @return CarbonImmutable|null The parsed bound, or null when open
     */
    private static function parseBound(?string $value, bool $endOfDay): ?CarbonImmutable
    {
        // Normalize once: a missing or blank bound is open.
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        // Parse the date and snap it to the appropriate day boundary.
        $date = CarbonImmutable::parse($value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    /**
     * Coerce a mixed value to a trimmed string, or null when it is not a string.
     *
     * @param  mixed  $value  A raw stored value
     * @return string|null The string value, or null when absent/non-string
     */
    private static function stringOrNull(mixed $value): ?string
    {
        // Only strings carry a usable date; everything else is treated as open.
        return \is_string($value) ? $value : null;
    }
}
