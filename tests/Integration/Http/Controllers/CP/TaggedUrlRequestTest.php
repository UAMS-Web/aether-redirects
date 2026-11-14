<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Auth\File\User as FileStatamicUser;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Http\Middleware\CP\TrimStrings as CpTrimStrings;
use Statamic\Sites\Site as StatamicSite;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class, TaggedUrlRequestStore::class);

/**
 * HTTP tests for a request for a tagged URL alone, with no vanity URL
 * (UAMS-Web/aether-redirects#18): the same create screen and review queue as a
 * vanity request, saved as an entry of the `tagged_url_requests` collection, and
 * issuing its tagged URL only when an approver approves it.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/TaggedUrlRequestTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();

    $collection = Collection::make(TaggedUrlRequestStore::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();

    turParameters(['standard_parameters' => ['utm_campaign', 'utm_id', 'utm_marketing_tactic']]);
});

/**
 * Save the parameters global with the default site's values.
 *
 * @param  array<string, mixed>  $data  The global's values.
 */
function turParameters(array $data): void
{
    $set = GlobalSet::find(TaggedUrlLookup::PARAMETERS_GLOBAL) ?? GlobalSet::make(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Tagged URL parameters');
    $set->save();

    $site = Site::default();
    Assert::assertInstanceOf(StatamicSite::class, $site);
    $variables = $set->in($site->handle()) ?? $set->makeLocalization($site->handle());
    Assert::assertInstanceOf(Variables::class, $variables);
    $variables->data($data);
    $variables->save();
}

/**
 * Submit a tagged-only request as the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function turSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
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

/**
 * The id of the request a create response stored.
 *
 * @param  TestResponse<Response>  $response
 */
function turId(TestResponse $response): string
{
    $id = $response->json('redirect.id');
    Assert::assertIsString($id);

    return $id;
}

/**
 * Approve or reject a request as the signed-in user.
 *
 * @param  array<string, mixed>  $data
 * @return TestResponse<Response>
 */
function turReview(FileStatamicUser $user, string $action, string $id, array $data = []): TestResponse
{
    $test = mrdTest();
    $test->actingAs($user);

    return $test->postJson(mrdRoute('marketing-redirects.'.$action, ['redirect' => $id]), $data);
}

/**
 * A stored field of the request with the given id.
 */
function turField(string $id, string $field): mixed
{
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    Assert::assertSame(TaggedUrlRequestStore::COLLECTION, $entry->collectionHandle());

    return $entry->get($field);
}

/**
 * A request field value stored on the request with the given id.
 */
function turRequestField(string $id, string $handle): mixed
{
    $fields = turField($id, TaggedUrlRequestStore::REQUEST_FIELDS_FIELD);
    Assert::assertIsArray($fields);

    return $fields[$handle] ?? null;
}

it('saves a request with the tagged URL part only, pending and with no tagged URL yet', function (): void {
    $creator = mrdCreator();

    $response = turSubmit($creator, [
        'utm_id' => ' 42 ',
        'utm_marketing_tactic' => '',
        'requester' => '  Comms  ',
        'notes' => '   ',
    ]);

    $response->assertCreated()
        ->assertJsonPath('saved', true)
        ->assertJsonPath('message', 'Tagged URL request submitted for review. The tagged URL is issued once an approver approves it.');

    $id = turId($response);

    expect(turField($id, 'status'))->toBe('pending')
        ->and(turField($id, 'title'))->toBe('https://www.uams.edu/flu')
        ->and(turField($id, 'destination'))->toBe('https://www.uams.edu/flu')
        ->and(turRequestField($id, 'utm_campaign'))->toBe('flu-2026')
        ->and(turRequestField($id, 'utm_id'))->toBe('42')
        ->and(turRequestField($id, 'utm_marketing_tactic'))->toBeNull()
        ->and(turField($id, TaggedUrlRequestStore::SUPPRESS_FIELD))->toBeFalse()
        ->and(turField($id, 'requester'))->toBe('Comms')
        ->and(turField($id, 'notes'))->toBeNull()
        ->and(turField($id, 'submitted_by'))->toBe($creator->id())
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull();

    // It is not a redirect: neither redirect store gains a row
    expect(mrdEntryCount())->toBe(0)
        ->and(app(MarketingRedirectStore::class)->all())->toBe([]);
});

it('refuses a vanity field on a tagged-only request rather than dropping it', function (string $field, string $value): void {
    turSubmit(mrdCreator(), [$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(app(TaggedUrlRequestStore::class)->all())->toBe([]);
})->with([
    'a domain' => ['host', 'uams.info'],
    'a source' => ['source', '/flu'],
    'a redirect type' => ['response', '301'],
    'a start date' => ['valid_from', '2026-12-01'],
    'an end date' => ['valid_until', '2026-12-31'],
]);

it('requires a destination, and refuses fields that are too long or not text', function (array $overrides, string $field): void {
    /** @var array<string, mixed> $overrides */
    turSubmit(mrdCreator(), $overrides)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'no destination' => [['destination' => ''], 'destination'],
    'the destination left out' => [['destination' => null, 'kind' => MarketingRedirectController::KIND_TAGGED], 'destination'],
    'a destination that is not text' => [['destination' => ['https://www.uams.edu/']], 'destination'],
    'a requester that is not text' => [['requester' => ['Comms']], 'requester'],
    'notes that are not text' => [['notes' => ['Fall push']], 'notes'],
    'a long destination' => [['destination' => 'https://www.uams.edu/'.str_repeat('a', 2048)], 'destination'],
    'a long request field' => [['utm_campaign' => str_repeat('a', 256)], 'utm_campaign'],
    'a request field that is not text' => [['utm_id' => ['x']], 'utm_id'],
    'a suppress flag that is not a boolean' => [[TaggedUrlRequestStore::SUPPRESS_FIELD => 'maybe'], TaggedUrlRequestStore::SUPPRESS_FIELD],
    'a long requester' => [['requester' => str_repeat('a', 256)], 'requester'],
    'long notes' => [['notes' => str_repeat('a', 2049)], 'notes'],
]);

it('submits with the destination key left out entirely', function (): void {
    $test = mrdTest();
    $test->actingAs(mrdCreator());

    $test->postJson(mrdRoute('marketing-redirects.store'), ['kind' => MarketingRedirectController::KIND_TAGGED])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destination']);
});

it('accepts an empty value for each optional field', function (): void {
    $id = turId(turSubmit(mrdCreator(), [
        'utm_campaign' => null,
        TaggedUrlRequestStore::SUPPRESS_FIELD => null,
        'requester' => null,
        'notes' => null,
    ])->assertCreated());

    expect(turRequestField($id, 'utm_campaign'))->toBeNull()
        ->and(turField($id, TaggedUrlRequestStore::SUPPRESS_FIELD))->toBeFalse()
        ->and(turField($id, 'requester'))->toBeNull()
        ->and(turField($id, 'notes'))->toBeNull();
});

it('stores a suppress flag sent as a number as a boolean', function (): void {
    $id = turId(turSubmit(mrdCreator(), [TaggedUrlRequestStore::SUPPRESS_FIELD => 1])->assertCreated());

    expect(turField($id, TaggedUrlRequestStore::SUPPRESS_FIELD))->toBeTrue();
});

it('trims every value itself, without the middleware that trims them', function (): void {
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    $id = turId(turSubmit(mrdCreator(), [
        'destination' => '  https://www.uams.edu/flu  ',
        'utm_campaign' => '  flu-2026  ',
        'utm_id' => '   ',
        'requester' => '  Comms  ',
        'notes' => '   ',
    ])->assertCreated());

    expect(turField($id, 'destination'))->toBe('https://www.uams.edu/flu')
        ->and(turRequestField($id, 'utm_campaign'))->toBe('flu-2026')
        ->and(turRequestField($id, 'utm_id'))->toBeNull()
        ->and(turField($id, 'requester'))->toBe('Comms')
        ->and(turField($id, 'notes'))->toBeNull();
});

it('accepts the longest allowed values', function (): void {
    turSubmit(mrdCreator(), [
        'destination' => 'https://www.uams.edu/'.str_repeat('a', 2048 - 21),
        'utm_campaign' => str_repeat('a', 255),
        'requester' => str_repeat('a', 255),
        'notes' => str_repeat('a', 2048),
    ])->assertCreated();
});

it('still requires the vanity fields on a request that does not ask for a tagged URL alone', function (): void {
    mrdSubmit(mrdCreator(), ['kind' => 'redirect', 'host' => '', 'source' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source']);
});

it('says so when the site keeps no tagged-only requests', function (): void {
    $collection = Collection::findByHandle(TaggedUrlRequestStore::COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->delete();

    turSubmit(mrdCreator())
        ->assertUnprocessable()
        ->assertJsonPath('errors.kind.0', 'Tagged URL requests are not set up on this site.');
});

it('tells the create screen whether tagged-only requests are offered', function (bool $present): void {
    if (! $present) {
        $collection = Collection::findByHandle(TaggedUrlRequestStore::COLLECTION);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->delete();
    }

    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('taggedAvailable', $present));
})->with(['the collection exists' => [true], 'no collection' => [false]]);

it('lists a pending tagged-only request in the same review queue as a vanity request', function (): void {
    $creator = mrdCreator();
    $id = turId(turSubmit($creator, ['utm_id' => '42', TaggedUrlRequestStore::SUPPRESS_FIELD => true, 'creative_services' => 'yes', 'job_number' => 'CS-1234', 'requester' => 'Comms', 'notes' => 'Fall push']));
    mrdSubmit($creator)->assertCreated();

    $approver = mrdApprover();
    test()->actingAs($approver);

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 2)
            ->where('redirects.0.kind', 'redirect')
            ->where('redirects.1', [
                'id' => $id,
                'kind' => MarketingRedirectController::KIND_TAGGED,
                'destination' => 'https://www.uams.edu/flu',
                'fields' => ['utm_campaign' => 'flu-2026', 'utm_id' => '42'],
                'suppress_utm' => true,
                'creative_services' => true,
                'job_number' => 'CS-1234',
                'requester' => 'Comms',
                'notes' => 'Fall push',
                'can_review' => true,
                'approve_url' => mrdRoute('marketing-redirects.approve', ['redirect' => $id]),
                'reject_url' => mrdRoute('marketing-redirects.reject', ['redirect' => $id]),
            ]));
});

it('marks a tagged-only request its own submitter cannot review', function (): void {
    $both = mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]);
    turSubmit($both)->assertCreated();

    test()->actingAs($both);

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('redirects.0.can_review', false));
});

it('issues the tagged URL when an approver approves, and keeps it on the request', function (): void {
    $id = turId(turSubmit(mrdCreator(), ['utm_id' => '42', 'utm_marketing_tactic' => 'paid social']));
    $approver = mrdApprover();

    $url = 'https://www.uams.edu/flu?utm_id=42&utm_campaign=flu-2026&utm_marketing_tactic=paid%20social';

    turReview($approver, 'approve', $id)
        ->assertOk()
        ->assertJsonPath('message', 'Tagged URL approved and issued: '.$url)
        ->assertJsonPath('redirect.'.TaggedUrlRequestStore::TAGGED_URL_FIELD, $url);

    expect(turField($id, 'status'))->toBe('approved')
        ->and(turField($id, 'reviewed_by'))->toBe($approver->id())
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe($url)
        ->and(app(TaggedUrlRequestStore::class)->find($id)[TaggedUrlRequestStore::TAGGED_URL_FIELD] ?? null)->toBe($url);

    // Approved, it leaves the queue
    test()->actingAs($approver);
    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('redirects', 0));
});

it('does not change an issued tagged URL when the parameters change later', function (): void {
    $id = turId(turSubmit(mrdCreator()));
    turReview(mrdApprover(), 'approve', $id)->assertOk();

    turParameters(['standard_parameters' => []]);

    expect(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe('https://www.uams.edu/flu?utm_campaign=flu-2026')
        ->and(app(TaggedUrlRequestStore::class)->find($id)[TaggedUrlRequestStore::TAGGED_URL_FIELD] ?? null)->toBe('https://www.uams.edu/flu?utm_campaign=flu-2026');
});

it('refuses to approve while the site defines no parameters, and leaves the request pending', function (): void {
    $id = turId(turSubmit(mrdCreator()));

    $global = GlobalSet::findByHandle(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->delete();

    turReview(mrdApprover(), 'approve', $id)
        ->assertUnprocessable()
        ->assertJsonPath('errors.tagged_url.0', 'Tagged URL parameters are not set up on this site, so no tagged URL can be issued yet.');

    expect(turField($id, 'status'))->toBe('pending')
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull();

    // A rejection needs no parameters
    turReview(mrdApprover(), 'reject', $id, ['reason' => 'No campaign'])->assertOk();
});

it('ignores a status, tagged URL or submitter the requester sends', function (): void {
    $creator = mrdCreator();

    $id = turId(turSubmit($creator, [
        'status' => 'approved',
        TaggedUrlRequestStore::TAGGED_URL_FIELD => 'https://evil.example/',
        'submitted_by' => 'someone-else',
        'reviewed_by' => 'someone-else',
    ])->assertCreated());

    expect(turField($id, 'status'))->toBe('pending')
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull()
        ->and(turField($id, 'submitted_by'))->toBe($creator->id())
        ->and(turField($id, 'reviewed_by'))->toBeNull();
});

it('leaves every utm_ parameter off the issued URL when the request suppresses them', function (): void {
    $id = turId(turSubmit(mrdCreator(), [TaggedUrlRequestStore::SUPPRESS_FIELD => true]));

    turReview(mrdApprover(), 'approve', $id)->assertOk();

    expect(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe('https://www.uams.edu/flu');
});

it('refuses to let a submitter approve their own tagged-only request, and issues nothing', function (): void {
    $both = mrdUser([MarketingRedirectController::PERMISSION, MarketingRedirectController::APPROVE_PERMISSION]);
    $id = turId(turSubmit($both));

    turReview($both, 'approve', $id)
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot review a redirect you requested.');

    expect(turField($id, 'status'))->toBe('pending')
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull();
});

it('rejects a tagged-only request with its reason and issues no tagged URL', function (): void {
    $id = turId(turSubmit(mrdCreator()));
    $approver = mrdApprover();

    turReview($approver, 'reject', $id, ['reason' => '  Not a campaign  '])
        ->assertOk()
        ->assertJsonPath('message', 'Redirect rejected.');

    expect(turField($id, 'status'))->toBe('rejected')
        ->and(turField($id, 'rejection_reason'))->toBe('Not a campaign')
        ->and(turField($id, 'reviewed_by'))->toBe($approver->id())
        ->and(turField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull();

    // Reviewed, it cannot be approved afterwards
    turReview($approver, 'approve', $id)
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'This redirect has already been reviewed.');
});

it('still approves a vanity request in the same queue with its own message', function (): void {
    $response = mrdSubmit(mrdCreator());

    turReview(mrdApprover(), 'approve', turId($response))
        ->assertOk()
        ->assertJsonPath('message', 'Redirect approved. It now serves on its domain.');
});

it('neither offers nor accepts a tagged-only request from a user who can only make short links', function (): void {
    $shortLinks = mrdUser([MarketingRedirectController::SHORT_LINK_PERMISSION]);
    test()->actingAs($shortLinks);

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('taggedAvailable', false));

    turSubmit($shortLinks)->assertForbidden();

    expect(app(TaggedUrlRequestStore::class)->all())->toBe([]);
});

it('asks a tagged-only request whether it involves Creative Services, and takes no for an answer', function (): void {
    turSubmit(mrdCreator(), ['creative_services' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['creative_services']);

    turSubmit(mrdCreator(), ['creative_services' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['creative_services']);

    // No needs no job number, and a job number sent with no is not kept
    $id = turId(turSubmit(mrdCreator(), ['creative_services' => 'no', 'job_number' => 'CS-1'])->assertCreated());

    expect(turField($id, 'creative_services'))->toBeFalse()
        ->and(turField($id, 'job_number'))->toBeNull();
});

it('needs a job number in the configured format when a tagged-only request involves Creative Services', function (): void {
    config()->set('aether-redirects.creative_services.job_number.pattern', '/^CS-\d+$/');
    config()->set('aether-redirects.creative_services.job_number.description', 'CS- and digits');

    turSubmit(mrdCreator(), ['creative_services' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.job_number.0', 'Enter the Creative Services job number.');

    turSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.job_number.0', 'The job number must match CS- and digits.');

    turSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => ['CS-1']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['job_number']);

    turSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => 'CS-'.str_repeat('1', 98)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['job_number']);

    $id = turId(turSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => 'CS-1234'])->assertCreated());

    expect(turField($id, 'creative_services'))->toBeTrue()
        ->and(turField($id, 'job_number'))->toBe('CS-1234');
});

it('keeps a job number padded to the longest allowed length on a tagged-only request, trimmed', function (): void {
    test()->withoutMiddleware([TrimStrings::class, CpTrimStrings::class, ConvertEmptyStringsToNull::class]);

    $number = str_repeat('1', 98);
    $id = turId(turSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => ' '.$number.' '])->assertCreated());

    expect(turField($id, 'job_number'))->toBe($number);
});

it('requires the Creative Services answer even when the key is left out', function (): void {
    $test = mrdTest();
    $test->actingAs(mrdCreator());

    $test->postJson(mrdRoute('marketing-redirects.store'), ['kind' => MarketingRedirectController::KIND_TAGGED, 'destination' => 'https://www.uams.edu/flu'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['creative_services']);
});

it('takes an empty job number with a no, and refuses one that is not text', function (): void {
    turSubmit(mrdCreator(), ['creative_services' => 'no', 'job_number' => null])->assertCreated();

    turSubmit(mrdCreator(), ['creative_services' => 'no', 'job_number' => ['CS-1']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['job_number']);
});

it('shows a stored tagged-only request with no Creative Services answer as not involving them', function (): void {
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->collection(TaggedUrlRequestStore::COLLECTION);
    $entry->data(['title' => 'https://www.uams.edu/', 'destination' => 'https://www.uams.edu/', 'status' => 'pending', 'submitted_by' => 'someone-else']);
    $entry->save();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('redirects.0.creative_services', false)
            ->where('redirects.0.job_number', null));
});
