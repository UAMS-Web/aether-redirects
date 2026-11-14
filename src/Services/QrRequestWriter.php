<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectHosts;

/**
 * Saves a vanity request together with its `-QR` redirect (UAMS-Web/aether-redirects#14).
 *
 * The code is made from a second redirect on the same domain, so the request is refused, with
 * nothing saved, unless that source is free as well as the vanity one.
 */
final readonly class QrRequestWriter
{
    public function __construct(
        private RedirectDomainStore $store,
        private RedirectCollisionDetector $detector,
    ) {}

    /**
     * Save a vanity request and the QR redirect that goes with it.
     *
     * @param  MarketingRedirect  $vanity  The validated request, whose source is settled and which holds no collision
     * @param  list<array<string, mixed>>  $candidates  The rows on the domain store that may collide: every one that is not rejected
     * @return array{vanity: array<string, mixed>, qr: array<string, mixed>}|string The saved rows, or the reason the code cannot be made
     */
    public function save(MarketingRedirect $vanity, array $candidates): array|string
    {
        if (! RedirectHosts::isVanity($vanity->host)) {
            return __('A QR code is made for a vanity URL, so choose a vanity domain.');
        }

        $companion = QrRedirect::companion($vanity);

        $conflict = $this->detector->firstConflict($companion->source, $companion->window, $candidates, $companion->host);

        if ($conflict !== null) {
            return $this->conflictMessage($conflict);
        }

        return [
            'vanity' => $this->store->create(QrRedirect::vanity($vanity)),
            'qr' => $this->store->create($companion),
        ];
    }

    /**
     * Name the redirect that holds the QR source.
     *
     * @param  array<string, mixed>  $conflict  The colliding stored row
     */
    private function conflictMessage(array $conflict): string
    {
        // The detector reports only a row whose source is a string, and the host is a string on a stored row.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $source = RedirectHosts::normalize(is_string($conflict['host'] ?? null) ? $conflict['host'] : null).(is_string($conflict['source'] ?? null) ? $conflict['source'] : '');
        $target = is_string($conflict['target_url'] ?? null) ? $conflict['target_url'] : '';

        if ($target !== '') {
            return __('A redirect from :source to :target already exists within an overlapping validity window.', ['source' => $source, 'target' => $target]);
        }

        return __('A redirect from :source already exists within an overlapping validity window.', ['source' => $source]);
    }
}
