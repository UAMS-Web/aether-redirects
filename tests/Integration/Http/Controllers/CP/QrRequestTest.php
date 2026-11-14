<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Statamic\Facades\Stache;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class);

/**
 * HTTP tests for asking for a QR code with a vanity URL (UAMS-Web/aether-redirects#112): the request saves
 * a second, `-QR` redirect beside the vanity one.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/QrRequestTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();
});

/**
 * The id of the entry that holds a source on the domain record of a host, in any case.
 */
function qrRequestIdOf(string $host, string $source): ?string
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
function qrRequestStored(string $id, string $field): mixed
{
    Stache::clear();

    return mrdField($id, $field);
}

it('saves a -QR redirect beside the vanity one, each tagged for its role, both pending', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => true]);

    $response->assertCreated()
        ->assertJsonPath('saved', true)
        ->assertJsonPath('message', 'Redirect submitted for review. It serves on uams.info once an approver approves it.');

    $vanity = qrRequestIdOf('uams.info', '/flu');
    $qr = qrRequestIdOf('uams.info', '/flu-QR');

    expect(mrdEntryCount())->toBe(2)
        ->and($response->json('redirect.id'))->toBe($vanity)
        ->and($response->json('qr.id'))->toBe($qr)
        ->and($vanity)->not->toBeNull()
        ->and($qr)->not->toBeNull()
        ->and(qrRequestStored((string) $vanity, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=text-url')
        ->and(qrRequestStored((string) $qr, 'target_url'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr')
        ->and(qrRequestStored((string) $vanity, 'qr_role'))->toBe('vanity')
        ->and(qrRequestStored((string) $qr, 'qr_role'))->toBe('companion')
        ->and(qrRequestStored((string) $vanity, 'status'))->toBe('pending')
        ->and(qrRequestStored((string) $qr, 'status'))->toBe('pending')
        ->and(qrRequestStored((string) $qr, 'submitted_by'))->toBe(qrRequestStored((string) $vanity, 'submitted_by'));
});

it('serves neither redirect until the request is approved', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => true])->assertCreated();

    expect(mrdServe('https://uams.info/flu')->getStatusCode())->toBe(404)
        ->and(mrdServe('https://uams.info/flu-QR')->getStatusCode())->toBe(404);
});

it('saves the QR redirect for a generated back-half', function (): void {
    $response = mrdSubmit(mrdUser([MarketingRedirectController::VANITY_PERMISSION]), ['source' => null, 'qr' => true]);

    $response->assertCreated();

    /** @var string $source */
    $source = $response->json('redirect.source');

    expect($source)->toMatch('/\A\/[A-Za-z0-9]{7}\z/')
        ->and($response->json('qr.source'))->toBe($source.'-QR')
        ->and(mrdEntryCount())->toBe(2);
});

it('saves one redirect, untouched, when no code is asked for', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/flu']);

    $response->assertCreated();

    $id = (string) qrRequestIdOf('uams.info', '/flu');

    expect(mrdEntryCount())->toBe(1)
        ->and($response->json('qr'))->toBeNull()
        ->and(qrRequestStored($id, 'target_url'))->toBe('https://www.uams.edu/clinics/flu')
        ->and(app(RedirectDomainStore::class)->find($id))->not->toHaveKey('qr_role');
});

it('does not take a falsy qr value as a request', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => false])->assertCreated();

    expect(mrdEntryCount())->toBe(1);
});

it('refuses a code for a request on the main site, saving nothing', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/flu', 'host' => null, 'qr' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.qr.0', 'A QR code is made for a vanity URL, so choose a vanity domain.');

    expect(mrdEntryCount())->toBe(0);
});

it('refuses a code when the -QR source is already taken, saving nothing', function (): void {
    mrdSeed([
        'host' => 'uams.info',
        'source' => '/flu-qr',
        'target_url' => 'https://www.uams.edu/other',
        'status' => 'approved',
    ]);

    mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.qr.0', 'A redirect from uams.info/flu-qr to https://www.uams.edu/other already exists within an overlapping validity window.');

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

    mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => true])->assertCreated();

    expect(mrdEntryCount())->toBe(3);
});

it('forbids a code to a user who can request only tagged URLs', function (): void {
    mrdSubmit(mrdUser([MarketingRedirectController::TAGGED_PERMISSION]), ['source' => '/flu', 'qr' => true])->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
});

it('names the conflicting redirect without a destination when it has none', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/flu-qr', 'status' => 'approved']);

    mrdSubmit(mrdCreator(), ['source' => '/flu', 'qr' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.qr.0', 'A redirect from uams.info/flu-qr already exists within an overlapping validity window.');
});
