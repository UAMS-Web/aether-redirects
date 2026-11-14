<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Permission;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class);

/**
 * HTTP tests for the permission that lets a user request a vanity URL with a back-half they type
 * (UAMS-Web/aether-redirects#13): on each request the holder types the source or leaves it blank for
 * a generated one, a typed source passes the rules of a typed back-half, the domain is a vanity domain,
 * and the permission includes the two narrower levels.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/CustomVanityPermissionTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();

    $collection = Collection::make(TaggedUrlRequestStore::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();
});

/**
 * A user who holds the custom vanity URL permission and no other request permission.
 */
function cvpCustom(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::CUSTOM_PERMISSION]);
}

it('registers the custom vanity URL permission with a label and a description', function (): void {
    $permission = Permission::get(MarketingRedirectController::CUSTOM_PERMISSION);

    expect(MarketingRedirectController::CUSTOM_PERMISSION)->toBe('request custom vanity urls')
        ->and($permission)->not->toBeNull()
        ->and($permission->label())->toBe('Request custom vanity URLs')
        ->and($permission->description())->toContain('back-half you choose');
});

it('describes each narrower permission as included by the wider ones', function (): void {
    expect(Permission::get(MarketingRedirectController::VANITY_PERMISSION)->description())->toContain('Request custom vanity URLs')
        ->and(Permission::get(MarketingRedirectController::TAGGED_PERMISSION)->description())->toContain('a vanity request permission');
});

it('opens the form for the custom level, which types a source on the vanity domains only', function (): void {
    test()->actingAs(cvpCustom());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canRequestVanity', true)
            ->where('canTypeBackHalf', true)
            ->where('canChooseAnyDomain', false)
            ->where('taggedAvailable', true)
            ->where('hostOptions', ['uams.health', 'uams.info', 'uamsbaptist.health']));
});

it('offers the narrower levels no typed source and the widest level every domain', function (string $permission, bool $types, bool $anyDomain): void {
    test()->actingAs(mrdUser([$permission]));

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canTypeBackHalf', $types)
            ->where('canChooseAnyDomain', $anyDomain));
})->with([
    'tagged' => [MarketingRedirectController::TAGGED_PERMISSION, false, false],
    'generated' => [MarketingRedirectController::VANITY_PERMISSION, false, false],
    'custom' => [MarketingRedirectController::CUSTOM_PERMISSION, true, false],
    'widest' => [MarketingRedirectController::PERMISSION, true, true],
]);

it('saves a vanity request with the back-half the holder typed', function (string $host, string $destination): void {
    $response = mrdSubmit(cvpCustom(), ['host' => $host, 'source' => '/Hot-Dog', 'destination' => $destination]);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/Hot-Dog')
        ->and($response->json('redirect.host'))->toBe($host);
})->with([
    'uams.info' => ['uams.info', 'https://www.uams.edu/clinics/flu'],
    'uams.health' => ['uams.health', 'https://uamshealth.com/'],
]);

it('saves a vanity request with a generated back-half when the holder leaves the source blank', function (): void {
    $response = mrdSubmit(cvpCustom(), ['source' => '']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toMatch('/\A\/[A-Za-z0-9]{7}\z/');
});

it('holds a typed back-half to the rules of a typed back-half', function (string $source, string $message): void {
    $response = mrdSubmit(cvpCustom(), ['source' => $source]);

    $response->assertStatus(422)->assertJsonPath('errors.source.0', $message);

    expect(mrdEntryCount())->toBe(0);
})->with([
    'a space' => ['/hot dog', 'A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.'],
    'a QR suffix' => ['/hotdog-qr', 'A back-half cannot end in -QR, which names the QR redirect of a vanity URL.'],
]);

it('refuses a domain that is not a vanity domain at the custom level', function (mixed $host): void {
    $response = mrdSubmit(cvpCustom(), ['host' => $host, 'source' => '/hotdog', 'destination' => 'https://example.org/']);

    $response->assertStatus(422)->assertJsonPath('errors.host.0', 'Choose a vanity domain: uams.info, uams.health, uamsbaptist.health.');

    expect(mrdEntryCount())->toBe(0);
})->with([
    'the short-link domain' => ['go.uams.edu'],
    'no domain, the main site' => [null],
    'another domain' => ['example.org'],
]);

it('keeps the generated level from typing a source, which the custom level may do', function (): void {
    mrdSubmit(mrdUser([MarketingRedirectController::VANITY_PERMISSION]), ['source' => '/hotdog'])
        ->assertStatus(422)
        ->assertJsonPath('errors.source.0', 'This access requests a generated back-half. Leave the source blank.');
});

it('includes the tagged URL level in the custom level', function (): void {
    mrdTest()->actingAs(cvpCustom());

    test()->postJson(mrdRoute('marketing-redirects.store'), [
        'kind' => MarketingRedirectController::KIND_TAGGED,
        'destination' => 'https://www.uams.edu/flu',
        'utm_campaign' => 'flu-2026',
        'creative_services' => 'no',
    ])->assertCreated();
});

it('refuses a holder of only the tagged URL permission a typed or generated vanity request', function (string $source): void {
    mrdSubmit(mrdUser([MarketingRedirectController::TAGGED_PERMISSION]), ['source' => $source])->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
})->with(['typed' => ['/hotdog'], 'blank' => ['']]);

it('lets the widest level type a source on any domain and use the main site', function (): void {
    mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'source' => '/some path', 'destination' => 'https://example.org/'])->assertCreated();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/clinics/flu-old', 'destination' => '/clinics/flu'])->assertCreated();
});
