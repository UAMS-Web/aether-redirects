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
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class);

/**
 * HTTP tests for the permission that lets a user request a tagged URL and nothing more
 * (UAMS-Web/aether-redirects#11): the form opens for it, a tagged-only request is saved,
 * the vanity fields are refused, and a user with none of the request permissions is kept out.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/TaggedUrlPermissionTest.php
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
 * A user who holds the tagged URL permission and no other request permission.
 */
function ttpTaggedOnly(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::TAGGED_PERMISSION]);
}

/**
 * How many tagged-only requests are waiting for review.
 */
function ttpSaved(): int
{
    return count(app(TaggedUrlRequestStore::class)->pending());
}

/**
 * Submit a tagged-only request as the given user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function ttpSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
{
    $test = mrdTest();
    $test->actingAs($user);

    return $test->postJson(mrdRoute('marketing-redirects.store'), [
        'kind' => MarketingRedirectController::KIND_TAGGED,
        'destination' => 'https://www.uams.edu/flu',
        'utm_campaign' => 'flu-2026',
        'creative_services' => 'no',
        ...$overrides,
    ]);
}

it('opens the request form for a user who can request only tagged URLs, set to a tagged request', function (): void {
    test()->actingAs(ttpTaggedOnly());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('canCreateRedirects', false)
            ->where('shortLinkHost', null)
            ->where('taggedAvailable', true));
});

it('saves a tagged-only request from a user holding only the tagged URL permission', function (): void {
    $response = ttpSubmit(ttpTaggedOnly());

    $response->assertCreated()->assertJsonPath('saved', true);

    expect(ttpSaved())->toBe(1);
});

it('refuses a vanity field on a tagged-only request, with a validation error and nothing saved', function (string $field, string $value): void {
    $response = ttpSubmit(ttpTaggedOnly(), [$field => $value]);

    $response->assertStatus(422)->assertJsonValidationErrors($field);

    expect(ttpSaved())->toBe(0)
        ->and(mrdEntryCount())->toBe(0);
})->with([
    'host' => ['host', 'uams.info'],
    'source' => ['source', '/flu-shot'],
    'response' => ['response', '301'],
    'valid from' => ['valid_from', '2026-12-01'],
    'valid until' => ['valid_until', '2026-12-31'],
]);

it('refuses a vanity request from a user holding only the tagged URL permission', function (): void {
    mrdSubmit(ttpTaggedOnly(), ['host' => 'uams.info'])->assertForbidden();

    expect(mrdEntryCount())->toBe(0)
        ->and(ttpSaved())->toBe(0);
});

it('keeps a user with none of the request permissions out of the form and its submission', function (): void {
    $user = mrdApprover();

    test()->actingAs($user);
    test()->get(mrdRoute('marketing-redirects.create'))->assertForbidden();

    ttpSubmit($user)->assertForbidden();

    expect(ttpSaved())->toBe(0);
});

it('lets a holder of the redirect permission request a tagged URL without the tagged URL permission', function (): void {
    $creator = mrdUser([MarketingRedirectController::PERMISSION]);

    ttpSubmit($creator)->assertCreated();

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('taggedAvailable', true));
});

it('does not offer tagged requests on a site that keeps none, even to a tagged URL holder', function (): void {
    $collection = Collection::findByHandle(TaggedUrlRequestStore::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->delete();

    test()->actingAs(ttpTaggedOnly());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('taggedAvailable', false));

    ttpSubmit(ttpTaggedOnly())
        ->assertStatus(422)
        ->assertJsonPath('errors.kind.0', 'Tagged URL requests are not set up on this site.');
});
