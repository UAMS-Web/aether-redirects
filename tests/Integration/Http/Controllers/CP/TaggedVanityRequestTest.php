<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';
require_once __DIR__.'/../../../../Support/TaggedUrlChoiceHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Services\TaggedVanityIssuer;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class, TaggedVanityIssuer::class);

/**
 * HTTP tests for a vanity request made with tagged URL choices (UAMS-Web/aether-redirects#125, part
 * of #26): the choices are checked and kept with the request, and approval composes the destination
 * of the vanity redirect and of its `-QR` redirect and freezes each.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/TaggedVanityRequestTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();
    tcSetUp();

    // A site whose parameters read the vanity URL and the QR flag the system fills
    tcParameters([
        ...tcDefaultParameters(),
        'custom_parameters' => [
            ['name' => 'utm_vanity', 'enabled' => true, 'order' => 1, 'source_type' => 'request_field', 'request_field' => 'vanity_url'],
            ['name' => 'utm_qr', 'enabled' => true, 'order' => 2, 'source_type' => 'request_field', 'request_field' => 'qr_code'],
        ],
    ]);
});

/**
 * The id of the domain entry holding a source on a host.
 */
function tvId(string $source, string $host = 'uams.info'): string
{
    foreach (app(RedirectDomainStore::class)->all() as $row) {
        if (($row['source'] ?? null) === $source && ($row['host'] ?? null) === $host && is_string($row['id'] ?? null)) {
            return $row['id'];
        }
    }

    Assert::fail('No entry for '.$source);
}

/**
 * A stored field, read after the in-memory copies are dropped.
 */
function tvStored(string $id, string $field): mixed
{
    Stache::clear();

    return mrdField($id, $field);
}

/**
 * Approve a request as an approver.
 *
 * @return TestResponse<Response>
 */
function tvApprove(string $id): TestResponse
{
    $test = mrdTest();
    $test->actingAs(mrdApprover());

    return $test->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]));
}

/**
 * The query of a stored destination.
 */
function tvQuery(mixed $url): string
{
    Assert::assertIsString($url);

    return (string) parse_url($url, PHP_URL_QUERY);
}

it('keeps the choices with a pending vanity request and leaves its typed destination alone', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/Walk-Two', 'channel' => 'ch-social', 'steps' => ['source' => 'src-facebook', 'medium' => 'med-feed']])->assertCreated();

    $id = tvId('/Walk-Two');
    $choices = tvStored($id, 'tagged_choices');

    Assert::assertIsArray($choices);

    expect($choices['channel'] ?? null)->toBe('ch-social')
        ->and($choices['steps'] ?? null)->toBe(['source' => 'src-facebook', 'medium' => 'med-feed'])
        ->and(tvStored($id, 'target_url'))->toBe('https://www.uams.edu/clinics/flu');
});

it('saves a vanity request with no choices as it always was', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/plain'])->assertCreated();

    $id = tvId('/plain');

    expect(tvStored($id, 'tagged_choices'))->toBeNull();

    tvApprove($id)->assertOk();

    expect(tvStored($id, 'target_url'))->toBe('https://www.uams.edu/clinics/flu');
});

it('refuses a choice the cascade does not offer against its field', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/bad', 'channel' => 'ch-social', 'steps' => ['source' => 'src-flyer']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['steps.source']);

    expect(mrdEntryCount())->toBe(0);
});

it('refuses a value the system fills when the requester types it', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/typed', 'vanity_url' => 'go.uams.edu/x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vanity_url']);
});

it('issues the vanity URL and the QR URL each their own destination at approval', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/Walk', 'qr' => true, 'channel' => 'ch-social', 'steps' => ['source' => 'src-facebook']])->assertCreated();

    $vanity = tvId('/Walk');
    $qr = tvId('/Walk-QR');

    tvApprove($vanity)->assertOk();

    $vanityQuery = tvQuery(tvStored($vanity, 'target_url'));
    $qrQuery = tvQuery(tvStored($qr, 'target_url'));

    expect($vanityQuery)->toContain('utm_vanity=uams.info%2Fwalk&')
        ->and($vanityQuery)->toContain('utm_qr=false')
        ->and($vanityQuery)->toContain('utm_specs=text-url')
        ->and($qrQuery)->toContain('utm_vanity=uams.info%2Fwalk-qr')
        ->and($qrQuery)->toContain('utm_qr=true')
        ->and($qrQuery)->toContain('utm_specs=qr');
});

it('leaves an issued destination as it was when a list changes later', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/frozen', 'channel' => 'ch-social', 'steps' => ['source' => 'src-facebook']])->assertCreated();

    $id = tvId('/frozen');

    tvApprove($id)->assertOk();

    $issued = tvStored($id, 'target_url');

    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-facebook', ['title' => 'Meta', 'value' => 'meta', 'list' => ['social-sources'], 'medium' => ['facebook-media']]);

    expect(tvStored($id, 'target_url'))->toBe($issued)
        ->and($issued)->toContain('utm_source=facebook');
});

it('leaves the vanity value blank when the tag is suppressed', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/quiet', 'suppress_utm' => true, 'channel' => 'ch-social'])->assertCreated();

    $id = tvId('/quiet');

    tvApprove($id)->assertOk();

    expect(tvQuery(tvStored($id, 'target_url')))->not->toContain('utm_vanity');
});

it('composes no vanity value for a row on the main site', function (): void {
    $url = app(TaggedVanityIssuer::class)->destinationFor([
        'host' => null,
        'source' => '/old-page',
        'tagged_choices' => ['destination' => 'https://www.uams.edu/flu', 'channel' => 'ch-social'],
    ]);

    expect(tvQuery($url))->not->toContain('utm_vanity=');
});

it('issues nothing for a row that carries no choices', function (): void {
    expect(app(TaggedVanityIssuer::class)->destinationFor(['host' => 'uams.info', 'source' => '/x']))->toBeNull();
});

it('holds the approval while no tagged URL parameters are set up', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/waits', 'channel' => 'ch-social'])->assertCreated();

    $global = GlobalSet::findByHandle(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->delete();

    $id = tvId('/waits');

    tvApprove($id)->assertUnprocessable()->assertJsonValidationErrors(['tagged_url']);

    expect(tvStored($id, 'status'))->toBe('pending');
});

it('shows the approver the choices a vanity request carries, and none for one made without', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/shown', 'suppress_utm' => true, 'channel' => 'ch-social', 'steps' => ['source' => 'src-facebook']])->assertCreated();
    mrdSubmit(mrdCreator(), ['source' => '/bare'])->assertCreated();

    test()->actingAs(mrdApprover());
    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('redirects', function (mixed $redirects): bool {
            Assert::assertIsIterable($redirects);

            $tagged = [];

            foreach ($redirects as $row) {
                Assert::assertIsArray($row);
                Assert::assertIsString($row['source']);
                $tagged[$row['source']] = $row['tagged'];
            }

            expect($tagged['/shown'])->toBe([
                'choices' => [
                    ['name' => 'channel', 'option' => 'Social', 'custom' => null],
                    ['name' => 'source', 'option' => 'Facebook', 'custom' => null],
                ],
                'fields' => [],
                'suppress_utm' => true,
            ])
                ->and($tagged['/bare'])->toBeNull();

            return true;
        }));
});

it('keeps each side of a tagged pair as issued when the vanity redirect is saved again', function (): void {
    mrdSubmit(mrdCreator(), ['source' => '/pair', 'qr' => true, 'channel' => 'ch-social'])->assertCreated();

    $vanity = tvId('/pair');
    $qr = tvId('/pair-QR');

    tvApprove($vanity)->assertOk();

    $issued = tvStored($qr, 'target_url');

    $entry = Entry::find($vanity);
    Assert::assertInstanceOf(Statamic\Entries\Entry::class, $entry);
    $entry->set('notes', 'edited');
    $entry->save();

    expect(tvStored($qr, 'target_url'))->toBe($issued)
        ->and($issued)->toContain('utm_qr=true');
});

it('issues a main-site request its tagged destination at approval and keeps it', function (): void {
    $response = mrdSubmit(mrdCreator(), ['host' => '', 'source' => '/old-page', 'channel' => 'ch-social', 'steps' => ['source' => 'src-facebook']]);

    $response->assertCreated();

    $id = $response->json('redirect.id');
    Assert::assertIsString($id);

    $pending = app(MarketingRedirectStore::class)->find($id);

    expect($pending['target_url'] ?? null)->toBe('https://www.uams.edu/clinics/flu')
        ->and($pending['tagged_choices']['channel'] ?? null)->toBe('ch-social');

    tvApprove($id)->assertOk();

    $issued = app(MarketingRedirectStore::class)->find($id)['target_url'] ?? null;

    expect(tvQuery($issued))->toContain('utm_source=facebook')
        ->and(tvQuery($issued))->not->toContain('utm_vanity=');
});
