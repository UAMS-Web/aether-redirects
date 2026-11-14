<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;

/**
 * Finds the `-QR` redirect that was made with a vanity redirect (UAMS-Web/aether-redirects#14).
 *
 * A redirect found only by its source could belong to someone else's request, so it is the pair
 * only when it says so itself and is on the same domain.
 */
final class QrPairFinder
{
    /**
     * The QR redirect made with a vanity redirect, or null when it has none.
     *
     * @param  StatamicEntry  $vanity  A redirect domain entry
     */
    public function companionOf(StatamicEntry $vanity): ?StatamicEntry
    {
        $vanitySource = $vanity->get('source');

        if ($vanity->get(QrRedirect::FIELD) !== QrRedirect::VANITY || ! is_string($vanitySource)) {
            return null;
        }

        $source = strtolower(QrRedirect::source($vanitySource));

        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', RedirectDomainRules::COLLECTION);
        /** @var EntryQueryBuilder $query */
        $query = $query->where(QrRedirect::FIELD, QrRedirect::COMPANION);

        /** @var iterable<StatamicEntry> $entries */
        $entries = $query->get();

        foreach ($entries as $entry) {
            $entrySource = $entry->get('source');

            if ($entry->get('host') === $vanity->get('host') && is_string($entrySource) && strtolower($entrySource) === $source) {
                return $entry;
            }
        }

        return null;
    }
}
