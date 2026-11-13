<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;
use UAMSWeb\Aether\Redirects\Support\TrustedDestination;

/**
 * Reads the hosts a short link may point at without approval
 * (UAMS-Web/aether-redirects#84): the application's URL, each site's absolute URL,
 * and the published records of the domain list ({@see RedirectDomainList}).
 */
class TrustedDestinationReader
{
    /**
     * Read the trusted hosts.
     *
     * @return TrustedDestination The check over them
     */
    public function trusted(): TrustedDestination
    {
        $appUrl = config('app.url');

        // Each site's host, from its absolute URL.
        $hosts = [];

        /** @var iterable<StatamicSite> $sites */
        $sites = Site::all();

        foreach ($sites as $site) {
            $url = $site->absoluteUrl();
            $host = \is_string($url) ? TrustedDestination::hostOf($url) : null;

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return new TrustedDestination(
            \is_string($appUrl) ? $appUrl : null,
            [...$hosts, ...RedirectDomainList::records()],
        );
    }
}
