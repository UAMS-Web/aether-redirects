<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Permission;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\ShortCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class);

/**
 * HTTP tests for the permission that lets a user request a vanity URL with a generated back-half
 * (UAMS-Web/aether-redirects#12): the form opens for it, a request on a vanity domain with the source
 * left blank is saved with a drawn back-half, a typed source or another domain is refused, and the
 * wider permission includes it.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/VanityPermissionTest.php
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
 * A user who holds the vanity URL permission and no other request permission.
 */
function vpGenerated(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::VANITY_PERMISSION]);
}

/**
 * Submit a vanity request with the source left blank, as the given user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function vpSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
{
    return mrdSubmit($user, ['source' => '', ...$overrides]);
}

it('registers the vanity URL permission with a label and a description', function (): void {
    $permission = Permission::get(MarketingRedirectController::VANITY_PERMISSION);

    expect(MarketingRedirectController::VANITY_PERMISSION)->toBe('request vanity urls')
        ->and($permission)->not->toBeNull()
        ->and($permission->label())->toBe('Request vanity URLs')
        ->and($permission->description())->toContain('generates');
});

it('opens the form for the generated level, without a typed source, and with the vanity domains only', function (): void {
    test()->actingAs(vpGenerated());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canRequestVanity', true)
            ->where('canTypeBackHalf', false)
            ->where('taggedAvailable', true)
            ->where('hostOptions', ['uams.health', 'uams.info', 'uamsbaptist.health']));
});

it('offers the domain list a short-link maker has, with go.uams.edu, at the generated level', function (): void {
    test()->actingAs(mrdUser([MarketingRedirectController::VANITY_PERMISSION, MarketingRedirectController::SHORT_LINK_PERMISSION]));

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('hostOptions', ['go.uams.edu', 'uams.health', 'uams.info', 'uamsbaptist.health']));
});

it('offers the wider permission every domain and a typed source', function (): void {
    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canTypeBackHalf', true)
            ->where('hostOptions', ['go.uams.edu', 'uams.health', 'uams.info', 'uamsbaptist.health']));
});

it('saves a vanity request from the generated level with a generated back-half', function (string $host, string $destination): void {
    $response = vpSubmit(vpGenerated(), ['host' => $host, 'destination' => $destination]);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toMatch('/\A\/[A-Za-z0-9]{7}\z/')
        ->and($response->json('redirect.host'))->toBe($host);
})->with([
    'uams.info' => ['uams.info', 'https://www.uams.edu/clinics/flu'],
    'uams.health' => ['uams.health', 'https://uamshealth.com/'],
]);

it('refuses a typed source at the generated level, with a validation error and nothing saved', function (string $source): void {
    $response = vpSubmit(vpGenerated(), ['source' => $source]);

    $response->assertStatus(422)->assertJsonPath('errors.source.0', 'This access requests a generated back-half. Leave the source blank.');

    expect(mrdEntryCount())->toBe(0);
})->with(['a word' => ['/hotdog'], 'a bare word' => ['hotdog']]);

it('treats a source of only spaces at the generated level as blank, the middleware having trimmed it', function (): void {
    vpSubmit(vpGenerated(), ['source' => '   '])->assertCreated();
});

it('refuses a domain that is not a vanity domain at the generated level', function (mixed $host): void {
    $response = vpSubmit(vpGenerated(), ['host' => $host, 'destination' => 'https://example.org/']);

    $response->assertStatus(422)->assertJsonPath('errors.host.0', 'Choose a vanity domain: uams.info, uams.health, uamsbaptist.health.');

    expect(mrdEntryCount())->toBe(0);
})->with([
    'the short-link domain' => ['go.uams.edu'],
    'no domain, the main site' => [null],
    'a blank domain' => [''],
    'another domain' => ['example.org'],
    'not text' => [['uams.info']],
]);

it('keeps the wider permission typing a source and using the main site', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/Hot-Dog'])->assertCreated();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/clinics/flu-old', 'destination' => '/clinics/flu'])->assertCreated();
});

it('includes the tagged URL level, and nothing wider, in the generated level', function (): void {
    $user = vpGenerated();

    mrdTest()->actingAs($user);

    test()->postJson(mrdRoute('marketing-redirects.store'), [
        'kind' => MarketingRedirectController::KIND_TAGGED,
        'destination' => 'https://www.uams.edu/flu',
        'utm_campaign' => 'flu-2026',
        'creative_services' => 'no',
    ])->assertCreated();

    // The generated level may not approve, make short links or open the review queue
    test()->postJson(mrdRoute('marketing-redirects.store'), ['short_link' => true, 'host' => ShortCode::HOST, 'source' => 'abc234', 'destination' => 'https://www.uams.edu/x'])->assertForbidden();
});

it('refuses a vanity request from a holder of only the tagged URL permission', function (): void {
    vpSubmit(mrdUser([MarketingRedirectController::TAGGED_PERMISSION]))->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
});

it('keeps a user with none of the request permissions out of a vanity request', function (): void {
    vpSubmit(mrdApprover())->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
});
