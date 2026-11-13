<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as GlobalSetItem;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * Reads and appends redirects on the shared `redirects` global (UAMS-Web/uams-statamic-archive#1355).
 *
 * The utility writes to the **default** site's localization. The `redirects`
 * field is non-localizable — one shared list intended to apply everywhere — and
 * {@see FindRedirectRule} merges the default list beneath every site's
 * list at read time (UAMS-Web/uams-statamic-archive#1110), so a redirect created here serves on a 404 for
 * every site with no per-site duplication. Collision detection (UAMS-Web/uams-statamic-archive#1356) therefore
 * compares against this same shared default-site list.
 */
class MarketingRedirectStore implements RedirectRequestStore
{
    /**
     * The handle of the redirects global set.
     */
    public const string GLOBAL_HANDLE = 'redirects';

    /**
     * The replicator field on the global that holds the redirect rows.
     */
    public const string FIELD = 'redirects';

    /**
     * Return the shared redirect rows stored on the default site.
     *
     * @return list<array<string, mixed>> The stored redirect rows (empty when none/absent)
     */
    public function all(): array
    {
        // Resolve the default site's localization; treat an absent one as empty.
        $variables = $this->defaultVariables();

        if (! $variables instanceof Variables) {
            return [];
        }

        // Read the redirect rows, tolerating a missing or non-array value.
        $value = $variables->get(self::FIELD, []);

        if (! \is_array($value)) {
            return [];
        }

        // Replicator rows are always string-keyed maps once stored.
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($value);

        return $rows;
    }

    /**
     * Append a marketing redirect to the shared default-site list and persist it.
     *
     * @param  MarketingRedirect  $redirect  The redirect to store
     * @return array<string, mixed> The stored row, including its generated id
     *
     * @throws RuntimeException When the `redirects` global set does not exist
     */
    public function create(MarketingRedirect $redirect): array
    {
        // Resolve the default site's localization to write into.
        $variables = $this->defaultVariables();

        if (! $variables instanceof Variables) {
            throw new RuntimeException(
                sprintf('The "%s" global set does not exist.', self::GLOBAL_HANDLE)
            );
        }

        // Build the row with a fresh unique id, then append it to the current list.
        $row = $redirect->toRow((string) Str::uuid());

        $existing = $variables->get(self::FIELD, []);
        $rows = \is_array($existing) ? array_values($existing) : [];
        $rows[] = $row;

        // Persist the extended list back onto the default localization.
        $variables->set(self::FIELD, $rows);
        $variables->save();

        return $row;
    }

    /**
     * Append many pre-built redirect rows to the shared default-site list in one
     * write.
     *
     * The rows are persisted verbatim (each is expected to already be a valid
     * `redirect` replicator row — e.g. the host's `MigrationRedirect::toRow()`),
     * so this is the batch counterpart of {@see create()} for the generated
     * migration map (UAMS-Web/uams-statamic-archive#1641): one read-modify-write rather than one per row. Passing
     * an empty list is a no-op that avoids a needless save.
     *
     * @param  list<array<string, mixed>>  $rows  The redirect rows to append
     * @return int The number of rows appended
     *
     * @throws RuntimeException When the `redirects` global set does not exist
     */
    public function appendMany(array $rows): int
    {
        // An empty batch writes nothing — skip the save entirely.
        if ($rows === []) {
            return 0;
        }

        // Resolve the default site's localization to write into.
        $variables = $this->defaultVariables();

        if (! $variables instanceof Variables) {
            throw new RuntimeException(
                sprintf('The "%s" global set does not exist.', self::GLOBAL_HANDLE)
            );
        }

        // Append the batch to the current list, then persist once.
        $existing = $variables->get(self::FIELD, []);
        $current = \is_array($existing) ? array_values($existing) : [];

        $variables->set(self::FIELD, [...$current, ...$rows]);
        $variables->save();

        return \count($rows);
    }

    /**
     * Return the stored redirect row with the given id, or null when absent.
     *
     * @param  string  $id  The replicator row id
     * @return array<string, mixed>|null The stored row, or null when no row has that id
     */
    public function find(string $id): ?array
    {
        // Scan the shared list for a row whose id matches.
        foreach ($this->all() as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Return every redirect row still awaiting approval (UAMS-Web/uams-statamic-archive#1357).
     *
     * @return list<array<string, mixed>> The pending redirect rows (empty when none)
     */
    public function pending(): array
    {
        // Keep only rows whose effective approval status is pending.
        return array_values(array_filter(
            $this->all(),
            static fn (array $row): bool => RedirectStatus::fromRow($row) === RedirectStatus::Pending,
        ));
    }

    /**
     * Count the redirect rows awaiting approval, for the CP review badge (UAMS-Web/uams-statamic-archive#1357).
     *
     * @return int The number of pending redirect rows
     */
    public function pendingCount(): int
    {
        return \count($this->pending());
    }

    /**
     * Approve a pending redirect so it begins serving, recording the reviewer.
     *
     * @param  string  $id  The replicator row id to approve
     * @param  string  $reviewerId  The Control Panel user id approving the request
     * @return array<string, mixed>|null The updated row, or null when no row has that id
     *
     * @throws RuntimeException When the `redirects` global set does not exist
     */
    public function approve(string $id, string $reviewerId): ?array
    {
        // Flip the status to approved and clear any stale rejection reason.
        return $this->mutateRow($id, static fn (array $row): array => [
            ...$row,
            'status' => RedirectStatus::Approved->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => null,
        ]);
    }

    /**
     * Reject a pending redirect, retaining the reason for the record (UAMS-Web/uams-statamic-archive#1357).
     *
     * @param  string  $id  The replicator row id to reject
     * @param  string  $reviewerId  The Control Panel user id rejecting the request
     * @param  string  $reason  Why the request was rejected
     * @return array<string, mixed>|null The updated row, or null when no row has that id
     *
     * @throws RuntimeException When the `redirects` global set does not exist
     */
    public function reject(string $id, string $reviewerId, string $reason): ?array
    {
        // Flip the status to rejected and retain the reason.
        return $this->mutateRow($id, static fn (array $row): array => [
            ...$row,
            'status' => RedirectStatus::Rejected->value,
            'reviewed_by' => $reviewerId,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Apply a mutation to the stored row with the given id and persist the list.
     *
     * This read-modify-writes the whole shared list, so under genuinely concurrent
     * mutations of *different* rows the last save wins — a known flat-file
     * limitation shared with {@see create()} and Statamic globals generally. The
     * redirects surface is low-write and staff-only, so this is accepted for MVP;
     * harden with a lock over the global handle if contention ever appears.
     *
     * @param  string  $id  The replicator row id to mutate
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mutator  Maps the found row to its replacement
     * @return array<string, mixed>|null The updated row, or null when no row has that id
     *
     * @throws RuntimeException When the `redirects` global set does not exist
     */
    private function mutateRow(string $id, Closure $mutator): ?array
    {
        // Resolve the default site's localization the shared list lives on.
        $variables = $this->defaultVariables();

        if (! $variables instanceof Variables) {
            throw new RuntimeException(
                sprintf('The "%s" global set does not exist.', self::GLOBAL_HANDLE)
            );
        }

        // Read the current list, tolerating a missing or non-array value.
        $existing = $variables->get(self::FIELD, []);
        $rows = \is_array($existing) ? array_values($existing) : [];

        // Locate the target row, apply the mutation, and remember the result.
        $updated = null;

        foreach ($rows as $index => $row) {
            if (\is_array($row) && ($row['id'] ?? null) === $id) {
                /** @var array<string, mixed> $row */
                $rows[$index] = $mutator($row);
                $updated = $rows[$index];
                break;
            }
        }

        // A missing id is a no-op: nothing to persist, signal with null.
        if ($updated === null) {
            return null;
        }

        // Persist the mutated list back onto the default localization.
        $variables->set(self::FIELD, $rows);
        $variables->save();

        return $updated;
    }

    /**
     * Resolve the default site's localization on the redirects global.
     *
     * The default site is always among the global's configured sites, so `in()`
     * returns (auto-creating if needed) that site's localization — the shared
     * list the UAMS-Web/uams-statamic-archive#1110 read-time merge serves everywhere.
     *
     * @return Variables|null The localization, or null when the global set is absent
     */
    private function defaultVariables(): ?Variables
    {
        // Locate the redirects global set; bail when it is not configured.
        /** @var GlobalSetItem|null $global */
        $global = GlobalSet::findByHandle(self::GLOBAL_HANDLE);

        if ($global === null) {
            return null;
        }

        // Read (auto-creating) the default site's shared localization.
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $variables = $global->in($defaultSite->handle());

        // in() returns variables or null, so admitting anything returns the same value
        // @pest-mutate-ignore: InstanceOfToTrue
        return $variables instanceof Variables ? $variables : null;
    }
}
