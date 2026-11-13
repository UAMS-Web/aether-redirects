<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * Moves each redirect domain entry from a host string to a reference to a domain
 * record (UAMS-Web/aether-redirects#24).
 *
 * One record is created for each distinct valid host the entries hold, unless a
 * published record already names it, and each entry is rewritten to the record's id.
 * A rule serves the same redirect before and after, because the readers resolve a
 * host string through the same records. Running it again changes nothing: an entry
 * that already holds a record id is skipped. An entry whose host is not a valid
 * domain is reported and left as it is.
 */
#[Description('Move each redirect domain entry from a host string to a reference to a domain record (UAMS-Web/aether-redirects#24)')]
#[Signature('redirects:migrate-domain-hosts {--dry-run : Report what would change without creating records or saving entries}')]
class MigrateRedirectDomainHosts extends Command
{
    /**
     * Create the missing domain records and rewrite the entries that name them.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;

        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', RedirectDomainRules::COLLECTION);
        // A stable order, so a run reports and creates records the same way each time
        /** @var EntryQueryBuilder $query */
        $query = $query->orderBy('title');

        /** @var Collection<int, mixed> $entries */
        $entries = $query->get();

        $records = RedirectDomainList::records();
        $created = 0;
        $rewritten = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            // An entry query yields entries only, so this guard narrows the type for
            // static analysis and never skips anything
            // @pest-mutate-ignore:InstanceOfToTrue,ContinueToBreak
            if (! $entry instanceof StatamicEntry) {
                continue;
            }

            if (RedirectDomainList::isReference($entry->get('host'))) {
                continue;
            }

            $host = RedirectDomainRules::normalizeHost($entry->get('host'));

            if ($host === null) {
                $entryId = $entry->id();
                // A saved entry always has a string id, so the fallback is never used
                // @pest-mutate-ignore:EmptyStringToNotEmpty
                $this->warn(sprintf('Entry %s has a host that is not a valid domain, so it was left as it is.', is_string($entryId) ? $entryId : ''));
                $skipped++;

                continue;
            }

            $id = array_search($host, $records, true);

            if ($id === false) {
                $id = $dryRun ? $host : $this->createRecord($host);
                $records[$id] = $host;
                $created++;
            }

            if (! $dryRun) {
                $entry->set('host', $id);
                $entry->save();
            }

            $rewritten++;
        }

        $this->info(sprintf('%s%d domain record(s) created, %d entr%s rewritten, %d skipped.', $dryRun ? 'Dry run: ' : '', $created, $rewritten, $rewritten === 1 ? 'y' : 'ies', $skipped));

        return self::SUCCESS;
    }

    /**
     * Create a published domain record for a host.
     *
     * @param  string  $host  A normalized host.
     * @return string The new record's id.
     */
    private function createRecord(string $host): string
    {
        /** @var StatamicEntry $record */
        $record = Entry::make();
        $record->collection(RedirectDomainList::COLLECTION);
        $record->data(['host' => $host]);
        $record->save();

        $id = $record->id();

        // A saved entry always has a string id, so the fallback is never used
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        return is_string($id) ? $id : '';
    }
}
