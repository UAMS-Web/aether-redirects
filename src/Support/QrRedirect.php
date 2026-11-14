<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * The `-QR` redirect that sits beside a vanity URL (UAMS-Web/aether-redirects#14).
 *
 * UAMS guidance makes a QR code from a redirect rather than the tagged URL, and counts scans apart
 * from typed visits: the QR redirect carries `qr` in `utm_specs` and the vanity redirect, the text URL
 * printed beside the code, carries `text-url`. A request for a code therefore stores two redirects on
 * one domain, to one destination, and each row records its role so approval and a later change of
 * destination can find the other.
 */
final class QrRedirect
{
    /**
     * The suffix a QR redirect's source adds to the vanity back-half.
     */
    public const string SUFFIX = '-QR';

    /**
     * The destination query parameter that tells scans from typed visits.
     */
    public const string PARAMETER = 'utm_specs';

    /**
     * The tracking value of the vanity redirect, the URL printed as text beside a code.
     */
    public const string TEXT_SPEC = 'text-url';

    /**
     * The tracking value of the QR redirect, the URL a code encodes.
     */
    public const string QR_SPEC = 'qr';

    /**
     * The entry field holding a row's role.
     */
    public const string FIELD = 'qr_role';

    /**
     * The role of the vanity redirect a code was requested for.
     */
    public const string VANITY = 'vanity';

    /**
     * The role of the `-QR` redirect made with it.
     */
    public const string COMPANION = 'companion';

    /**
     * The source of the QR redirect for a vanity source.
     *
     * @param  string  $vanitySource  The vanity redirect's stored source, such as `/v6qzl2N`
     * @return string The source with the suffix added, such as `/v6qzl2N-QR`
     */
    public static function source(string $vanitySource): string
    {
        return $vanitySource.self::SUFFIX;
    }

    /**
     * The vanity source a QR redirect's source belongs to, or null when it is not one.
     *
     * The suffix matches in any case, as a source match does, and a source that is only the suffix
     * belongs to nothing.
     *
     * @param  string  $source  A stored source
     * @return string|null The source without the suffix, or null
     */
    public static function vanitySource(string $source): ?string
    {
        if (! str_ends_with(strtoupper($source), self::SUFFIX)) {
            return null;
        }

        $vanity = substr($source, 0, -strlen(self::SUFFIX));

        return in_array($vanity, ['', '/'], true) ? null : $vanity;
    }

    /**
     * Set the tracking value in a destination, replacing any the destination already has.
     *
     * The rest of the query keeps its order and its encoding, and the fragment stays last.
     *
     * @param  string  $destination  The destination URL
     * @param  string  $spec  The tracking value
     * @return string The destination carrying that value
     */
    public static function withSpec(string $destination, string $spec): string
    {
        $fragment = '';
        $hash = strpos($destination, '#');

        if ($hash !== false) {
            $fragment = substr($destination, $hash);
            $destination = substr($destination, 0, $hash);
        }

        $query = '';
        $mark = strpos($destination, '?');

        if ($mark !== false) {
            $query = substr($destination, $mark + 1);
            $destination = substr($destination, 0, $mark);
        }

        $pairs = array_filter(
            explode('&', $query),
            static fn (string $pair): bool => $pair !== '' && ! str_starts_with($pair.'=', self::PARAMETER.'='),
        );
        $pairs[] = self::PARAMETER.'='.$spec;

        return $destination.'?'.implode('&', $pairs).$fragment;
    }

    /**
     * The vanity redirect of a request that asks for a code: tagged as the text URL and marked.
     *
     * @param  MarketingRedirect  $vanity  The request as submitted
     * @return MarketingRedirect The same request, tagged and marked as the vanity side
     */
    public static function vanity(MarketingRedirect $vanity): MarketingRedirect
    {
        return self::copy($vanity, $vanity->source, self::withSpec($vanity->destination, self::TEXT_SPEC), self::VANITY);
    }

    /**
     * The QR redirect to make beside a vanity redirect.
     *
     * @param  MarketingRedirect  $vanity  The vanity side, whose destination has not yet been tagged
     * @return MarketingRedirect The same request on the `-QR` source, tagged for scans
     */
    public static function companion(MarketingRedirect $vanity): MarketingRedirect
    {
        return self::copy($vanity, self::source($vanity->source), self::withSpec($vanity->destination, self::QR_SPEC), self::COMPANION);
    }

    /**
     * Copy a request with a new source, destination and role.
     */
    private static function copy(MarketingRedirect $from, string $source, string $destination, string $role): MarketingRedirect
    {
        return new MarketingRedirect(
            source: $source,
            destination: $destination,
            response: $from->response,
            window: $from->window,
            requester: $from->requester,
            notes: $from->notes,
            submittedBy: $from->submittedBy,
            host: $from->host,
            generatedBackHalf: $from->generatedBackHalf,
            approvedOnCreate: $from->approvedOnCreate,
            creativeServices: $from->creativeServices,
            jobNumber: $from->jobNumber,
            qrRole: $role,
        );
    }
}
