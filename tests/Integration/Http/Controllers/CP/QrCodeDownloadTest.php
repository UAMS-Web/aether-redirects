<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';
require_once __DIR__.'/../../../../Support/QrFileReaders.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\QrCodeController;
use UAMSWeb\Aether\Redirects\Qr\QrFileStore;
use UAMSWeb\Aether\Redirects\Qr\QrMatrix;
use UAMSWeb\Aether\Redirects\Services\QrPairFinder;
use UAMSWeb\Aether\Redirects\Services\QrRequestWriter;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use Zxing\QrReader;

uses(IsolatesStache::class);

covers(QrCodeController::class, QrFileStore::class);

/**
 * Tests for the files of a vanity URL's QR code: made as assets, linked from the redirect, downloadable
 * and replaced in place (UAMS-Web/aether-redirects#14).
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/QrCodeDownloadTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();
});

/**
 * Point the add-on at an asset container on a disk of this test's own.
 */
function qfsContainer(): void
{
    $root = storage_path('framework/testing/qr-assets/'.uniqid('', true));
    File::ensureDirectoryExists($root);

    config()->set('filesystems.disks.qr_test', ['driver' => 'local', 'root' => $root]);
    config()->set('aether-redirects.qr.container', 'qr_codes');

    AssetContainer::make('qr_codes')->disk('qr_test')->save();
}

/**
 * Save a vanity redirect with its QR redirect, and return the vanity entry.
 */
function qfsVanity(string $source = '/flu'): StatamicEntry
{
    $saved = app(QrRequestWriter::class)->save(new MarketingRedirect(
        source: $source,
        destination: 'https://www.uams.edu/clinics/flu',
        response: '301',
        requester: 'Jane',
        submittedBy: 'user-1',
        host: 'uams.info',
        creativeServices: true,
        jobNumber: '12345',
    ), []);

    Assert::assertIsArray($saved);

    return qfsEntry((string) $saved['vanity']['id']);
}

function qfsEntry(string $id): StatamicEntry
{
    Stache::clear();
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    return $entry;
}

function qfsId(StatamicEntry $entry): string
{
    $id = $entry->id();
    Assert::assertIsString($id);

    return $id;
}

/**
 * The bytes of a file the add-on made, read straight from its disk.
 */
function qfsBytes(StatamicEntry $vanity, string $extension): string
{
    $asset = Asset::find('qr_codes::qr-codes/'.qfsId($vanity).'.'.$extension);
    Assert::assertNotNull($asset);

    return (string) $asset->contents();
}

function qfsUser(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::VANITY_PERMISSION]);
}

it('makes the four files as assets, links them from the vanity redirect, and says so', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))
        ->assertOk()
        ->assertJsonCount(4, 'files')
        ->assertJsonPath('files.0', ['format' => 'svg', 'url' => mrdRoute('marketing-redirects.qr.download', ['redirect' => qfsId($vanity), 'format' => 'svg'])])
        ->assertJsonPath('files.3', ['format' => 'eps', 'url' => mrdRoute('marketing-redirects.qr.download', ['redirect' => qfsId($vanity), 'format' => 'eps'])]);

    $linked = qfsEntry(qfsId($vanity))->get('qr_files');

    expect($linked)->toBe([
        'svg' => 'qr_codes::qr-codes/'.qfsId($vanity).'.svg',
        'png' => 'qr_codes::qr-codes/'.qfsId($vanity).'.png',
        'pdf' => 'qr_codes::qr-codes/'.qfsId($vanity).'.pdf',
        'eps' => 'qr_codes::qr-codes/'.qfsId($vanity).'.eps',
    ]);

    foreach ((array) $linked as $id) {
        expect(Asset::find((string) $id))->not->toBeNull();
    }
});

it('encodes the QR URL and nothing else, in every file', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();

    $matrix = QrMatrix::encode('https://uams.info/flu-QR');
    $png = tempnam(sys_get_temp_dir(), 'qr').'.png';
    file_put_contents($png, qfsBytes($vanity, 'png'));

    expect((new QrReader($png))->text())->toBe('https://uams.info/flu-QR')
        ->and(qrSvgModules(qfsBytes($vanity, 'svg')))->toBe(qrModulesOf($matrix))
        ->and(qrPdfModules(qfsBytes($vanity, 'pdf'), $matrix->total()))->toBe(qrModulesOf($matrix))
        ->and(qrEpsModules(qfsBytes($vanity, 'eps'), $matrix->total()))->toBe(qrModulesOf($matrix));

    unlink($png);
});

it('keeps the code when the destination is repointed, because it encodes the QR URL', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();
    $before = qfsBytes($vanity, 'svg');

    $entry = qfsEntry(qfsId($vanity));
    $entry->set('target_url', 'https://www.uams.edu/clinics/moved')->save();

    expect(qfsBytes($vanity, 'svg'))->toBe($before);
});

it('makes the files again in place: the same assets, the same links, replaced contents', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();
    $linked = qfsEntry(qfsId($vanity))->get('qr_files');

    $asset = Asset::find('qr_codes::qr-codes/'.qfsId($vanity).'.svg');
    Assert::assertNotNull($asset);
    $asset->disk()->put($asset->path(), 'damaged');

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();

    expect(qfsEntry(qfsId($vanity))->get('qr_files'))->toBe($linked)
        ->and(qfsBytes($vanity, 'svg'))->toContain('<svg')
        ->and(AssetContainer::findByHandle('qr_codes')?->assets()->count())->toBe(4);
});

it('downloads each file after the request is closed, as the right type', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();

    foreach (['svg', 'png', 'pdf', 'eps'] as $format) {
        $response = test()->get(mrdRoute('marketing-redirects.qr.download', ['redirect' => qfsId($vanity), 'format' => $format]));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.'.$format);
    }

    test()->get(mrdRoute('marketing-redirects.qr.download', ['redirect' => qfsId($vanity), 'format' => 'gif']))->assertNotFound();
});

it('shows the screen with both notices, the guidance link and the files so far', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());

    test()->get(mrdRoute('marketing-redirects.qr', ['redirect' => qfsId($vanity)]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('aether-redirects::QrCodeDownload')
            ->where('host', 'uams.info')
            ->where('source', '/flu')
            ->where('guidanceUrl', QrCodeController::GUIDANCE_URL)
            ->where('generateUrl', mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))
            ->where('files', []));

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertOk();

    test()->get(mrdRoute('marketing-redirects.qr', ['redirect' => qfsId($vanity)]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('files', 4));
});

it('names the notices on the screen so a change to either is a test failure', function (): void {
    $page = (string) file_get_contents(__DIR__.'/../../../../../resources/js/pages/QrCodeDownload.vue');

    expect($page)->toContain('A QR code must always be printed with the visible text URL beside it.')
        ->and($page)->toContain('Do not make your own QR code from the visible text URL, because scans would count as typed visits. Making your own from the QR URL is fine.');
});

it('makes nothing, and says why, when no container is set up', function (): void {
    $vanity = qfsVanity();
    test()->actingAs(qfsUser());

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No asset container is set up for QR codes, so no files can be made.')
        ->assertJsonPath('errors.qr.0', 'No asset container is set up for QR codes, so no files can be made.');

    config()->set('aether-redirects.qr.container', 'missing');

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertUnprocessable();
});

it('refuses a vanity redirect whose QR redirect is gone', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    $companion = (new QrPairFinder)->companionOf($vanity);
    Assert::assertNotNull($companion);
    $companion->delete();
    test()->actingAs(qfsUser());

    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.qr.0', 'This redirect has no QR redirect, so there is no code to make.');
});

it('is not found for a redirect with no QR code, an unknown id, or an entry outside the redirects', function (): void {
    qfsContainer();
    test()->actingAs(qfsUser());
    $plain = app(RedirectDomainStore::class)->create(new MarketingRedirect(
        source: '/plain',
        destination: 'https://www.uams.edu/clinics/plain',
        response: '301',
        submittedBy: 'user-1',
        host: 'uams.info',
    ));

    test()->get(mrdRoute('marketing-redirects.qr', ['redirect' => (string) $plain['id']]))->assertNotFound();
    test()->get(mrdRoute('marketing-redirects.qr', ['redirect' => 'nope']))->assertNotFound();
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => 'nope']))->assertNotFound();
    test()->get(mrdRoute('marketing-redirects.qr.download', ['redirect' => 'nope', 'format' => 'svg']))->assertNotFound();
});

it('is forbidden to a user who cannot request a vanity URL, and open to an approver', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    $url = mrdRoute('marketing-redirects.qr', ['redirect' => qfsId($vanity)]);

    test()->actingAs(mrdUser([MarketingRedirectController::TAGGED_PERMISSION]));
    test()->get($url)->assertForbidden();
    test()->postJson(mrdRoute('marketing-redirects.qr.generate', ['redirect' => qfsId($vanity)]))->assertForbidden();

    test()->actingAs(mrdApprover());
    test()->get($url)->assertOk();
});

it('is open to each permission that can request a vanity URL, and to an approver', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    $url = mrdRoute('marketing-redirects.qr', ['redirect' => qfsId($vanity)]);

    foreach ([MarketingRedirectController::VANITY_PERMISSION, MarketingRedirectController::CUSTOM_VANITY_PERMISSION, MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION] as $permission) {
        test()->actingAs(mrdUser([$permission]));
        test()->get($url)->assertOk();
    }
});

it('returns the ids of the files it made, and writes the metadata of each asset', function (): void {
    qfsContainer();
    $vanity = qfsVanity();

    $made = app(QrFileStore::class)->generate($vanity);

    $id = qfsId($vanity);

    expect($made)->toBe([
        'svg' => 'qr_codes::qr-codes/'.$id.'.svg',
        'png' => 'qr_codes::qr-codes/'.$id.'.png',
        'pdf' => 'qr_codes::qr-codes/'.$id.'.pdf',
        'eps' => 'qr_codes::qr-codes/'.$id.'.eps',
    ]);

    $disk = AssetContainer::findByHandle('qr_codes')?->disk();
    Assert::assertNotNull($disk);

    foreach (['svg', 'png', 'pdf', 'eps'] as $extension) {
        expect($disk->exists('qr-codes/.meta/'.$id.'.'.$extension.'.yaml'))->toBeTrue();
    }
});

it('keeps the files in the folder the site names, trimmed of slashes, or at the root of the container when there is none', function (?string $folder, string $directory): void {
    qfsContainer();
    config()->set('aether-redirects.qr.folder', $folder);
    $vanity = qfsVanity();

    $made = app(QrFileStore::class)->generate($vanity);

    expect($made)->toBeArray()
        ->and($made['svg'] ?? null)->toBe('qr_codes::'.$directory.qfsId($vanity).'.svg');
})->with([
    'a plain folder' => ['qr', 'qr/'],
    'slashes on both sides' => ['/qr/files/', 'qr/files/'],
    'an empty folder' => ['', ''],
    'no folder' => [null, ''],
]);

it('makes nothing when the domain of the redirect cannot be resolved, or the QR redirect has no source', function (): void {
    qfsContainer();
    $vanity = qfsVanity();

    $companion = (new QrPairFinder)->companionOf($vanity);
    Assert::assertNotNull($companion);
    $companion->set('source', null)->save();

    expect(app(QrFileStore::class)->generate(qfsEntry(qfsId($vanity))))->toBe('This redirect has no QR redirect, so there is no code to make.');

    $other = qfsVanity('/other');
    $record = RedirectDomainList::idFor('uams.info');
    Assert::assertIsString($record);
    qfsEntry($record)->delete();

    expect(app(QrFileStore::class)->generate(qfsEntry(qfsId($other))))->toBe('This redirect has no QR redirect, so there is no code to make.');
});

it('lists only the links that still reach an asset', function (): void {
    qfsContainer();
    $vanity = qfsVanity();
    app(QrFileStore::class)->generate($vanity);

    $entry = qfsEntry(qfsId($vanity));
    $entry->set('qr_files', ['svg' => 'qr_codes::missing.svg', 'png' => 12, 'pdf' => 'qr_codes::qr-codes/'.qfsId($vanity).'.pdf'])->save();

    $found = app(QrFileStore::class)->files(qfsEntry(qfsId($vanity)));

    expect(array_keys($found))->toBe(['pdf']);

    qfsEntry(qfsId($vanity))->set('qr_files', 'not a list')->save();

    expect(app(QrFileStore::class)->files(qfsEntry(qfsId($vanity))))->toBe([]);
});
