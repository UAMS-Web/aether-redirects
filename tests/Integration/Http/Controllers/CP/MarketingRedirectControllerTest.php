<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\Facades\Antlers;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Globals\Variables;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

/**
 * HTTP coverage for {@see MarketingRedirectController}
 * (UAMS-Web/uams-statamic-archive#1355 + UAMS-Web/uams-statamic-archive#1356): the create screen renders, a staff-created redirect serves on
 * a 404, collisions within an overlapping validity window are rejected, and every
 * endpoint is gated by the `create marketing redirects` permission.
 */
// Stache-isolated: every Stache store, users included, is re-rooted into this
// process's throwaway directory, and roles go to a per-test path below.
uses(IsolatesStache::class);

/**
 * Resolve a Control Panel route to its URL string.
 *
 * `cp_route()` is declared without a return type, so static analysis widens it
 * to `mixed`; this typed wrapper restores the `string` a CP route always yields.
 *
 * @param  array<string, mixed>  $params
 */
function marketingRedirectCpRoute(string $route, array $params = []): string
{
    /** @var string $url */
    $url = cp_route($route, $params);

    return $url;
}

/**
 * The default site's handle. `Site::default()` is typed `mixed` by the facade,
 * so narrow it to the concrete site before reading its `string` handle.
 */
function marketingRedirectDefaultSiteHandle(): string
{
    /** @var Statamic\Sites\Site $site */
    $site = Site::default();

    /** @var string $handle */
    $handle = $site->handle();

    return $handle;
}

/**
 * Persist a super user (holds every permission) for an authorized request.
 */
function marketingRedirectSuperUser(): FileStatamicUser
{
    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->id((string) Str::uuid());
    $user->email('marketing-redirect-super@example.test');
    $user->makeSuper();
    $user->save();

    return $user;
}

/**
 * Persist a plain user that lacks the marketing-redirect permission.
 */
function marketingRedirectPlainUser(): FileStatamicUser
{
    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->id((string) Str::uuid());
    $user->email('marketing-redirect-plain@example.test');
    $user->save();

    return $user;
}

/**
 * Persist a user granted only `create marketing redirects` (not `approve …`),
 * to prove the two permissions are independent (UAMS-Web/uams-statamic-archive#1357).
 */
function marketingRedirectCreatorOnlyUser(): FileStatamicUser
{
    $role = Role::make('marketing-redirect-creator');
    $role->permissions(['access cp', 'create marketing redirects']);
    $role->save();

    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->id((string) Str::uuid());
    $user->email('marketing-redirect-creator-'.Str::uuid().'@example.test');
    $user->assignRole('marketing-redirect-creator');
    $user->save();

    return $user;
}

/**
 * Store one pending redirect as the given user and return its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function marketingRedirectStorePending(FileStatamicUser $creator, array $overrides = []): string
{
    test()->actingAs($creator);

    $response = test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), array_merge([
        'source' => '/vanity-'.Str::uuid(),
        'destination' => '/target',
        'response' => '301',
    ], $overrides));

    $response->assertStatus(201);

    /** @var string $id */
    $id = $response->json('redirect.id');

    return $id;
}

/**
 * Seed one existing redirect on the shared default-site list via the store.
 *
 * @param  array<string, mixed>  $overrides
 */
function marketingRedirectSeed(array $overrides = []): void
{
    /** @var array{source: string, destination: string, response?: string, valid_from?: string|null, valid_until?: string|null, requester?: string|null, notes?: string|null} $input */
    $input = array_merge([
        'source' => '/flu',
        'destination' => '/existing',
    ], $overrides);

    app(MarketingRedirectStore::class)->create(MarketingRedirect::fromInput($input));
}

beforeEach(function (): void {
    // Several tests sign in more than one user, which Statamic allows only on Pro,
    // the edition the host runs
    config()->set('statamic.editions.pro', true);

    test()->withoutMiddleware(PreventRequestForgery::class);

    // The create screen renders the CP layout, which @vites the CP bundle; stub CP
    // assets so the controller response is what's under test.
    test()->withoutVite();

    // Repoint the file role repository at a throwaway path so persisting a test
    // role never writes into the Testbench skeleton's roles file.
    $rolesDir = storage_path('framework/testing/marketing-redirect-roles/'.uniqid('', true));
    File::ensureDirectoryExists($rolesDir);
    app(RoleRepositoryContract::class)->path($rolesDir.'/roles.yaml');

    // Provide the shared redirects global the utility writes into.
    /** @var Statamic\Globals\GlobalSet $set */
    $set = GlobalSet::make('redirects');
    $set->title('Redirects');
    $set->save();
    /** @var Variables $vars */
    $vars = $set->makeLocalization(marketingRedirectDefaultSiteHandle());
    $vars->data(['redirects' => []]);
    $vars->save();
});

it('renders the create screen for an authorized user', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->get(marketingRedirectCpRoute('marketing-redirects.create'))->assertOk();
});

/**
 * Render the redirect for `$uri` through the same {{ find_redirect_rule }} tag
 * the 404 template uses, on the given site.
 */
function marketingRedirectServed(string $uri, string $siteHandle): string
{
    return (string) Antlers::parse(
        '{{ find_redirect_rule in="'.$siteHandle.'" }}{{ target_url }}|{{ response }}{{ /find_redirect_rule }}',
        ['current_uri' => $uri],
        // Trusted: parsed as a template rather than user content, so no content-tag
        // allow-list a host configures can refuse the tag.
        true,
    );
}

it('creates a pending redirect that does NOT serve until an approver approves it', function (): void {
    // A distinct creator and approver — the creator cannot approve their own (UAMS-Web/uams-statamic-archive#1357).
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => 'Flu-Shot/',
        'destination' => '/clinics/flu',
        'response' => '301',
    ])
        ->assertStatus(201)
        ->assertJsonPath('saved', true)
        ->assertJsonPath('redirect.source', '/Flu-Shot')
        ->assertJsonPath('redirect.target_url', '/clinics/flu')
        ->assertJsonPath('redirect.response', '301')
        // The created redirect enters the queue as pending (UAMS-Web/uams-statamic-archive#1357).
        ->assertJsonPath('redirect.status', 'pending');

    // While pending it does NOT serve — the core of the approval gate.
    expect(marketingRedirectServed('/Flu-Shot', marketingRedirectDefaultSiteHandle()))->not->toContain('/clinics/flu');

    // A different approver promotes it, and then it serves through the tag on the
    // default site and (via the UAMS-Web/uams-statamic-archive#1110 read-time merge) on a non-default site too.
    $store = app(MarketingRedirectStore::class);
    $id = $store->all()[0]['id'];

    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertOk()
        ->assertJsonPath('saved', true)
        ->assertJsonStructure(['saved', 'message', 'redirect'])
        ->assertJsonPath('redirect.status', 'approved');

    $default = marketingRedirectDefaultSiteHandle();
    /** @var Collection<int|string, Statamic\Sites\Site> $sites */
    $sites = Site::all();
    /** @var array<int|string, string> $handles */
    $handles = $sites->map(fn (Statamic\Sites\Site $site) => $site->handle())->all();
    $other = collect($handles)
        ->first(fn (string $handle): bool => $handle !== $default) ?? $default;

    foreach ([$default, $other] as $siteHandle) {
        expect(marketingRedirectServed('/Flu-Shot', $siteHandle))->toBe('/clinics/flu|301');
    }
});

it('retains a rejected redirect with its reason and never serves it', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/gone',
        'destination' => '/nowhere',
        'response' => '301',
    ])->assertStatus(201);

    $store = app(MarketingRedirectStore::class);
    /** @var string $id */
    $id = $store->all()[0]['id'];

    // A different approver rejects the request.
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), [
        'reason' => 'Destination is off-domain.',
    ])
        ->assertOk()
        ->assertJsonPath('saved', true)
        ->assertJsonStructure(['saved', 'message', 'redirect'])
        ->assertJsonPath('redirect.status', 'rejected')
        ->assertJsonPath('redirect.rejection_reason', 'Destination is off-domain.');

    // A rejected redirect is retained but never serves.
    /** @var array{rejection_reason: string} $rejected */
    $rejected = $store->find($id);
    expect($rejected['rejection_reason'])->toBe('Destination is off-domain.')
        ->and(marketingRedirectServed('/gone', marketingRedirectDefaultSiteHandle()))->not->toContain('/nowhere');
});

it('stores the redirect on the shared default site so it serves on every site', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '302',
    ])->assertStatus(201);

    // The shared default-site list (served everywhere via UAMS-Web/uams-statamic-archive#1110) holds the row.
    $stored = app(MarketingRedirectStore::class)->all();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]['source'])->toBe('/vanity')
        ->and($stored[0]['target_url'])->toBe('/target')
        ->and($stored[0]['response'])->toBe('302')
        ->and($stored[0]['enabled'])->toBeTrue();
});

it('captures the optional validity window and requester metadata', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/campaign',
        'destination' => '/promo',
        'response' => '301',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-06-30',
        'requester' => 'Marketing',
        'notes' => 'summer push',
    ])->assertStatus(201);

    $stored = app(MarketingRedirectStore::class)->all();

    expect($stored[0]['valid_from'])->toBe('2026-06-01')
        ->and($stored[0]['valid_until'])->toBe('2026-06-30')
        ->and($stored[0]['requester'])->toBe('Marketing')
        ->and($stored[0]['notes'])->toBe('summer push');
});

it('rejects a source collision within an overlapping validity window', function (): void {
    marketingRedirectSeed(['source' => '/flu', 'valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);

    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/Flu/',
        'destination' => '/new-place',
        'response' => '301',
        'valid_from' => '2026-01-15',
        'valid_until' => '2026-02-15',
    ])
        ->assertStatus(422)
        // The message must NAME the conflicting redirect (AC UAMS-Web/uams-statamic-archive#1356), not just flag an error.
        ->assertJsonValidationErrors(['source' => 'A redirect from /flu to /existing']);

    // The colliding redirect was not written.
    expect(app(MarketingRedirectStore::class)->all())->toHaveCount(1);
});

it('lets a source be re-requested after its earlier request was rejected', function (): void {
    // Submit /promo, then a different approver rejects it (row retained, never serves).
    $id = marketingRedirectStorePending(marketingRedirectSuperUser(), ['source' => '/promo', 'destination' => '/campaign-a']);

    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'Wrong destination.'])->assertOk();

    // The corrected request for the same source must succeed — a rejected row
    // never serves, so it must not reserve the source (UAMS-Web/uams-statamic-archive#1357). Regression for the
    // collision-detector-is-status-blind defect.
    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/promo',
        'destination' => '/campaign-b',
        'response' => '301',
    ])->assertStatus(201);
});

it('still blocks re-requesting a source that already has an approved redirect', function (): void {
    // Submit /vip, then a different approver approves it (it now serves).
    $id = marketingRedirectStorePending(marketingRedirectSuperUser(), ['source' => '/vip', 'destination' => '/a']);

    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    // A second request for the same source collides — an approved redirect serves.
    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vip',
        'destination' => '/b',
        'response' => '301',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');
});

it('allows the same source with a non-overlapping validity window', function (): void {
    marketingRedirectSeed(['source' => '/flu', 'valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);

    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/flu',
        'destination' => '/february-place',
        'response' => '301',
        'valid_from' => '2026-02-01',
        'valid_until' => '2026-02-28',
    ])->assertStatus(201);

    expect(app(MarketingRedirectStore::class)->all())->toHaveCount(2);
});

it('rejects an inverted validity window', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
        'valid_from' => '2026-06-30',
        'valid_until' => '2026-06-01',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('valid_until');
});

it('validates the required and constrained fields', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '',
        'destination' => '',
        'response' => '418',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source', 'destination', 'response']);
});

it('denies the create screen to users without the permission', function (): void {
    test()->actingAs(marketingRedirectPlainUser());

    // A web (non-JSON) CP request for an unauthorized user is redirected away
    // rather than rendered; the authoritative 403 gate is asserted on the JSON
    // store endpoint below.
    test()->get(marketingRedirectCpRoute('marketing-redirects.create'))->assertStatus(302);
});

it('forbids storing a redirect for users without the permission', function (): void {
    test()->actingAs(marketingRedirectPlainUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
    ])->assertForbidden();

    // Nothing was written.
    expect(app(MarketingRedirectStore::class)->all())->toBeEmpty();
});

it('renders the review queue for an approver', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->get(marketingRedirectCpRoute('marketing-redirects.review'))->assertOk();
});

it('forbids a creator from approving their own request (no self-approve)', function (): void {
    // One user both creates and tries to approve — the guard must forbid it.
    $creator = marketingRedirectSuperUser();
    $id = marketingRedirectStorePending($creator);

    test()->actingAs($creator);

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertStatus(403)
        ->assertJsonStructure(['message']);

    // The request stays pending — the self-approve did not take effect.
    /** @var array{status: string} $found */
    $found = app(MarketingRedirectStore::class)->find($id);
    expect($found['status'])->toBe('pending');
});

it('forbids a creator from rejecting their own request', function (): void {
    $creator = marketingRedirectSuperUser();
    $id = marketingRedirectStorePending($creator);

    test()->actingAs($creator);

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'nope'])
        ->assertStatus(403);

    /** @var array{status: string} $found */
    $found = app(MarketingRedirectStore::class)->find($id);
    expect($found['status'])->toBe('pending');
});

it('rejects a second review of an already-reviewed request', function (): void {
    $id = marketingRedirectStorePending(marketingRedirectSuperUser());

    // A different approver approves it.
    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    // A further approve or reject is a no-op 422 — it was already reviewed.
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status' => 'already been reviewed']);

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'late'])
        ->assertStatus(422);
});

it('returns 404 when approving or rejecting an unknown id', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => 'does-not-exist']))
        ->assertStatus(404)
        ->assertJsonStructure(['message']);

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => 'does-not-exist']), ['reason' => 'x'])
        ->assertStatus(404)
        ->assertJsonStructure(['message']);
});

it('requires a reason to reject', function (): void {
    $id = marketingRedirectStorePending(marketingRedirectSuperUser());

    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    // Still pending — the invalid rejection did not take effect.
    /** @var array{status: string} $found */
    $found = app(MarketingRedirectStore::class)->find($id);
    expect($found['status'])->toBe('pending');
});

it('separates the create and approve permissions', function (): void {
    $creatorOnly = marketingRedirectCreatorOnlyUser();

    // A creator-only user CAN create a request…
    $ownId = marketingRedirectStorePending($creatorOnly);

    // …but CANNOT reach the review queue…
    test()->actingAs($creatorOnly);
    test()->get(marketingRedirectCpRoute('marketing-redirects.review'))->assertStatus(302);

    // …nor approve/reject a request submitted by someone ELSE. Using another
    // user's request is deliberate: it isolates the permission gate from the
    // self-review guard, so a dropped `authorize()` cannot pass unnoticed.
    $otherId = marketingRedirectStorePending(marketingRedirectSuperUser());
    test()->actingAs($creatorOnly);
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $otherId]))->assertForbidden();
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $otherId]), ['reason' => 'x'])->assertForbidden();

    /** @var array{status: string} $otherFound */
    $otherFound = app(MarketingRedirectStore::class)->find($otherId);
    /** @var array{status: string} $ownFound */
    $ownFound = app(MarketingRedirectStore::class)->find($ownId);
    expect($otherFound['status'])->toBe('pending')
        ->and($ownFound['status'])->toBe('pending');
});

it('forbids the review endpoints for a user without any redirect permission', function (): void {
    // A real pending request from an authorized user, submitted by someone the
    // plain user is NOT — so a dropped permission gate would let it through.
    $id = marketingRedirectStorePending(marketingRedirectSuperUser());

    test()->actingAs(marketingRedirectPlainUser());

    test()->get(marketingRedirectCpRoute('marketing-redirects.review'))->assertStatus(302);
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $id]))->assertForbidden();
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), ['reason' => 'x'])->assertForbidden();

    // The permission gate blocked it before any state change.
    /** @var array{status: string} $found */
    $found = app(MarketingRedirectStore::class)->find($id);
    expect($found['status'])->toBe('pending');
});

it('renders the pending queue payload with per-row action URLs and self-review flags', function (): void {
    // Two pending requests: one the viewer submitted (with a full field set),
    // one from someone else.
    $viewer = marketingRedirectSuperUser();
    $ownId = marketingRedirectStorePending($viewer, [
        'source' => '/own',
        'destination' => '/own-dest',
        'response' => '302',
        'requester' => 'Me',
        'notes' => 'Launch week',
        'valid_from' => '2026-08-01',
        'valid_until' => '2026-08-31',
    ]);
    marketingRedirectStorePending(marketingRedirectSuperUser(), ['source' => '/other', 'destination' => '/other-dest']);

    // An approved request must NOT appear in the queue.
    $approvedId = marketingRedirectStorePending(marketingRedirectSuperUser());
    test()->actingAs(marketingRedirectSuperUser());
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $approvedId]))->assertOk();

    test()->actingAs($viewer);
    test()->get(marketingRedirectCpRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
            // The page is a JS-registered Vue component, not an on-disk Inertia
            // page file, so skip the view-finder existence check (second arg).
                ->component('aether-redirects::MarketingRedirectReview', false)
                ->has('redirects', 2)
            // Row 0 is the viewer's own request: not reviewable, with its action URLs.
                ->where('redirects.0.source', '/own')
                ->where('redirects.0.destination', '/own-dest')
                ->where('redirects.0.response', '302')
                ->where('redirects.0.requester', 'Me')
                ->where('redirects.0.notes', 'Launch week')
                ->where('redirects.0.valid_from', '2026-08-01')
                ->where('redirects.0.valid_until', '2026-08-31')
                ->where('redirects.0.can_review', false)
                ->where('redirects.0.approve_url', marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $ownId]))
                ->where('redirects.0.reject_url', marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $ownId]))
            // Row 1 was submitted by someone else, so the viewer may review it.
                ->where('redirects.1.source', '/other')
                ->where('redirects.1.can_review', true)
        );
});

/**
 * Persist a user granted `access cp` and only `approve marketing redirects`,
 * to prove the create endpoints check their own permission, not just CP access.
 */
function marketingRedirectApproverOnlyUser(): FileStatamicUser
{
    $role = Role::make('marketing-redirect-approver');
    $role->permissions(['access cp', 'approve marketing redirects']);
    $role->save();

    $user = User::make();
    Assert::assertInstanceOf(FileStatamicUser::class, $user);

    $user->id((string) Str::uuid());
    $user->email('marketing-redirect-approver-'.Str::uuid().'@example.test');
    $user->assignRole('marketing-redirect-approver');
    $user->save();

    return $user;
}

it('forbids the create screen and the store endpoint to a CP user holding only the approve permission', function (): void {
    test()->actingAs(marketingRedirectApproverOnlyUser());

    test()->getJson(marketingRedirectCpRoute('marketing-redirects.create'))->assertForbidden();

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
    ])->assertForbidden();

    // Nothing was written.
    expect(app(MarketingRedirectStore::class)->all())->toBeEmpty();
});

it('hands the create screen its store URL and the two redirect-type options', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->get(marketingRedirectCpRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('aether-redirects::MarketingRedirectCreate', false)
                ->where('storeUrl', marketingRedirectCpRoute('marketing-redirects.store'))
                ->where('responseOptions', [
                    ['value' => '301', 'label' => '301 Moved Permanently'],
                    ['value' => '302', 'label' => '302 Found'],
                ])
        );
});

it('reports a saved request with its review message', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
    ])
        ->assertStatus(201)
        ->assertJsonPath('saved', true)
        ->assertJsonPath('message', 'Redirect submitted for review. It serves on a 404 for every site once an approver approves it.');
});

it('records the reviewing user on an approval and a rejection, and trims the rejection reason', function (): void {
    $creator = marketingRedirectSuperUser();
    $approvedId = marketingRedirectStorePending($creator);
    $rejectedId = marketingRedirectStorePending($creator);

    $reviewer = marketingRedirectSuperUser();
    test()->actingAs($reviewer);

    // The controller trims the reason itself, so take away both the framework's
    // trim and the Control Panel's own
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class]);

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.approve', ['redirect' => $approvedId]))->assertOk();
    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $rejectedId]), [
        'reason' => "  Wrong destination  \n",
    ])->assertOk();

    /** @var array<string, mixed> $approved */
    $approved = app(MarketingRedirectStore::class)->find($approvedId);
    /** @var array<string, mixed> $rejected */
    $rejected = app(MarketingRedirectStore::class)->find($rejectedId);

    expect($approved['reviewed_by'])->toBe($reviewer->id())
        ->and($rejected['reviewed_by'])->toBe($reviewer->id())
        ->and($rejected['rejection_reason'])->toBe('Wrong destination');
});

it('refuses a rejection whose reason breaks a rule, leaving the request pending', function (mixed $reason): void {
    $id = marketingRedirectStorePending(marketingRedirectSuperUser());
    test()->actingAs(marketingRedirectSuperUser());

    $payload = $reason === 'MISSING' ? [] : ['reason' => $reason];

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.reject', ['redirect' => $id]), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    /** @var array{status: string} $found */
    $found = app(MarketingRedirectStore::class)->find($id);
    expect($found['status'])->toBe('pending');
})->with([
    'missing (required)' => ['MISSING'],
    'not a string' => [['a reason']],
    'longer than 2048 characters' => [str_repeat('r', 2049)],
]);

it('refuses a submission that breaks one field rule, naming that field', function (string $field, mixed $value): void {
    test()->actingAs(marketingRedirectSuperUser());

    $payload = ['source' => '/vanity', 'destination' => '/target', 'response' => '301'];

    // Replace the field, or drop it when the case is its absence
    if ($value === 'MISSING') {
        unset($payload[$field]);
    } else {
        $payload[$field] = $value;
    }

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(app(MarketingRedirectStore::class)->all())->toBeEmpty();
})->with([
    'source missing' => ['source', 'MISSING'],
    'source not a string' => ['source', ['/vanity']],
    'source too long' => ['source', '/'.str_repeat('s', 2048)],
    'destination missing' => ['destination', 'MISSING'],
    'destination not a string' => ['destination', ['/target']],
    'destination too long' => ['destination', '/'.str_repeat('d', 2048)],
    'response missing' => ['response', 'MISSING'],
    'response not a string' => ['response', ['301']],
    'response sent as an integer' => ['response', 301],
    'response not an allowed code' => ['response', '307'],
    'valid_from not a date' => ['valid_from', 'not-a-date'],
    'valid_until not a date' => ['valid_until', 'not-a-date'],
    'requester not a string' => ['requester', ['Me']],
    'requester too long' => ['requester', str_repeat('q', 256)],
    'notes not a string' => ['notes', ['note']],
    'notes too long' => ['notes', str_repeat('n', 2049)],
]);

it('accepts every optional field sent as null', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
        'valid_from' => null,
        'valid_until' => null,
        'requester' => null,
        'notes' => null,
    ])->assertStatus(201);
});

it('accepts a window bounded on one side only', function (array $window): void {
    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), array_merge([
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
    ], $window))->assertStatus(201);
})->with([
    'from only, in the future' => [['valid_from' => '2099-01-01']],
    'until only, in the past' => [['valid_until' => '2000-01-01']],
]);

it('reports an inverted window with a message as well as the field error', function (): void {
    test()->actingAs(marketingRedirectSuperUser());

    $message = 'The "valid until" date must be on or after the "valid from" date.';

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/vanity',
        'destination' => '/target',
        'response' => '301',
        'valid_from' => '2026-06-30',
        'valid_until' => '2026-06-01',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', $message)
        ->assertJsonPath('errors.valid_until', [$message]);
});

it('names a conflicting redirect that has no destination by its source alone', function (array $target): void {
    // A legacy row on the same source, with an empty or absent destination
    app(MarketingRedirectStore::class)->appendMany([
        array_merge(['id' => 'legacy-1', 'type' => 'redirect', 'source' => '/flu'], $target),
    ]);

    test()->actingAs(marketingRedirectSuperUser());

    test()->postJson(marketingRedirectCpRoute('marketing-redirects.store'), [
        'source' => '/flu',
        'destination' => '/target',
        'response' => '301',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A redirect from /flu already exists within an overlapping validity window.');
})->with([
    'empty destination' => [['target_url' => '']],
    'no destination' => [[]],
]);

it('fills the display fields of a pending row that lacks them with empty strings', function (): void {
    app(MarketingRedirectStore::class)->appendMany([
        ['id' => 'bare-1', 'type' => 'redirect', 'status' => 'pending'],
    ]);

    test()->actingAs(marketingRedirectSuperUser());

    test()->get(marketingRedirectCpRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('aether-redirects::MarketingRedirectReview', false)
                ->has('redirects', 1)
                ->where('redirects.0.id', 'bare-1')
                ->where('redirects.0.source', '')
                ->where('redirects.0.destination', '')
                ->where('redirects.0.response', '301')
        );
});
