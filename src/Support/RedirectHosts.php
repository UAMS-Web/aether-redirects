<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * The vanity redirect domains and which destinations each one serves
 * (UAMS-Web/aether-redirects#83).
 *
 * Marketing vanity URLs live on three domains, chosen by where the link points:
 * `uams.info` for a `uams.edu` page, `uamsbaptist.health` for a `uamshealth.com`
 * page about the UAMS Health and Baptist Health partnership, and `uams.health` for
 * any other `uamshealth.com` page. The partnership is not visible in a URL, so a
 * creator may choose `uamsbaptist.health` for any `uamshealth.com` destination.
 * Any other pairing of a vanity domain with a destination needs an approver.
 */
final class RedirectHosts
{
    /**
     * The domain for a `uams.edu` destination.
     */
    public const string INFO = 'uams.info';

    /**
     * The domain for any `uamshealth.com` destination.
     */
    public const string HEALTH = 'uams.health';

    /**
     * The domain for a `uamshealth.com` destination about the partnership.
     */
    public const string BAPTIST = 'uamsbaptist.health';

    /**
     * The domains where a path is not reused for 12 weeks after its last use.
     *
     * @var list<string>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed list is observable, and `RedirectHostsTest` pins each domain.
    // @pest-mutate-ignore:RemoveArrayItem
    public const array VANITY = [self::INFO, self::HEALTH, self::BAPTIST];

    /**
     * Weeks a path stays reserved after the end of its last rule on a vanity domain.
     */
    // As above: a changed value is observable, and the 12-week boundary cases in `RedirectCollisionDetectorTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int REUSE_BUFFER_WEEKS = 12;

    /**
     * Whether a domain is one of the vanity domains.
     *
     * @param  string|null  $host  A normalized host.
     */
    public static function isVanity(?string $host): bool
    {
        // Both sides are strings, which compare the same loosely or strictly
        // @pest-mutate-ignore: TrueToFalse
        return $host !== null && \in_array($host, self::VANITY, true);
    }

    /**
     * The vanity domain a destination calls for, or null when it calls for none.
     *
     * @param  string  $destination  The destination URL.
     */
    public static function proposeFor(string $destination): ?string
    {
        $destinationHost = self::destinationHost($destination);

        if ($destinationHost === null) {
            return null;
        }

        if (self::isWithin($destinationHost, 'uams.edu')) {
            return self::INFO;
        }

        return self::isWithin($destinationHost, 'uamshealth.com') ? self::HEALTH : null;
    }

    /**
     * Whether a creator may pair a domain with a destination without an approver.
     *
     * A domain that is not a vanity domain has no pairing rule here. A vanity
     * domain accepts the destination it is proposed for, and `uamsbaptist.health`
     * also accepts any `uamshealth.com` destination.
     *
     * @param  string  $host  A normalized host.
     * @param  string  $destination  The destination URL.
     */
    public static function accepts(string $host, string $destination): bool
    {
        if (! self::isVanity($host)) {
            return true;
        }

        $proposed = self::proposeFor($destination);

        if ($host === $proposed) {
            return true;
        }

        return $host === self::BAPTIST && $proposed === self::HEALTH;
    }

    /**
     * A host as stored, or null when it is empty or not a valid domain.
     *
     * @param  mixed  $value  The submitted or stored host.
     */
    public static function normalize(mixed $value): ?string
    {
        return RedirectDomainRules::normalizeHost($value);
    }

    /**
     * The host a destination URL names, lowercased, or null for a relative or malformed URL.
     *
     * @param  string  $destination  The destination URL.
     */
    private static function destinationHost(string $destination): ?string
    {
        $host = parse_url(trim($destination), PHP_URL_HOST);

        return \is_string($host) ? strtolower(rtrim($host, '.')) : null;
    }

    /**
     * Whether a host is a domain or a subdomain of it.
     *
     * @param  string  $host  A lowercased host.
     * @param  string  $domain  The parent domain.
     */
    private static function isWithin(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
