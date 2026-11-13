<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use UAMSWeb\Aether\Redirects\Support\RedirectHosts;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;
use UAMSWeb\Aether\Redirects\Support\ValidityWindow;

/**
 * Detects redirect source collisions within overlapping validity windows (UAMS-Web/uams-statamic-archive#1356).
 *
 * A vanity URL can only point one place at a time, so two redirects that claim
 * the same (normalized) source with validity windows that intersect on any day
 * are a conflict the utility must surface *before* saving — otherwise an editor
 * unknowingly overrides a live redirect, or two "valid" redirects race on the
 * same 404. Two redirects on the same source with non-overlapping windows are
 * allowed (a source can legitimately point different places in different date
 * ranges), and a redirect with no window collides with any other on the source.
 *
 * The detector is a pure function of its inputs — no facades, no I/O — so it is
 * exhaustively unit-testable and reusable by the CP store and any later slice.
 */
class RedirectCollisionDetector
{
    /**
     * Return the first existing redirect row that conflicts with a candidate,
     * or null when the candidate can be stored without collision.
     *
     * A row conflicts when it is on the candidate's host, shares the candidate's
     * case-insensitive source collision key *and* its validity window overlaps the
     * candidate's. A row with no host belongs to the main site's `redirects`
     * global, so the same path on two hosts, or on a host and the main site, does
     * not conflict (UAMS-Web/aether-redirects#83). On a vanity host the row's
     * window is first extended by the reuse buffer, so a path is not reused for a
     * different destination until {@see RedirectHosts::REUSE_BUFFER_WEEKS} weeks
     * after its last day of use.
     *
     * @param  string  $candidateSource  The candidate redirect's source path (raw or normalized)
     * @param  ValidityWindow  $candidateWindow  The candidate redirect's validity window
     * @param  iterable<int, mixed>  $existingRows  The already-stored redirect rows (raw global data, defensively untyped)
     * @param  string|null  $candidateHost  The candidate's normalized host, or null for the main site
     * @return array<string, mixed>|null The first conflicting row, or null when there is none
     */
    public function firstConflict(
        string $candidateSource,
        ValidityWindow $candidateWindow,
        iterable $existingRows,
        ?string $candidateHost = null
    ): ?array {
        // Compute the candidate's collision key once for the comparison loop.
        $candidateKey = RedirectSource::collisionKey($candidateSource);

        // Walk every stored row, returning the first genuine conflict.
        foreach ($existingRows as $row) {
            // Skip anything that is not a usable redirect row.
            if (! is_array($row)) {
                continue;
            }

            // A stored replicator row is a string-keyed map once confirmed an array.
            /** @var array<string, mixed> $row */

            // A row without a string source cannot share the candidate's path.
            $rowSource = $row['source'] ?? null;
            if (! is_string($rowSource)) {
                continue;
            }

            // Different source path: no collision regardless of the windows.
            if (RedirectSource::collisionKey($rowSource) !== $candidateKey) {
                continue;
            }

            // Another host's rule, or the main site's, does not share this path.
            if (RedirectHosts::normalize($row['host'] ?? null) !== $candidateHost) {
                continue;
            }

            // Same host and source: a conflict only when the validity windows
            // overlap, counting the reuse buffer on a vanity host.
            $rowWindow = ValidityWindow::fromRow($row);

            if (RedirectHosts::isVanity($candidateHost)) {
                $rowWindow = $rowWindow->withReuseBuffer(RedirectHosts::REUSE_BUFFER_WEEKS);
            }

            if ($candidateWindow->overlaps($rowWindow)) {
                return $row;
            }
        }

        // No stored row shared the source within an overlapping window.
        return null;
    }
}
