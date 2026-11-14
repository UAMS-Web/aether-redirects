<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Listeners;

use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaving;
use UAMSWeb\Aether\Redirects\Services\QrPairFinder;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;

/**
 * Keeps a vanity redirect and its `-QR` redirect pointing at one destination (UAMS-Web/aether-redirects#14).
 *
 * Saving the vanity redirect with a new destination repoints the QR redirect to it, so a printed
 * code never leads somewhere the text URL beside it does not. Each keeps its own tracking value.
 */
final readonly class SyncQrRedirect
{
    public function __construct(private QrPairFinder $finder) {}

    /**
     * Carry the vanity redirect's destination to its QR redirect.
     *
     * @param  EntrySaving  $event  The event.
     */
    public function handle(EntrySaving $event): void
    {
        $vanity = $event->entry;

        if (! $vanity instanceof StatamicEntry) {
            return;
        }

        $companion = $this->finder->companionOf($vanity);
        $destination = $vanity->get('target_url');

        if (! $companion instanceof StatamicEntry || ! is_string($destination) || $destination === '') {
            return;
        }

        // The vanity redirect always carries the text URL value, whatever an editor typed
        $vanity->set('target_url', QrRedirect::withSpec($destination, QrRedirect::TEXT_SPEC));

        $qrDestination = QrRedirect::withSpec($destination, QrRedirect::QR_SPEC);

        if ($companion->get('target_url') !== $qrDestination) {
            $companion->set('target_url', $qrDestination);
            $companion->save();
        }
    }
}
