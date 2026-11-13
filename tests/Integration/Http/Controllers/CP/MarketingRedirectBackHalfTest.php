<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

/**
 * HTTP coverage for the generated back-half of a vanity URL request
 * (UAMS-Web/aether-redirects#15): a request on a vanity domain with no source
 * draws 7 base-62 characters never issued on that domain in any case, and
 * approval refuses one that a live rule on the domain has issued since.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/MarketingRedirectBackHalfTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    mrdSetUp();
});

it('generates a back-half for a vanity domain request with no source, and serves it once approved', function (string $host, string $destination, array $source): void {
    // The submit helper fills in a source, so post the payload without one directly
    test()->actingAs(mrdCreator());
    $response = test()->postJson(mrdRoute('marketing-redirects.store'), [
        'creative_services' => 'yes',
        'job_number' => '12345',
        'host' => $host,
        'destination' => $destination,
        'response' => '301',
        ...$source,
    ]);

    $response->assertCreated();

    /** @var string $backHalf */
    $backHalf = $response->json('redirect.source');

    // A slash, then 7 characters from the base-62 alphabet, kept in their drawn case
    expect($backHalf)->toMatch('#^/[A-Za-z0-9]{7}$#')
        ->and($response->json('redirect.'.BackHalf::FIELD))->toBeTrue()
        ->and($response->json('message'))->toBe('Redirect submitted for review. It serves on '.$host.$backHalf.' once an approver approves it.');

    /** @var string $id */
    $id = $response->json('redirect.id');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]))->assertOk();

    $served = mrdServe('https://'.$host.$backHalf);

    expect($served->getStatusCode())->toBe(301)
        ->and($served->headers->get('Location'))->toBe($destination);
})->with([
    'uams.info' => ['uams.info', 'https://www.uams.edu/clinics/flu'],
    'uams.health' => ['uams.health', 'https://uamshealth.com/flu'],
    'uamsbaptist.health' => ['uamsbaptist.health', 'https://uamshealth.com/partnership'],
])->with([
    'source left out' => [[]],
    'source empty' => [['source' => '']],
    'source blank' => [['source' => '   ']],
    'source null' => [['source' => null]],
]);

it('marks a typed source as not generated', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/typed']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/typed')
        ->and($response->json('redirect.'.BackHalf::FIELD))->toBeFalse()
        ->and($response->json('message'))->toBe('Redirect submitted for review. It serves on uams.info once an approver approves it.');
});

it('requires a source on a domain that is not a vanity domain, and on the main site', function (?string $host): void {
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/existing', 'target_url' => 'https://example.org/']);
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => $host, 'source' => '', 'destination' => 'https://example.org/else'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect(mrdEntryCount())->toBe(1);
})->with([
    'a domain the collection serves' => ['go.uams.edu'],
    'the main site' => [null],
]);

it('redraws a back-half that a rule on the domain has issued in another case', function (array $issued): void {
    /** @var array<string, mixed> $issued */
    // Over the alphabet `ab`, every draw of `a` is taken by the `/A` rule, so only `/b` is free
    mrdGenerator('ab', 1, 50);
    mrdSeed(['host' => 'uams.info', 'source' => '/A', 'target_url' => 'https://www.uams.edu/old', ...$issued]);

    $response = mrdSubmit(mrdCreator(), ['source' => '']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toBe('/b');
})->with([
    'a live redirect' => [['status' => 'approved']],
    'a pending request' => [['status' => 'pending']],
    'a redirect with no status' => [[]],
    'a redirect whose window has ended' => [['status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('counts a rule on the domain in either case as issuing a back-half, so every draw fails', function (array $issued): void {
    /** @var array<string, mixed> $issued */
    // One draw over `ab`, with both letters issued in the other case: a check that missed either would let it through
    mrdGenerator('ab', 1, 1);
    mrdSeed(['host' => 'uams.info', 'source' => '/A', 'target_url' => 'https://www.uams.edu/old', ...$issued]);
    mrdSeed(['host' => 'uams.info', 'source' => '/b', 'target_url' => 'https://www.uams.edu/old', ...$issued]);

    mrdSubmit(mrdCreator(), ['source' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect(mrdEntryCount())->toBe(2);
})->with([
    'a live redirect' => [['status' => 'approved']],
    'a pending request' => [['status' => 'pending']],
    'a redirect with no status' => [[]],
    'a redirect whose window has ended' => [['status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('does not count a rejected request, or a rule on another domain, as issuing a back-half', function (array $taken): void {
    /** @var array<string, mixed> $taken */
    // One draw over `ab`: it succeeds only when neither `/a` nor `/b` counts as issued
    mrdGenerator('ab', 1, 1);
    mrdSeed(['host' => 'uams.info', 'source' => '/A', 'target_url' => 'https://www.uams.edu/old', ...$taken]);
    mrdSeed(['host' => 'uams.info', 'source' => '/B', 'target_url' => 'https://www.uams.edu/old', ...$taken]);

    mrdSubmit(mrdCreator(), ['source' => ''])->assertCreated();
})->with([
    'rejected requests' => [['status' => 'rejected', 'rejection_reason' => 'No']],
    'rules on another domain' => [['host' => 'uams.health', 'status' => 'approved']],
]);

it('tells the requester when every allowed draw was taken, and saves nothing', function (): void {
    mrdGenerator('ab', 1, 3);
    mrdSeed(['host' => 'uams.info', 'source' => '/a', 'target_url' => 'https://www.uams.edu/old']);
    mrdSeed(['host' => 'uams.info', 'source' => '/B', 'target_url' => 'https://www.uams.edu/old']);

    $response = mrdSubmit(mrdCreator(), ['source' => '']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect($response->json('errors.source.0'))->toBe('A unique code could not be generated after 3 attempts. Try again, and report it if this keeps happening.')
        ->and(mrdEntryCount())->toBe(2);
});

it('approves only the first of two pending back-halves that differ only in case', function (): void {
    $first = mrdGeneratedRequest('/aB3xY9q');
    $second = mrdGeneratedRequest('/ab3xy9q');

    test()->actingAs(mrdApprover());

    // The other request is only pending, so it does not block this one
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $first->id()]))->assertOk();

    $response = test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $second->id()]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect($response->json('errors.source.0'))->toBe('The back-half /ab3xy9q has already been issued on uams.info in another case. Reject this request so it can be submitted again for a new back-half.')
        ->and(mrdField(mrdId($first), 'status'))->toBe('approved')
        ->and(mrdField(mrdId($second), 'status'))->toBe('pending');

    // The refused request can still be rejected, so it can be submitted again
    test()->postJson(mrdRoute('marketing-redirects.reject', ['redirect' => $second->id()]), ['reason' => 'Back-half taken'])->assertOk();
});

it('refuses to approve a generated back-half a live rule on its domain has issued', function (array $live): void {
    /** @var array<string, mixed> $live */
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', ...$live]);
    $request = mrdGeneratedRequest('/aB3xY9q');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertStatus(422);

    expect(mrdField(mrdId($request), 'status'))->toBe('pending');
})->with([
    'an approved rule' => [['status' => 'approved']],
    'a rule with no status' => [[]],
    'an approved rule whose window has ended' => [['status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('approves a generated back-half that only a rejected request, a pending one or another domain shares', function (array $other): void {
    /** @var array<string, mixed> $other */
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', ...$other]);
    $request = mrdGeneratedRequest('/aB3xY9q');

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();

    expect(mrdField(mrdId($request), 'status'))->toBe('approved');
})->with([
    'a rejected request' => [['status' => 'rejected', 'rejection_reason' => 'No']],
    'a pending request' => [['status' => 'pending']],
    'an approved rule on another domain' => [['host' => 'uams.health', 'status' => 'approved']],
]);

it('leaves a typed source off a vanity domain to its own approval rules', function (): void {
    // A typed path on go.uams.edu is not a generated back-half, so a live rule differing in case does not block it here
    mrdSeed(['host' => 'go.uams.edu', 'source' => '/Typed', 'target_url' => 'https://example.org/old', 'status' => 'approved']);
    $request = mrdGeneratedRequest('/typed', [BackHalf::FIELD => false, 'host' => 'go.uams.edu', 'target_url' => 'https://example.org/new']);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();
});

it('tells the create screen which domains generate a back-half', function (): void {
    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('generatedHosts', ['uams.info', 'uams.health', 'uamsbaptist.health']));
});

it('generates a back-half for a whitespace source even without the middleware that trims it', function (): void {
    // Without the middleware, the spaces arrive as sent and must not become a rule for the domain's root
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    $response = mrdSubmit(mrdCreator(), ['source' => '   ']);

    $response->assertCreated();

    expect($response->json('redirect.source'))->toMatch('#^/[A-Za-z0-9]{7}$#')
        ->and($response->json('redirect.'.BackHalf::FIELD))->toBeTrue();
});

it('ignores a generated flag the requester submits', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '/typed', BackHalf::FIELD => true]);

    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('redirect.id');

    expect(mrdField($id, BackHalf::FIELD))->toBeFalse();
});

it('refuses to generate with a generator binding that is not a code generator', function (): void {
    app()->bind(BackHalf::GENERATOR, static fn (): stdClass => new stdClass);
    test()->withoutExceptionHandling();

    expect(fn (): TestResponse => mrdSubmit(mrdCreator(), ['source' => '']))
        ->toThrow(LogicException::class, 'The "aether-redirects.back-half-generator" binding must resolve to a '.RandomCode::class.'.');

    expect(mrdEntryCount())->toBe(0);
});

it('approves a flagged entry whose source is not a string, since there is nothing to compare', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', 'status' => 'approved']);
    $request = mrdGeneratedRequest('/aB3xY9q', ['source' => ['/aB3xY9q']]);

    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))->assertOk();
});

it('leaves a flagged entry that has lost its domain to the domain guard, rather than comparing it', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/AB3XY9Q', 'target_url' => 'https://www.uams.edu/old', 'status' => 'approved']);
    $request = mrdGeneratedRequest('/aB3xY9q');

    // The domain guard refuses such an entry from the Control Panel, so it is written as a hand edit would be
    $request->set('host', null);
    $request->saveQuietly();

    // The back-half re-check steps aside, so the refusal names the domain and not the back-half
    test()->actingAs(mrdApprover());
    test()->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $request->id()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['host' => 'Choose a domain from the domain list.'])
        ->assertJsonMissingValidationErrors(['source']);
});
