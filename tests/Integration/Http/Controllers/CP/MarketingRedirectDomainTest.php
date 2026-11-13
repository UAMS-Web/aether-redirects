<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use Statamic\Sites\Site;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;
use UAMSWeb\Aether\Redirects\Tests\Support\RedirectDomainRecords;

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

/**
 * HTTP coverage for the approval workflow on the `redirect_domains` collection
 * (UAMS-Web/aether-redirects#83): a request for a vanity domain is an entry that
 * does not serve until an approver approves it, domains are chosen to suit the
 * destination, and collisions are scoped to the domain with a 12-week reuse buffer.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/MarketingRedirectDomainTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    mrdSetUp();
});

it('saves a request as a pending entry that does not serve until an approver approves it', function (): void {
    $creator = mrdCreator();

    $response = mrdSubmit($creator, ['source' => '/flu', 'requester' => 'Communications']);

    $response->assertCreated()
        ->assertJsonPath('message', 'Redirect submitted for review. It serves on uams.info once an approver approves it.');

    expect($response->json('redirect.status'))->toBe('pending');

    /** @var string $id */
    $id = $response->json('redirect.id');
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->collectionHandle())->toBe('redirect_domains')
        ->and($entry->get('host'))->toBe(RedirectDomainRecords::idFor('uams.info'))
        ->and($entry->get('source'))->toBe('/flu')
        ->and($entry->get('status'))->toBe('pending')
        ->and($entry->get('submitted_by'))->toBe($creator->id())
        ->and($entry->get('requester'))->toBe('Communications');

    // Pending: the domain is known but the rule is withheld, so the application answers 404
    expect(mrdServe('https://uams.info/flu')->getStatusCode())->toBe(404)
        ->and(mrdManifestRuleCount('uams.info'))->toBe(0);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertOk()
        ->assertJsonPath('message', 'Redirect approved. It now serves on its domain.');

    expect(mrdManifestRuleCount('uams.info'))->toBe(1);

    $served = mrdServe('https://uams.info/flu');

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe('https://www.uams.edu/clinics/flu')
        ->and(mrdField($id, 'status'))->toBe('approved');
});

it('forbids the creator approving their own request on a domain', function (): void {
    $user = mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]);

    /** @var string $id */
    $id = mrdSubmit($user)->json('redirect.id');

    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertForbidden();

    expect(mrdField($id, 'status'))->toBe('pending');
});

it('retains a rejected request with its reason and never serves it', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/nope']);
    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'Not a marketing piece'])->assertOk();

    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->get('status'))->toBe('rejected')
        ->and($entry->get('rejection_reason'))->toBe('Not a marketing piece')
        ->and(mrdServe('https://uams.info/nope')->getStatusCode())->toBe(404);

    // A rejected request does not reserve its path
    mrdSubmit(mrdCreator(), ['source' => '/nope'])->assertCreated();
});

it('lists pending domain requests in the review queue with their domain', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/queued'])->assertCreated();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 1)
            ->where('redirects.0.host', 'uams.info')
            ->where('redirects.0.source', '/queued'));
});

it('refuses a request from a user without the create permission', function (): void {
    mrdSubmit(mrdApprover())->assertForbidden();

    expect(mrdEntryCount())->toBe(0);
});

it('keeps serving an entry that has no status', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/old', 'target_url' => 'https://www.uams.edu/old']);

    expect(mrdServe('https://uams.info/old')->getStatusCode())->toBe(301)
        ->and(mrdManifestRuleCount('uams.info'))->toBe(1);
});

it('accepts the domain each destination calls for', function (string $host, string $destination): void {
    mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => $destination])->assertCreated();
})->with([
    'uams.edu page on uams.info' => ['uams.info', 'https://www.uams.edu/news'],
    'uams.edu subdomain on uams.info' => ['uams.info', 'https://research.uams.edu/'],
    'uamshealth.com page on uams.health' => ['uams.health', 'https://uamshealth.com/clinics'],
    'uamshealth.com page on uamsbaptist.health' => ['uamsbaptist.health', 'https://www.uamshealth.com/baptist'],
]);

it('refuses a mismatched domain from a creator and allows it from an approver', function (string $host, string $destination): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => $destination]);

    $response->assertStatus(422);

    expect($response->json('errors.host.0'))->toBe($host.' does not serve this destination. An approver can save the exception.');

    mrdSubmit(mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]), ['host' => $host, 'destination' => $destination])
        ->assertCreated();
})->with([
    'uamshealth.com page on uams.info' => ['uams.info', 'https://uamshealth.com/clinics'],
    'uams.edu page on uams.health' => ['uams.health', 'https://www.uams.edu/news'],
    'uams.edu page on uamsbaptist.health' => ['uamsbaptist.health', 'https://www.uams.edu/news'],
    'outside page on uams.info' => ['uams.info', 'https://example.org/'],
    'relative destination on uams.info' => ['uams.info', '/clinics'],
]);

it('lets a super user save a mismatched domain, since a super user holds every permission', function (): void {
    $user = mrdCreator();
    $user->makeSuper();
    $user->save();

    mrdSubmit($user, ['host' => 'uams.info', 'destination' => 'https://uamshealth.com/clinics'])->assertCreated();
});

it('refuses a domain that is not a redirect domain', function (string|array $host, string $message): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host]);

    $response->assertStatus(422);

    expect($response->json('errors.host.0'))->toBe($message)
        ->and(mrdEntryCount())->toBe(0);
})->with([
    'a valid but unknown domain' => ['example.org', 'example.org is not a redirect domain.'],
    'not a domain' => ['not a host', 'Enter a valid domain, such as uams.info.'],
    'a list' => [['uams.info'], 'Must be a string.'],
]);

it('treats a blank domain as a main-site request, whatever whitespace it carries', function (string $host): void {
    // Without the middleware that trims strings and nulls blanks, the value arrives as sent
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => $host, 'destination' => '/clinics/flu'])->assertCreated();

    expect(mrdEntryCount())->toBe(0);
})->with(['an empty string' => [''], 'spaces' => ['   ']]);

it('accepts a domain the collection already serves', function (): void {
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/existing', 'target_url' => 'https://example.org/']);

    mrdSubmit(mrdCreator(), ['host' => 'go.uams.edu', 'destination' => 'https://example.org/else'])->assertCreated();
});

it('scopes collisions to the domain', function (): void {
    mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/shared'])->assertCreated();

    // The same path on another domain is a different rule
    mrdSubmit(mrdCreator(), ['host' => 'uams.health', 'source' => '/shared', 'destination' => 'https://uamshealth.com/'])->assertCreated();

    // The same path on the same domain collides, and the message names the conflicting rule
    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/Shared']);

    $response->assertStatus(422);

    expect($response->json('errors.source.0'))->toContain('uams.info/shared');
});

it('does not collide with a main-site redirect on the same path', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/flu', 'target_url' => 'https://www.uams.edu/flu']);

    // A request with no domain is a main-site request; it never meets the domain's entries
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/flu', 'destination' => '/clinics/flu'])->assertCreated();
});

it('reserves a path on a vanity domain for 12 weeks after its last day', function (string $host, string $validFrom, int $status): void {
    mrdSeed([
        'host' => $host,
        'source' => '/campaign',
        'target_url' => 'https://www.uams.edu/old',
        'status' => 'approved',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-01-31',
    ]);

    mrdSubmit(mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]), [
        'host' => $host,
        'source' => '/campaign',
        'destination' => 'https://www.uams.edu/new',
        'valid_from' => $validFrom,
    ])->assertStatus($status);
})->with([
    'the day after it ended' => ['uams.info', '2026-02-01', 422],
    '83 days after the last day' => ['uams.info', '2026-04-24', 422],
    'exactly 12 weeks after the last day' => ['uams.info', '2026-04-25', 201],
    'a go.uams.edu path has no buffer' => ['go.uams.edu', '2026-02-01', 201],
]);

it('keeps a path with no end date reserved indefinitely', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/forever', 'target_url' => 'https://www.uams.edu/old', 'valid_from' => '2026-01-01']);

    mrdSubmit(mrdCreator(), ['source' => '/forever', 'valid_from' => '2030-01-01'])->assertStatus(422);
});

it('hands the create screen the domains a request can be made for', function (): void {
    mrdSeed(['host' => 'zz.example.org', 'source' => '/x', 'target_url' => 'https://example.org/']);
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/x', 'target_url' => 'https://example.org/']);
    mrdSeed(['host' => 'uams.health', 'source' => '/y', 'target_url' => 'https://uamshealth.com/']);

    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('hostOptions', ['go.uams.edu', 'uams.health', 'uams.info', 'uamsbaptist.health', 'zz.example.org'])
            ->where('canApprove', false));
});

it('creates a published entry on the default site that the collection titles by host and path', function (): void {
    $response = mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/Flu Shot']);
    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->locale())->toBe('default')
        ->and($entry->published())->toBeTrue()
        ->and($entry->get('title'))->toBe('uams.info/Flu Shot')
        ->and($response->json('redirect.id'))->toBe($entry->id());
});

it('approves a main-site request with the message for every site', function (): void {
    mrdMainSiteGlobal();

    /** @var string $id */
    $id = mrdSubmit(mrdCreator(), ['host' => null, 'destination' => '/clinics/flu'])->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertOk()
        ->assertJsonPath('message', 'Redirect approved. It now serves on a 404 for every site.');
});

it('reviews a domain request and a main-site request side by side', function (): void {
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/main', 'destination' => '/clinics/flu'])->assertCreated();
    mrdSubmit(mrdCreator(), ['host' => 'uams.info', 'source' => '/domain'])->assertCreated();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 2)
            ->where('redirects.0.host', null)
            ->where('redirects.1.host', 'uams.info'));
});
