<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UAMSWeb\Aether\Redirects\Qr\QrFileStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * The download screen for the files of a vanity URL's QR code, and the endpoints behind it
 * (UAMS-Web/aether-redirects#14).
 *
 * Whoever may request a vanity URL, and approvers, may open it at any later time.
 */
final class QrCodeController extends CpController
{
    /**
     * The QR code guidance both notices link to.
     */
    public const string GUIDANCE_URL = 'https://communications.uams.edu/web/kb/qr-codes/';

    /**
     * Render the download screen of a vanity redirect's QR code.
     */
    public function show(string $redirect, QrFileStore $files): InertiaResponse
    {
        $entry = $this->vanityEntry($redirect);

        return Inertia::render('aether-redirects::QrCodeDownload', [
            'host' => QrFileStore::hostOf($entry),
            'source' => $entry->get('source'),
            'generateUrl' => cp_route('marketing-redirects.qr.generate', ['redirect' => $redirect]),
            'guidanceUrl' => self::GUIDANCE_URL,
            'files' => $this->fileLinks($redirect, $files, $entry),
        ]);
    }

    /**
     * Make the files, or make them again in place.
     */
    public function generate(string $redirect, QrFileStore $files): JsonResponse
    {
        $entry = $this->vanityEntry($redirect);
        $made = $files->generate($entry);

        if (is_string($made)) {
            return response()->json(['message' => $made, 'errors' => ['qr' => [$made]]], 422);
        }

        return response()->json(['files' => $this->fileLinks($redirect, $files, $entry)]);
    }

    /**
     * Download one of the files.
     */
    public function download(string $redirect, string $format, QrFileStore $files): StreamedResponse
    {
        $asset = $files->files($this->vanityEntry($redirect))[$format] ?? null;

        abort_if($asset === null, 404);

        return $asset->download();
    }

    /**
     * The vanity redirect with an id, which the user may see the code of.
     */
    private function vanityEntry(string $id): StatamicEntry
    {
        abort_unless(Gate::any([
            MarketingRedirectController::VANITY_PERMISSION,
            MarketingRedirectController::CUSTOM_VANITY_PERMISSION,
            MarketingRedirectController::PERMISSION,
            MarketingRedirectController::APPROVE_PERMISSION,
        ]), 403);

        $entry = Entry::find($id);

        abort_unless($entry instanceof StatamicEntry && $entry->collectionHandle() === RedirectDomainRules::COLLECTION && QrFileStore::hasCode($entry), 404);

        return $entry;
    }

    /**
     * The download links of the files made so far, by extension.
     *
     * @return list<array{format: string, url: mixed}>
     */
    private function fileLinks(string $redirect, QrFileStore $files, StatamicEntry $entry): array
    {
        return array_map(
            static fn (string $format): array => ['format' => $format, 'url' => cp_route('marketing-redirects.qr.download', ['redirect' => $redirect, 'format' => $format])],
            array_keys($files->files($entry)),
        );
    }
}
