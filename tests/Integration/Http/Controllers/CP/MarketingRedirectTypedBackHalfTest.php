<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

/**
 * HTTP coverage for the rules a typed back-half on a vanity URL request must pass
 * (UAMS-Web/aether-redirects#16), when the request is submitted and again when it is approved.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/MarketingRedirectTypedBackHalfTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    mrdSetUp();
});

it('accepts a typed back-half on a vanity domain and serves it once approved', function (string $host, string $destination): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host, 'source' => '/Hot-Dog', 'destination' => $destination]);

    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    expect(mrdServe('https://'.$host.'/hot-dog')->headers->get('Location'))->toBe($destination);
})->with([
    'uams.info' => ['uams.info', 'https://www.uams.edu/clinics/flu'],
    'uams.health' => ['uams.health', 'https://uamshealth.com/'],
]);

it('refuses a typed back-half that fails a rule, names the rule, and saves nothing', function (string $source, string $message): void {
    config(['aether-redirects.back_half.reserved' => ['admin']]);
    mrdSeed(['host' => 'uams.info', 'source' => '/Taken', 'target_url' => 'https://www.uams.edu/old', 'status' => 'approved']);
    mrdSeed(['host' => 'uams.info', 'source' => '/waiting', 'target_url' => 'https://www.uams.edu/old', 'status' => 'pending', 'submitted_by' => 'someone-else']);
    $before = mrdEntryCount();

    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => $source]);

    $response->assertStatus(422);

    expect($response->json('errors.source.0'))->toBe($message)
        ->and(mrdEntryCount())->toBe($before);
})->with([
    'a space' => ['/hot dog', 'A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.'],
    'a nested path' => ['/hot/dog', 'A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.'],
    'too long' => ['/'.str_repeat('a', 51), 'A back-half is 50 characters or fewer.'],
    'a QR suffix' => ['/hotdog-qr', 'A back-half cannot end in -QR, which names the QR redirect of a vanity URL.'],
    'a reserved word' => ['/Admin', '"Admin" is a reserved word and cannot be used as a back-half.'],
    'a live redirect in another case' => ['/tAKEN', 'The back-half "tAKEN" is already in use on this domain, in any case.'],
    'a pending request in another case' => ['/WAITING', 'The back-half "WAITING" is already in use on this domain, in any case.'],
]);

it('lets a rejected request free its back-half', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/hotdog', 'target_url' => 'https://www.uams.edu/old', 'status' => 'rejected', 'rejection_reason' => 'No']);

    mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/HotDog'])->assertCreated();
});

it('keeps the rules to a vanity domain', function (): void {
    // A domain the collection serves, and the main site, keep their own path rules
    mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'source' => '/some/long path-QR', 'destination' => 'https://example.org/'])->assertCreated();

    mrdMainSiteGlobal();
    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/clinics/flu-QR', 'destination' => '/clinics/flu'])->assertCreated();
});

it('approves a typed back-half when nothing live holds it, and counts only approved rows', function (): void {
    // Two pending requests for one back-half can exist if the second was saved before the rules; neither blocks the other
    $first = mrdGeneratedRequest('/Hotdog', [BackHalf::FIELD => false]);

    mrdGeneratedRequest('/hotdog', [BackHalf::FIELD => false]);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $first->id()]))->assertOk();

    expect(mrdField(mrdId($first), 'status'))->toBe('approved');
});

it('refuses to approve the second of two pending requests for one back-half', function (): void {
    $first = mrdGeneratedRequest('/Hotdog', [BackHalf::FIELD => false]);
    $second = mrdGeneratedRequest('/hotdog', [BackHalf::FIELD => false]);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $first->id()]))->assertOk();

    $response = test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $second->id()]));

    $response->assertStatus(422);

    expect($response->json('errors.source.0'))->toBe('The back-half "hotdog" is already in use on this domain, in any case.')
        ->and(mrdField(mrdId($second), 'status'))->toBe('pending');
});

it('runs every rule again at approval', function (string $source, string $message): void {
    config(['aether-redirects.back_half.reserved' => ['admin']]);
    $request = mrdGeneratedRequest($source, [BackHalf::FIELD => false]);

    test()->actingAs(mrdApprover());
    $response = test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]));

    $response->assertStatus(422);

    expect($response->json('errors.source.0'))->toBe($message)
        ->and(mrdField(mrdId($request), 'status'))->toBe('pending');
})->with([
    'a malformed back-half' => ['/hot dog', 'A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.'],
    'a QR suffix' => ['/hotdog-QR', 'A back-half cannot end in -QR, which names the QR redirect of a vanity URL.'],
    'a reserved word added since' => ['/admin', '"admin" is a reserved word and cannot be used as a back-half.'],
]);

it('leaves a typed path on another domain to its own approval rules', function (): void {
    $request = mrdGeneratedRequest('/some path-QR', [BackHalf::FIELD => false, 'host' => 'go.uams.edu', 'target_url' => 'https://example.org/new']);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();
});

it('does not hold a generated back-half to the typed rules', function (): void {
    // A generator that draws only codes the reserved list names shows the typed rules do not run on a generated one
    app()->bind(BackHalf::GENERATOR, static fn (): RandomCode => new RandomCode('ab', 1, 5));
    config(['aether-redirects.back_half.reserved' => ['a', 'b']]);

    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBeIn(['/a', '/b']);
});
