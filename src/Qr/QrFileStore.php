<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer as ConcreteContainer;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\AssetContainer;
use Statamic\Filesystem\AbstractAdapter;
use UAMSWeb\Aether\Redirects\Services\QrPairFinder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;

/**
 * Makes the files of a QR code and keeps them as assets, linked from the vanity redirect
 * (UAMS-Web/aether-redirects#14).
 *
 * The code encodes the `-QR` redirect's URL and nothing else, so its destination can still be changed
 * after the code is printed. Making the files again writes the same paths, so each file is replaced
 * in place and its link does not change.
 */
final readonly class QrFileStore
{
    /**
     * The entry field that lists the files, keyed by extension, each an asset id.
     */
    public const string FIELD = 'qr_files';

    public function __construct(private QrPairFinder $finder) {}

    /**
     * The writers, one per format.
     *
     * @return list<QrWriter>
     */
    public static function writers(): array
    {
        return [new SvgWriter, new PngWriter, new PdfWriter, new EpsWriter];
    }

    /**
     * Make the files for a vanity redirect and link them from it.
     *
     * @return array<string, string>|string The asset id of each file by extension, or a message saying why none were made
     */
    public function generate(StatamicEntry $vanity): array|string
    {
        $handle = config('aether-redirects.qr.container');
        $container = is_string($handle) ? AssetContainer::findByHandle($handle) : null;

        if (! $container instanceof ConcreteContainer) {
            return __('No asset container is set up for QR codes, so no files can be made.');
        }

        $disk = $container->disk();

        if (! $disk instanceof AbstractAdapter) {
            return __('No asset container is set up for QR codes, so no files can be made.');
        }

        $companion = $this->finder->companionOf($vanity);
        $host = self::hostOf($vanity);
        $source = $companion?->get('source');

        if ($host === null || ! is_string($source)) {
            return __('This redirect has no QR redirect, so there is no code to make.');
        }

        $matrix = QrMatrix::encode('https://'.$host.$source);
        $folder = config('aether-redirects.qr.folder');
        $directory = is_string($folder) && $folder !== '' ? trim($folder, '/').'/' : '';

        $ids = [];

        $id = $vanity->id();
        // A saved entry always has a string id; the empty fallback only satisfies the analyzer.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $name = is_string($id) ? $id : '';

        foreach (self::writers() as $writer) {
            $path = $directory.$name.'.'.$writer->extension();

            $disk->put($path, $writer->write($matrix));
            $container->makeAsset($path)->save();

            // An asset's id is its container handle and path.
            $ids[$writer->extension()] = sprintf('%s::%s', $container->handle(), $path);
        }

        $vanity->set(self::FIELD, $ids);
        $vanity->save();

        return $ids;
    }

    /**
     * The files linked from a vanity redirect, by extension.
     *
     * @return array<string, Asset>
     */
    public function files(StatamicEntry $vanity): array
    {
        $linked = $vanity->get(self::FIELD);
        $files = [];

        foreach (is_array($linked) ? $linked : [] as $extension => $id) {
            $asset = is_string($id) ? \Statamic\Facades\Asset::find($id) : null;

            if ($asset instanceof Asset) {
                // PHP turns a numeric string key into an integer key, so the cast cannot change the array; it keeps the key a string for the analyzer.
                // @pest-mutate-ignore:RemoveStringCast
                $files[(string) $extension] = $asset;
            }
        }

        return $files;
    }

    /**
     * The domain a redirect is on, as a host name (the entry holds the id of the domain's record).
     */
    public static function hostOf(StatamicEntry $entry): ?string
    {
        return RedirectDomainList::resolve($entry->get('host'), RedirectDomainList::records());
    }

    /**
     * Whether a redirect is a vanity redirect that was made with a QR redirect.
     */
    public static function hasCode(StatamicEntry $entry): bool
    {
        return $entry->get(QrRedirect::FIELD) === QrRedirect::VANITY;
    }
}
