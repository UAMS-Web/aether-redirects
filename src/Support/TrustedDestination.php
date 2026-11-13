<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * Whether a short link's destination is on a host the application already serves
 * (UAMS-Web/aether-redirects#84).
 *
 * A generated code hides its destination by design and lends it the UAMS domain, so
 * a code pointing anywhere but a trusted host waits for an approver. The trusted
 * hosts are the application's own host and its subdomains, the sites' domains, and
 * the published redirect domains. Only an absolute `http` or `https` URL can be
 * trusted; anything else (a relative path, another scheme, no host) is not.
 *
 * Pure: the hosts are read into it elsewhere.
 */
final readonly class TrustedDestination
{
    /**
     * The trusted hosts matched exactly, normalized.
     *
     * @var list<string>
     */
    private array $exactHosts;

    /**
     * The application's host, normalized, or null when it has none.
     */
    private ?string $appHost;

    /**
     * @param  string|null  $appUrl  The application's URL; its host and every subdomain of it are trusted
     * @param  iterable<string>  $exactHosts  Hosts trusted exactly: the sites' domains and the published redirect domains
     */
    public function __construct(?string $appUrl, iterable $exactHosts)
    {
        $this->appHost = $appUrl === null ? null : self::hostOf($appUrl);

        // An empty entry can never match: hostOf() never returns an empty host.
        $hosts = [];

        foreach ($exactHosts as $host) {
            $hosts[] = self::normalize($host);
        }

        $this->exactHosts = $hosts;
    }

    /**
     * Whether a destination is on a trusted host.
     *
     * @param  string  $destination  The short link's destination URL
     * @return bool True when its host is the application's or a subdomain of it, or is trusted exactly
     */
    public function allows(string $destination): bool
    {
        $host = self::hostOf($destination);

        if ($host === null) {
            return false;
        }

        if ($this->appHost !== null && ($host === $this->appHost || str_ends_with($host, '.'.$this->appHost))) {
            return true;
        }

        return \in_array($host, $this->exactHosts, true);
    }

    /**
     * The normalized host of an absolute `http` or `https` URL.
     *
     * @param  string  $url  The URL
     * @return string|null The lowercased host without a trailing dot, or null when the URL has none
     */
    public static function hostOf(string $url): ?string
    {
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);
        $host = parse_url(trim($url), PHP_URL_HOST);

        // parse_url() answers null or false for a part it cannot find.
        if (! \is_string($scheme) || ! \is_string($host)) {
            return null;
        }

        $scheme = strtolower($scheme);
        $host = self::normalize($host);

        return ($scheme === 'http' || $scheme === 'https') && $host !== '' ? $host : null;
    }

    /**
     * Lowercase a host and drop a trailing dot.
     *
     * @param  string  $host  The host
     * @return string The normalized host
     */
    private static function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }
}
