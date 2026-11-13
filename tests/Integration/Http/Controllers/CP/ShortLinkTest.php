<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Support\ShortCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

/**
 * HTTP coverage for `go.uams.edu` short links (UAMS-Web/aether-redirects#84): a
 * user with `create short links` draws a 6-character code, a code is never
 * issued twice on the domain, and a code is saved approved when its destination
 * is on a trusted host and pending otherwise.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/ShortLinkTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    mrdSetUp();
    config()->set('app.url', 'https://uams.edu');
});

/**
 * A user who can make short links and nothing else.
 */
function slMaker(): FileStatamicUser
{
    return mrdUser([MarketingRedirectController::SHORT_LINK_PERMISSION]);
}

/**
 * Save a short link as the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function slSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
{
    $test = mrdTest();
    $test->actingAs($user);

    return $test->postJson(mrdRoute('marketing-redirects.store'), [
        'short_link' => true,
        'destination' => 'https://www.uams.edu/clinics/flu',
        'response' => '301',
        'creative_services' => 'no',
        ...$overrides,
    ]);
}

/**
 * Draw a code as the signed-in user.
 *
 * @return TestResponse<Response>
 */
function slDraw(FileStatamicUser $user): TestResponse
{
    $test = mrdTest();
    $test->actingAs($user);

    return $test->postJson(mrdRoute('marketing-redirects.short-code'));
}

/**
 * Draw codes from a small alphabet, so a test can make a draw collide.
 */
function slGenerator(string $alphabet, int $length, int $attempts): void
{
    app()->bind(ShortCode::GENERATOR, static fn (): RandomCode => new RandomCode($alphabet, $length, $attempts));
}

/**
 * Take go.uams.edu out of the domain list.
 */
function slUnlistShortLinkHost(): void
{
    $record = Entry::find((string) RedirectDomainList::idFor(ShortCode::HOST));
    Assert::assertInstanceOf(Statamic\Entries\Entry::class, $record);
    $record->delete();
    RedirectDomainRecords::reload();

    expect(RedirectDomainList::idFor(ShortCode::HOST))->toBeNull();
}

it('registers the create short links permission', function (): void {
    expect(Permission::get(MarketingRedirectController::SHORT_LINK_PERMISSION))->not->toBeNull();
});

it('draws a 6-character code from the restricted alphabet', function (): void {
    $response = slDraw(slMaker());

    $response->assertOk();

    /** @var string $code */
    $code = $response->json('code');

    expect(ShortCode::isCode($code))->toBeTrue()
        ->and($code)->toMatch('/\A[a-hjkmnp-z2-9]{6}\z/');
});

it('saves a drawn code on go.uams.edu with its submitter, approved and serving when the destination is trusted', function (): void {
    $maker = slMaker();

    /** @var string $code */
    $code = slDraw($maker)->json('code');

    $response = slSubmit($maker, ['source' => $code, 'requester' => 'Communications']);

    $response->assertCreated()
        ->assertJsonPath('saved', true)
        ->assertJsonPath('message', 'Short link created. It serves at go.uams.edu/'.$code.'.');

    /** @var string $id */
    $id = $response->json('redirect.id');

    expect(mrdField($id, 'host'))->toBe(RedirectDomainRecords::idFor(ShortCode::HOST))
        ->and(mrdField($id, 'source'))->toBe('/'.$code)
        ->and(mrdField($id, 'status'))->toBe('approved')
        ->and(mrdField($id, 'submitted_by'))->toBe($maker->id())
        ->and(mrdField($id, 'requester'))->toBe('Communications')
        ->and(mrdField($id, BackHalf::FIELD))->toBeTrue();

    $served = mrdServe('https://go.uams.edu/'.$code);

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu');
});

it('draws a code when the source is left blank', function (): void {
    slGenerator('ab', 6, 5);

    $response = slSubmit(slMaker(), ['source' => '  ']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toMatch('/\A\/[ab]{6}\z/');
});

it('trusts each trusted host: the application host and its subdomains, the sites and the domain list', function (string $destination): void {
    slSubmit(slMaker(), ['destination' => $destination])
        ->assertCreated()
        ->assertJsonPath('redirect.status', 'approved');
})->with([
    'the application host' => ['https://uams.edu/flu'],
    'a subdomain' => ['https://news.uams.edu/flu'],
    'a domain list host' => ['https://uams.health/flu'],
]);

it('saves a code for any other destination as pending, and serves it only once approved', function (): void {
    $response = slSubmit(slMaker(), ['destination' => 'https://example.org/campaign']);

    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');
    /** @var string $source */
    $source = $response->json('redirect.source');

    expect($response->json('message'))->toBe('Short link submitted for review, because its destination is not on a UAMS domain. It serves at go.uams.edu'.$source.' once an approver approves it.')
        ->and(mrdField($id, 'status'))->toBe('pending')
        ->and(mrdServe('https://go.uams.edu'.$source)->getStatusCode())->toBe(404);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    $served = mrdServe('https://go.uams.edu'.$source);

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe('https://example.org/campaign');
});

it('does not serve a code whose validity window has ended', function (): void {
    $response = slSubmit(slMaker(), ['valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']);

    $response->assertCreated()->assertJsonPath('redirect.status', 'approved');

    /** @var string $source */
    $source = $response->json('redirect.source');

    expect(mrdServe('https://go.uams.edu'.$source)->getStatusCode())->toBe(404);
});

it('draws again rather than reissue a source on the domain, imported, vanity, pending or expired', function (array $row): void {
    // Over a two-letter alphabet with `a` taken, every draw is `b`.
    slGenerator('ab', 1, 200);
    /** @var array<string, mixed> $row */
    mrdSeed(['host' => ShortCode::HOST, 'target_url' => 'https://example.org/', ...$row]);

    for ($draw = 0; $draw < 20; $draw++) {
        expect(slDraw(slMaker())->json('code'))->toBe('b');
    }

    expect(slSubmit(slMaker())->json('redirect.source'))->toBe('/b');
})->with([
    'an imported rule with no status' => [['source' => '/a']],
    'a vanity path in another case' => [['source' => '/A', 'status' => 'approved']],
    'a pending code' => [['source' => '/a', 'status' => 'pending']],
    'a rule whose window has ended' => [['source' => '/a', 'status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('refuses a typed code another rule on the domain has issued, in any case or window', function (): void {
    mrdSeed(['host' => ShortCode::HOST, 'source' => '/ABC234', 'target_url' => 'https://example.org/', 'valid_until' => '2020-01-31']);

    slSubmit(slMaker(), ['source' => 'abc234'])
        ->assertStatus(422)
        ->assertJsonPath('errors.source.0', 'The code abc234 is already in use on go.uams.edu. Generate another.');
});

it('allows a code a rejected request named, since a rejected request never served', function (): void {
    mrdSeed(['host' => ShortCode::HOST, 'source' => '/abc234', 'target_url' => 'https://example.org/', 'status' => 'rejected']);

    slSubmit(slMaker(), ['source' => '/abc234'])->assertCreated()->assertJsonPath('redirect.source', '/abc234');
});

it('refuses a source that is not a short code', function (string $source): void {
    slSubmit(slMaker(), ['source' => $source])
        ->assertStatus(422)
        ->assertJsonPath('errors.source.0', "A short link's path is a generated code. Use Generate code to get one.");
})->with([
    'a vanity path' => ['/flu-shot'],
    'too short' => ['abc23'],
    'too long' => ['abc2345'],
    'a look-alike' => ['abc0de'],
    'uppercase' => ['ABC234'],
]);

it('reports running out of draws instead of looping', function (): void {
    slGenerator('ab', 1, 3);
    mrdSeed(['host' => ShortCode::HOST, 'source' => '/a', 'target_url' => 'https://example.org/']);
    mrdSeed(['host' => ShortCode::HOST, 'source' => '/b', 'target_url' => 'https://example.org/']);

    slDraw(slMaker())->assertStatus(422)->assertJsonPath('errors.source.0', fn (string $message): bool => str_contains($message, 'after 3 attempts'));
    slSubmit(slMaker())->assertStatus(422)->assertJsonPath('errors.source.0', fn (string $message): bool => str_contains($message, 'after 3 attempts'));
});

it('refuses a short link on any other domain', function (): void {
    slSubmit(slMaker(), ['host' => 'uams.info'])
        ->assertStatus(422)
        ->assertJsonPath('errors.host.0', 'A short link is made on go.uams.edu.');

    slSubmit(slMaker(), ['host' => ' GO.UAMS.EDU '])->assertCreated();
});

it('refuses a short link while go.uams.edu is not in the domain list', function (): void {
    slUnlistShortLinkHost();

    slDraw(slMaker())->assertStatus(422)->assertJsonPath('errors.host.0', 'go.uams.edu is not a redirect domain.');
    slSubmit(slMaker())->assertStatus(422)->assertJsonPath('errors.host.0', 'go.uams.edu is not a redirect domain.');
});

it('refuses an inverted window', function (): void {
    slSubmit(slMaker(), ['valid_from' => '2030-02-01', 'valid_until' => '2030-01-01'])
        ->assertStatus(422)
        ->assertJsonPath('errors.valid_until.0', 'The "valid until" date must be on or after the "valid from" date.');
});

it('validates the fields of a short link', function (): void {
    slSubmit(slMaker(), ['destination' => '', 'response' => '307'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['destination', 'response']);
});

it('keeps the action and the save from a user without create short links', function (array $permissions): void {
    /** @var list<string> $permissions */
    $user = mrdUser($permissions);

    slDraw($user)->assertForbidden();
    slSubmit($user)->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
})->with([
    'a redirect creator' => [[MarketingRedirectController::PERMISSION]],
    'an approver' => [[MarketingRedirectController::APPROVE_PERMISSION]],
    'no redirect permission' => [[]],
]);

it('offers no route that makes a code without signing in', function (): void {
    test()->postJson(mrdRoute('marketing-redirects.short-code'))->assertUnauthorized();
    test()->postJson(mrdRoute('marketing-redirects.store'), ['short_link' => true, 'destination' => 'https://uams.edu/', 'response' => '301'])->assertUnauthorized();

    expect(mrdEntryCount())->toBe(0);
});

it('keeps an ordinary path on go.uams.edu from a short-link-only user', function (): void {
    mrdSubmit(slMaker(), ['host' => 'go.uams.edu', 'source' => '/typed'])->assertForbidden();
});

it('keeps vanity-path creation on go.uams.edu as it was for a redirect creator', function (): void {
    $response = mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'source' => '/typed-path', 'destination' => 'https://www.uams.edu/flu']);

    $response->assertCreated()->assertJsonPath('redirect.status', 'pending');

    mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'source' => '', 'destination' => 'https://www.uams.edu/flu'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source']);
});

it('opens the create screen to a short-link-only user, with the short-link domain', function (): void {
    test()->actingAs(slMaker());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('shortLinkHost', ShortCode::HOST)
            ->where('canCreateRedirects', false)
            ->where('shortCodeUrl', mrdRoute('marketing-redirects.short-code')));
});

it('offers a redirect creator no short-link domain without the permission', function (): void {
    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('shortLinkHost', null)
            ->where('canCreateRedirects', true));
});

it('offers no short-link domain while go.uams.edu is not in the domain list', function (): void {
    slUnlistShortLinkHost();

    test()->actingAs(mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::SHORT_LINK_PERMISSION]));

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('shortLinkHost', null));
});

it('keeps the create screen from a user with neither permission', function (): void {
    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.create'))->assertForbidden();
});

it('reads a typed code without its surrounding whitespace or leading slash', function (): void {
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    slSubmit(slMaker(), ['source' => ' /abc234 '])->assertCreated()->assertJsonPath('redirect.source', '/abc234');
});

it('refuses to draw with a generator binding that is not a code generator', function (): void {
    app()->bind(ShortCode::GENERATOR, static fn (): stdClass => new stdClass);
    test()->withoutExceptionHandling();

    expect(fn (): TestResponse => slDraw(slMaker()))
        ->toThrow(LogicException::class, 'The "aether-redirects.short-code-generator" binding must resolve to a '.RandomCode::class.'.');
});

it('asks a short link request the Creative Services question too, and accepts either answer', function (): void {
    slSubmit(slMaker(), ['creative_services' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('creative_services');

    slSubmit(slMaker(), ['creative_services' => 'no'])->assertCreated();

    $response = slSubmit(slMaker(), ['creative_services' => 'yes', 'job_number' => '13579']);

    $response->assertCreated();
    expect($response->json('redirect.creative_services'))->toBeTrue()
        ->and($response->json('redirect.job_number'))->toBe('13579');
});

it('does not apply the vanity URL refusal to a short link that names a vanity domain', function (): void {
    $response = slSubmit(slMaker(), ['host' => 'uams.info', 'creative_services' => 'no']);

    $response->assertUnprocessable();
    expect($response->json('errors.creative_services'))->toBeNull()
        ->and($response->json('errors.host.0'))->toBe('A short link is made on go.uams.edu.');
});
