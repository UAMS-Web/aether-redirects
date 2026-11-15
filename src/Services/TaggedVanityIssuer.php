<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

/**
 * Composes the destination of a vanity redirect that was requested with tagged URL choices
 * (UAMS-Web/aether-redirects#125).
 *
 * Approval calls it once for each redirect it approves, so a vanity redirect and its `-QR`
 * redirect each get their own URL. The result is stored as the redirect's destination and not
 * composed again, so a later change to a list or a parameter leaves an issued URL as it was.
 */
final readonly class TaggedVanityIssuer
{
    /**
     * @param  TaggedUrlLookupReader  $reader  Reads the host's lists and parameters
     */
    public function __construct(private TaggedUrlLookupReader $reader) {}

    /**
     * Whether a stored row was requested with tagged URL choices.
     *
     * @param  array<string, mixed>  $row  A stored vanity row
     */
    public static function carriesChoices(array $row): bool
    {
        return \is_array($row[MarketingRedirect::TAGGED_CHOICES_FIELD] ?? null);
    }

    /**
     * The destination to issue for a stored row.
     *
     * The tagged URL is composed from the destination as typed and the choices, placed on the row's
     * own vanity domain and back-half. A row in a QR pair also carries its tracking value, as the pair
     * does before approval.
     *
     * @param  array<string, mixed>  $row  A stored vanity row
     * @return string|null The destination, or null when the row carries no choices
     */
    public function destinationFor(array $row): ?string
    {
        if (! self::carriesChoices($row)) {
            return null;
        }

        $role = $row[QrRedirect::FIELD] ?? null;
        $request = TaggedUrlRequest::fromStored($row[MarketingRedirect::TAGGED_CHOICES_FIELD])
            ->placedOn(self::vanityOf($row), $role === QrRedirect::COMPANION);

        $url = $this->reader->composer()->compose($request);

        return match ($role) {
            QrRedirect::COMPANION => QrRedirect::withSpec($url, QrRedirect::QR_SPEC),
            QrRedirect::VANITY => QrRedirect::withSpec($url, QrRedirect::TEXT_SPEC),
            default => $url,
        };
    }

    /**
     * The vanity domain and back-half of a stored row, such as `uams.info/flu`.
     *
     * @param  array<string, mixed>  $row  A stored vanity row
     * @return string|null The domain and back-half, or null for a row on the main site, which has no vanity domain
     */
    private static function vanityOf(array $row): ?string
    {
        $host = $row['host'] ?? null;
        $source = $row['source'] ?? null;

        if (! \is_string($host) || $host === '' || ! \is_string($source) || trim($source, '/') === '') {
            return null;
        }

        return $host.'/'.ltrim($source, '/');
    }
}
