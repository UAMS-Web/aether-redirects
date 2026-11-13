<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Listeners;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\User;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * Keeps the domain list the only way a domain becomes trusted
 * (UAMS-Web/aether-redirects#24).
 *
 * Saving a domain record normalizes its host, refuses a host that is not a valid
 * domain or that another record already names, and needs the permission to manage
 * redirect domains from a signed-in user. Saving a redirect domain entry needs its
 * host to be a published domain record, so a rule cannot name an address that
 * nobody added on purpose. A save with no signed-in user (the migration, an
 * import) is not blocked on permission.
 */
final class GuardRedirectHosts
{
    /**
     * Check an entry about to be saved.
     *
     * @param  EntrySaving  $event  The event.
     *
     * @throws ValidationException When the host is not acceptable.
     * @throws AuthorizationException When the user may not manage redirect domains.
     */
    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry instanceof StatamicEntry) {
            return;
        }

        match ($entry->collectionHandle()) {
            RedirectDomainList::COLLECTION => $this->guardRecord($entry),
            RedirectDomainRules::COLLECTION => $this->guardRule($entry),
            default => null,
        };
    }

    /**
     * Normalize a domain record's host and check who is saving it.
     *
     * @param  StatamicEntry  $record  The domain record.
     */
    private function guardRecord(StatamicEntry $record): void
    {
        $user = User::current();

        // A super user holds every permission without listing it
        if ($user !== null && ! $user->isSuper() && ! $user->hasPermission(RedirectDomainList::PERMISSION)) {
            throw new AuthorizationException(__('You may not manage redirect domains.'));
        }

        $host = RedirectDomainRules::normalizeHost($record->get('host'));

        if ($host === null) {
            throw ValidationException::withMessages(['host' => __('Enter a valid domain, such as example.org.')]);
        }

        // One record per address, whichever record is being saved
        $existing = array_search($host, RedirectDomainList::records(), true);

        if ($existing !== false && $existing !== $record->id()) {
            throw ValidationException::withMessages(['host' => __(':host is already in the domain list.', ['host' => $host])]);
        }

        $record->set('host', $host);
    }

    /**
     * Require a redirect domain entry's host to be a published domain record.
     *
     * @param  StatamicEntry  $rule  The redirect domain entry.
     */
    private function guardRule(StatamicEntry $rule): void
    {
        if (! RedirectDomainList::isReference($rule->get('host'))) {
            throw ValidationException::withMessages(['host' => __('Choose a domain from the domain list.')]);
        }
    }
}
