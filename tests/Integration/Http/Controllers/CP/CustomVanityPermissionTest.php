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
use UAMSWeb\Aether\Redirects\Support\ShortCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class);

/**
 * HTTP tests for the permission that lets a user request a vanity URL with a back-half they type
 * (UAMS-Web/aether-redirects#13): it is registered, it includes the two narrower request levels, its
 * holder types a source or leaves it blank for a generated one on a vanity domain, and the main site
 * and the short-link domain stay with the widest permission.
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
function cvCustom(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::CUSTOM_VANITY_PERMISSION]);
}

it('registers the custom vanity URL permission with a label and a description', function (): void {
    $permission = Permission::get(MarketingRedirectController::CUSTOM_VANITY_PERMISSION);

    expect(MarketingRedirectController::CUSTOM_VANITY_PERMISSION)->toBe('request custom vanity urls')
        ->and($permission)->not->toBeNull()
        ->and($permission->label())->toBe('Request custom vanity URLs')
        ->and($permission->description())->toContain('types');
});

it('opens the form for the custom level with a typed source, the vanity domains and no main site', function (): void {
    test()->actingAs(cvCustom());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canRequestVanity', true)
            ->where('canTypeBackHalf', true)
            ->where('canUseMainSite', false)
            ->where('taggedAvailable', true)
            ->where('hostOptions', ['uams.health', 'uams.info', 'uamsbaptist.health']));
});

it('keeps the generated level without a typed source and the widest level with the main site', function (): void {
    test()->actingAs(mrdUser([MarketingRedirectController::VANITY_PERMISSION]));

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canTypeBackHalf', false)
            ->where('canUseMainSite', false));

    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canTypeBackHalf', true)
            ->where('canUseMainSite', true));
});

it('saves a request that types the back-half', function (): void {
    $response = mrdSubmit(cvCustom(), ['source' => '/hotdog']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/hotdog')
        ->and($response->json('redirect.host'))->toBe('uams.info');
});

it('saves a request that leaves the back-half blank for a generated one', function (): void {
    $response = mrdSubmit(cvCustom(), ['source' => '']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toMatch('/\A\/[A-Za-z0-9]{7}\z/');
});

it('refuses a domain that is not a vanity domain at the custom level', function (mixed $host): void {
    mrdSubmit(cvCustom(), ['host' => $host, 'source' => '/hotdog', 'destination' => 'https://example.org/'])
        ->assertStatus(422)
        ->assertJsonPath('errors.host.0', 'Choose a vanity domain: uams.info, uams.health, uamsbaptist.health.');

    expect(mrdEntryCount())->toBe(0);
})->with([
    'the short-link domain' => ['go.uams.edu'],
    'no domain, the main site' => [null],
    'another domain' => ['example.org'],
]);

it('holds a typed source to the same rules as the widest level', function (): void {
    mrdSubmit(cvCustom(), ['source' => '/hot dog'])->assertStatus(422)->assertJsonValidationErrors('source');

    expect(mrdEntryCount())->toBe(0);
});

it('still refuses a typed source at the generated level', function (): void {
    mrdSubmit(mrdUser([MarketingRedirectController::VANITY_PERMISSION]), ['source' => '/hotdog'])
        ->assertStatus(422)
        ->assertJsonPath('errors.source.0', 'This access requests a generated back-half. Leave the source blank.');
});

it('includes the tagged URL level, and nothing wider, in the custom level', function (): void {
    test()->actingAs(cvCustom());

    test()->postJson(mrdRoute('marketing-redirects.store'), [
        'kind' => MarketingRedirectController::KIND_TAGGED,
        'destination' => 'https://www.uams.edu/flu',
        'utm_campaign' => 'flu-2026',
        'creative_services' => 'no',
    ])->assertCreated();

    // The custom level may not approve or make short links
    test()->postJson(mrdRoute('marketing-redirects.store'), ['short_link' => true, 'host' => ShortCode::HOST, 'source' => 'abc234', 'destination' => 'https://www.uams.edu/x'])->assertForbidden();
});

it('keeps the widest permission typing a source and using the main site', function (): void {
    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/clinics/flu-old', 'destination' => '/clinics/flu'])->assertCreated();
});
