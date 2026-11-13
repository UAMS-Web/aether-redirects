<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

/**
 * A place marketing redirect requests are kept and reviewed
 * (UAMS-Web/aether-redirects#83).
 *
 * Two stores implement it: the `redirects` global set, which serves the main
 * site's 404 path, and the `redirect_domains` collection, which serves the
 * vanity domains. Both keep a request as a row in the same shape, so the
 * Control Panel utility reviews them the same way.
 */
interface RedirectRequestStore
{
    /**
     * Every stored row, whatever its status.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array;

    /**
     * The stored row with the given id, or null when absent.
     *
     * @param  string  $id  The row id.
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array;

    /**
     * Every row still awaiting approval.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array;

    /**
     * Approve a pending row, recording the reviewer.
     *
     * @param  string  $id  The row id.
     * @param  string  $reviewerId  The Control Panel user id approving it.
     * @return array<string, mixed>|null The updated row, or null when no row has that id.
     */
    public function approve(string $id, string $reviewerId): ?array;

    /**
     * Reject a pending row, retaining the reason.
     *
     * @param  string  $id  The row id.
     * @param  string  $reviewerId  The Control Panel user id rejecting it.
     * @param  string  $reason  Why it was rejected.
     * @return array<string, mixed>|null The updated row, or null when no row has that id.
     */
    public function reject(string $id, string $reviewerId, string $reason): ?array;
}
