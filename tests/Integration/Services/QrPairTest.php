<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use PHPUnit\Framework\Assert;
use Statamic\Contracts\Entries\Entry as ContractEntry;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use UAMSWeb\Aether\Redirects\Listeners\SyncQrRedirect;
use UAMSWeb\Aether\Redirects\Services\QrPairFinder;
use UAMSWeb\Aether\Redirects\Services\QrRequestWriter;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(QrRequestWriter::class, SyncQrRedirect::class, QrPairFinder::class, RedirectDomainStore::class);

/**
 * Tests for the pair a vanity URL and its `-QR` redirect make (UAMS-Web/aether-redirects#111): the writer saves
 * both, and the two are approved, rejected and repointed together.
 *
 * @command  vendor/bin/pest tests/Integration/Services/QrPairTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();
});

/**
 * Save a vanity redirect and its -QR companion the way a request does, and return what the writer returned.
 *
 * @return array{vanity: array<string, mixed>, qr: array<string, mixed>}|string
 */
function qrPair(string $source, ?string $host = 'uams.info'): array|string
{
    $store = app(RedirectDomainStore::class);

    return app(QrRequestWriter::class)->save(qrRedirect($source, $host), array_values(array_filter(
        $store->all(),
        static fn (array $row): bool => RedirectStatus::fromRow($row) !== RedirectStatus::Rejected,
    )));
}

/**
 * Save a vanity redirect alone, as a request without a code does.
 *
 * @return array<string, mixed>
 */
function qrPlain(string $source): array
{
    return app(RedirectDomainStore::class)->create(qrRedirect($source, 'uams.info'));
}

function qrRedirect(string $source, ?string $host): MarketingRedirect
{
    return new MarketingRedirect(
        source: $source,
        destination: 'https://www.uams.edu/clinics/flu',
        response: '301',
        requester: 'Jane',
        submittedBy: 'user-1',
        host: $host,
        creativeServices: true,
        jobNumber: '12345',
    );
}

/**
 * The id of the entry that holds a source on the domain record of a host, in any case.
 */
function qrIdOf(string $host, string $source): ?string
{
    foreach (app(RedirectDomainStore::class)->all() as $row) {
        if (is_string($row['source'] ?? null) && strtolower($row['source']) === strtolower($source) && ($row['host'] ?? null) === $host && is_string($row['id'] ?? null)) {
            return $row['id'];
        }
    }

    return null;
}

/**
 * A field of an entry as it is on disk, read after the in-memory copies are dropped.
 */
function qrStored(string $id, string $field): mixed
{
    Stache::clear();

    return mrdField($id, $field);
}

/**
 * Save fields onto the entry with an id, as an editor saving it would.
 *
 * @param  array<string, mixed>  $fields
 */
function qrSave(string $id, array $fields): void
{
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->merge($fields);
    $entry->save();
}

/**
 * Delete the entry with an id.
 */
function qrDelete(string $id): void
{
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->delete();
}

it('saves a -QR redirect beside the vanity one, each tagged for its role, both pending', function (): void {
    $saved = qrPair('/flu');

    $vanity = qrIdOf('uams.info', '/flu');
    $qr = qrIdOf('uams.info', '/flu-QR');

    Assert::assertIsArray($saved);

    expect(mrdEntryCount())->toBe(2)
        ->and($saved['vanity']['id'])->toBe($vanity)
        ->and($saved['qr']['id'])->toBe($qr)
        ->and($vanity)->not->toBeNull()
        ->and($qr)->not->toBeNull()
        ->and(qrStored((string) $vanity, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=text-url')
        ->and(qrStored((string) $qr, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr')
        ->and(qrStored((string) $vanity, 'qr_role'))->toBe('vanity')
        ->and(qrStored((string) $qr, 'qr_role'))->toBe('companion')
        ->and(qrStored((string) $vanity, 'status'))->toBe('pending')
        ->and(qrStored((string) $qr, 'status'))->toBe('pending')
        ->and(qrStored((string) $qr, 'submitted_by'))->toBe(qrStored((string) $vanity, 'submitted_by'));
});

it('serves neither redirect until the request is approved', function (): void {
    qrPair('/flu');

    expect(mrdServe('https://uams.info/flu')->getStatusCode())->toBe(404)
        ->and(mrdServe('https://uams.info/flu-QR')->getStatusCode())->toBe(404);
});

it('refuses a code for a request on the main site, saving nothing', function (): void {
    expect(qrPair('/flu', null))->toBe('A QR code is made for a vanity URL, so choose a vanity domain.')
        ->and(mrdEntryCount())->toBe(0);
});

it('refuses a code when the -QR source is already taken, saving nothing', function (): void {
    mrdSeed([
        'host' => 'uams.info',
        'source' => '/flu-qr',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'approved',
    ]);

    expect(qrPair('/flu'))->toBe('A redirect from uams.info/flu-qr to https://www.uams.edu/other already exists within an overlapping validity window.');

    // The seeded row alone: neither half of the request was saved
    expect(mrdEntryCount())->toBe(1);
});

it('does not count a rejected -QR redirect as taking the source', function (): void {
    mrdSeed([
        'host' => 'uams.info',
        'source' => '/flu-QR',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'rejected',
    ]);

    qrPair('/flu');

    expect(mrdEntryCount())->toBe(3);
});

it('approves the two redirects together, and both serve', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');
    $qr = (string) qrIdOf('uams.info', '/flu-QR');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $vanity]))->assertOk();

    $served = mrdServe('https://uams.info/flu');
    $servedQr = mrdServe('https://uams.info/flu-QR');

    expect(qrStored($qr, 'status'))->toBe('approved')
        ->and(qrStored($qr, 'reviewed_by'))->toBe(qrStored($vanity, 'reviewed_by'))
        ->and($served->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=text-url')
        ->and($servedQr->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');
});

it('reaches the QR redirect whatever the case of its suffix', function (): void {
    qrPair('/flu');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => (string) qrIdOf('uams.info', '/flu')]))->assertOk();

    foreach (['/flu-QR', '/flu-qr', '/flu-Qr', '/FLU-qR'] as $path) {
        expect(mrdServe('https://uams.info'.$path)->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');
    }
});

it('rejects the two redirects together, with the same reason', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');
    $qr = (string) qrIdOf('uams.info', '/flu-QR');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.reject', ['redirect' => $vanity]), ['reason' => 'Not a marketing piece'])->assertOk();

    expect(qrStored($qr, 'status'))->toBe('rejected')
        ->and(qrStored($qr, 'rejection_reason'))->toBe('Not a marketing piece')
        ->and(mrdServe('https://uams.info/flu-QR')->getStatusCode())->toBe(404);
});

it('leaves an unrelated -QR redirect alone when a vanity redirect without a code is approved', function (): void {
    qrPlain('/flu');
    $other = mrdSeed([
        'host' => 'uams.info',
        'source' => '/flu-QR',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'pending',
        'submitted_by' => 'someone-else',
    ]);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => (string) qrIdOf('uams.info', '/flu')]))->assertOk();

    expect(qrStored(mrdId($other), 'status'))->toBe('pending');
});

it('leaves a pair that is already reviewed as it is when its twin is approved', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');
    $qr = (string) qrIdOf('uams.info', '/flu-QR');

    qrSave($qr, ['status' => 'rejected', 'rejection_reason' => 'Handled by hand', 'reviewed_by' => 'someone']);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $vanity]))->assertOk();

    expect(qrStored($qr, 'status'))->toBe('rejected')
        ->and(qrStored($qr, 'reviewed_by'))->toBe('someone');
});

it('repoints the QR redirect when the vanity redirect is saved with a new destination', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');
    $qr = (string) qrIdOf('uams.info', '/flu-QR');

    qrSave($vanity, ['target_url' => 'https://www.uams.edu/clinics/new?utm_source=print']);

    expect(qrStored($vanity, 'target_url'))->toBe('https://www.uams.edu/clinics/new?utm_source=print&utm_specs=text-url')
        ->and(qrStored($qr, 'target_url'))->toBe('https://www.uams.edu/clinics/new?utm_source=print&utm_specs=qr');
});

it('keeps the vanity redirect tagged as the text URL when an editor saves it with another value', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');

    qrSave($vanity, ['target_url' => 'https://www.uams.edu/clinics/flu?utm_specs=qr']);

    expect(qrStored($vanity, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=text-url')
        ->and(qrStored((string) qrIdOf('uams.info', '/flu-QR'), 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');
});

it('repoints a QR redirect that has already been approved', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $vanity]))->assertOk();

    qrSave($vanity, ['target_url' => 'https://www.uams.edu/clinics/moved']);

    expect(mrdServe('https://uams.info/flu-qr')->headers->get('Location'))->toBe('https://www.uams.edu/clinics/moved?utm_specs=qr');
});

it('leaves a redirect with no QR code, and one that is not a redirect, as it is saved', function (): void {
    qrPlain('/flu');
    $plain = (string) qrIdOf('uams.info', '/flu');
    $other = mrdSeed(['host' => 'uams.info', 'source' => '/flu-QR', 'target_url' => 'https://www.uams.edu/other', 'status' => 'approved']);

    qrSave($plain, ['target_url' => 'https://www.uams.edu/clinics/new']);

    expect(qrStored($plain, 'target_url'))->toBe('https://www.uams.edu/clinics/new')
        ->and(qrStored(mrdId($other), 'target_url'))->toBe('https://www.uams.edu/other');
});

it('does not touch a QR redirect on another domain that shares the source', function (): void {
    qrPair('/flu');
    $elsewhere = mrdSeed([
        'host' => 'uams.health',
        'source' => '/flu-QR',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'approved',
        'qr_role' => 'companion',
    ]);

    qrSave((string) qrIdOf('uams.info', '/flu'), ['target_url' => 'https://www.uams.edu/clinics/new']);

    expect(qrStored(mrdId($elsewhere), 'target_url'))->toBe('https://www.uams.edu/other');
});

it('lets a vanity redirect whose QR redirect was deleted be saved', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');

    qrDelete((string) qrIdOf('uams.info', '/flu-QR'));
    qrSave($vanity, ['target_url' => 'https://www.uams.edu/clinics/new']);

    expect(qrStored($vanity, 'target_url'))->toBe('https://www.uams.edu/clinics/new');
});

it('names the conflicting redirect without a destination when it has none', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/flu-qr', 'status' => 'approved']);

    expect(qrPair('/flu'))->toBe('A redirect from uams.info/flu-qr already exists within an overlapping validity window.');
});

it('does not repoint a QR redirect of another domain or another source when the vanity redirect has none of its own', function (): void {
    qrPair('/flu');
    qrDelete((string) qrIdOf('uams.info', '/flu-QR'));

    $otherDomain = mrdSeed(['host' => 'uams.health', 'source' => '/flu-QR', 'target_url' => 'https://www.uams.edu/a', 'status' => 'approved', 'qr_role' => 'companion']);
    $otherSource = mrdSeed(['host' => 'uams.info', 'source' => '/zzz-QR', 'target_url' => 'https://www.uams.edu/b', 'status' => 'approved', 'qr_role' => 'companion']);

    qrSave((string) qrIdOf('uams.info', '/flu'), ['target_url' => 'https://www.uams.edu/clinics/new']);

    expect(qrStored(mrdId($otherDomain), 'target_url'))->toBe('https://www.uams.edu/a')
        ->and(qrStored(mrdId($otherSource), 'target_url'))->toBe('https://www.uams.edu/b');
});

it('does not take a QR redirect for the twin of a redirect that was never given a code', function (): void {
    qrPlain('/flu');
    $companion = mrdSeed([
        'host' => 'uams.info',
        'source' => '/flu-QR',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'pending',
        'submitted_by' => 'someone-else',
        'qr_role' => 'companion',
    ]);

    qrSave((string) qrIdOf('uams.info', '/flu'), ['target_url' => 'https://www.uams.edu/clinics/new']);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => (string) qrIdOf('uams.info', '/flu')]))->assertOk();

    expect(qrStored(mrdId($companion), 'target_url'))->toBe('https://www.uams.edu/other')
        ->and(qrStored(mrdId($companion), 'status'))->toBe('pending');
});

it('leaves a vanity redirect with no destination, or an empty one, as it is saved, and its QR redirect untouched', function (): void {
    qrPair('/flu');
    $vanity = (string) qrIdOf('uams.info', '/flu');
    $qr = (string) qrIdOf('uams.info', '/flu-QR');

    qrSave($vanity, ['target_url' => '']);

    expect(qrStored($vanity, 'target_url'))->toBeNull()
        ->and(qrStored($qr, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');

    qrSave($vanity, ['target_url' => null]);

    expect(qrStored($vanity, 'target_url'))->toBeNull()
        ->and(qrStored($qr, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');
});

it('ignores a saving event for something that is not an entry', function (): void {
    $guarded = Mockery::mock(ContractEntry::class);
    $guarded->shouldNotReceive('get', 'set', 'save');

    app(SyncQrRedirect::class)->handle(new EntrySaving($guarded));

    expect($guarded)->toBeInstanceOf(ContractEntry::class);
});
