<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
use Statamic\Sites\Site as StatamicSite;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlChoiceValidator;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(MarketingRedirectController::class, TaggedUrlChoiceValidator::class, TaggedUrlRequestStore::class);

/**
 * HTTP tests for the choices a tagged-only request is made with
 * (UAMS-Web/aether-redirects#119, part of #26): saved with the request, checked against what
 * the site's cascade offers, and read back to issue the URL at approval.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/TaggedUrlChoicesTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
    mrdMainSiteGlobal();

    foreach ([TaggedUrlRequestStore::COLLECTION, TaggedUrlLookup::LISTS_COLLECTION, TaggedUrlLookup::OPTIONS_COLLECTION] as $handle) {
        $collection = Collection::make($handle);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->save();
    }

    tcLookups();
    tcParameters(tcDefaultParameters());
});

/**
 * Save a lookup entry.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function tcEntry(string $collection, string $id, array $data, bool $published = true): void
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->id($id);
    $entry->collection($collection);
    $entry->locale('default');
    $entry->published($published);
    $entry->data($data);
    $entry->save();
}

/**
 * The site's lists: channels leading to sources, and a source leading to its own media.
 */
function tcLookups(): void
{
    $lists = TaggedUrlLookup::LISTS_COLLECTION;
    $options = TaggedUrlLookup::OPTIONS_COLLECTION;

    foreach (['channels', 'social-sources', 'generic-sources', 'facebook-media', 'generic-media', 'audiences'] as $list) {
        tcEntry($lists, $list, ['title' => $list]);
    }

    tcEntry($options, 'ch-social', ['title' => 'Social', 'value' => 'social', 'list' => ['channels'], 'source' => ['social-sources']]);
    tcEntry($options, 'ch-print', ['title' => 'Print', 'value' => 'print', 'list' => ['channels']]);
    tcEntry($options, 'src-facebook', ['title' => 'Facebook', 'value' => 'facebook', 'list' => ['social-sources'], 'medium' => ['facebook-media']]);
    tcEntry($options, 'src-linkedin', ['title' => 'LinkedIn', 'value' => 'linkedin', 'list' => ['social-sources']]);
    tcEntry($options, 'src-flyer', ['title' => 'Flyer', 'value' => 'flyer', 'list' => ['generic-sources']]);
    tcEntry($options, 'med-feed', ['title' => 'Feed', 'value' => 'feed', 'list' => ['facebook-media']]);
    tcEntry($options, 'med-other', ['title' => 'Other', 'value' => 'other', 'list' => ['generic-media']]);
    tcEntry($options, 'aud-staff', ['title' => 'Staff', 'value' => 'Staff', 'list' => ['audiences']]);
    tcEntry($options, 'aud-public', ['title' => 'Public', 'value' => 'Public', 'list' => ['audiences']]);
}

/**
 * The parameters the tests use unless one overrides them: the standard ones, an audience list and a request field.
 *
 * @return array<string, mixed>
 */
function tcDefaultParameters(): array
{
    return [
        TaggedUrlLookup::CHANNEL_LIST_FIELD => ['channels'],
        'fallback_source' => ['generic-sources'],
        'fallback_medium' => ['generic-media'],
        'standard_parameters' => ['utm_source', 'utm_medium', 'utm_campaign'],
        'custom_parameters' => [
            ['name' => 'audience', 'enabled' => true, 'order' => 1, 'source_type' => 'list', 'list' => ['audiences']],
            ['name' => 'vanity', 'enabled' => true, 'order' => 2, 'source_type' => 'request_field', 'request_field' => 'vanity_path'],
        ],
    ];
}

/**
 * Save the parameters global with the default site's values.
 *
 * @param  array<string, mixed>  $data  The global's values.
 */
function tcParameters(array $data): void
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
function tcSubmit(FileStatamicUser $user, array $overrides = []): TestResponse
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
 * A request made with a full set of choices.
 *
 * @return array<string, mixed>
 */
function tcChoices(): array
{
    return [
        'channel' => 'ch-social',
        'steps' => ['source' => 'src-facebook', 'medium' => 'med-feed'],
        'lists' => ['audiences' => 'aud-staff'],
        'custom_values' => ['audiences' => 'Extra'],
        'vanity_path' => '/flu',
    ];
}

/**
 * The id of the request a create response stored.
 *
 * @param  TestResponse<Response>  $response
 */
function tcId(TestResponse $response): string
{
    $id = $response->json('redirect.id');
    Assert::assertIsString($id);

    return $id;
}

/**
 * A stored field of the request with the given id.
 */
function tcField(string $id, string $field): mixed
{
    $entry = Entry::find($id);
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    return $entry->get($field);
}

/**
 * Approve a request as an approver.
 *
 * @return TestResponse<Response>
 */
function tcApprove(string $id): TestResponse
{
    $test = mrdTest();
    $test->actingAs(mrdApprover());

    return $test->postJson(mrdRoute('marketing-redirects.approve', ['redirect' => $id]));
}

it('saves a request pending with its channel, step choices, list choices, custom values and request fields', function (): void {
    $response = tcSubmit(mrdCreator(), tcChoices());

    $response->assertCreated();

    $id = tcId($response);

    expect(tcField($id, 'status'))->toBe('pending')
        ->and(tcField($id, 'channel'))->toBe('ch-social')
        ->and(tcField($id, 'steps'))->toBe(['source' => 'src-facebook', 'medium' => 'med-feed'])
        ->and(tcField($id, 'lists'))->toBe(['audiences' => 'aud-staff'])
        ->and(tcField($id, 'custom_values'))->toBe(['audiences' => 'Extra'])
        ->and(tcField($id, 'request_fields'))->toBe(['utm_campaign' => 'flu-2026', 'vanity_path' => '/flu'])
        ->and(tcField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBeNull();
});

it('keeps blank choices out of the saved request', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), [
        'channel' => '  ',
        'steps' => ['source' => '', 'medium' => null],
        'lists' => ['audiences' => ''],
        'custom_values' => ['audiences' => '  '],
        'vanity_path' => '',
    ]));

    expect(tcField($id, 'channel'))->toBeNull()
        ->and(tcField($id, 'steps'))->toBe([])
        ->and(tcField($id, 'lists'))->toBe([])
        ->and(tcField($id, 'custom_values'))->toBe([])
        ->and(tcField($id, 'request_fields'))->toBe(['utm_campaign' => 'flu-2026']);
});

it('refuses a choice the cascade does not offer, naming the field, and saves nothing', function (array $overrides, string $field): void {
    /** @var array<string, mixed> $overrides */
    tcSubmit(mrdCreator(), $overrides)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(mrdEntryCount())->toBe(0);
})->with([
    'a channel the site does not offer' => [['channel' => 'src-facebook'], 'channel'],
    'a channel that is not text' => [['channel' => ['ch-social']], 'channel'],
    'a source from another channel list' => [['channel' => 'ch-social', 'steps' => ['source' => 'src-flyer']], 'steps.source'],
    'a source when the channel is not chosen' => [['steps' => ['source' => 'src-facebook']], 'steps.source'],
    'a medium the chosen source does not lead to' => [['channel' => 'ch-social', 'steps' => ['source' => 'src-facebook', 'medium' => 'med-other']], 'steps.medium'],
    'a step that is not in the cascade' => [['steps' => ['colour' => 'ch-social']], 'steps.colour'],
    'a step choice that is not text' => [['channel' => 'ch-social', 'steps' => ['source' => ['src-facebook']]], 'steps.source'],
    'steps that are not a list of choices' => [['steps' => 'src-facebook'], 'steps'],
    'an option that does not exist' => [['channel' => 'ch-social', 'steps' => ['source' => 'nope']], 'steps.source'],
]);

it('offers a step its generic list when no choice names one', function (): void {
    tcSubmit(mrdCreator(), ['channel' => 'ch-print', 'steps' => ['source' => 'src-flyer', 'medium' => 'med-other']])->assertCreated();
});

it('refuses a list choice from a list no parameter reads, an option not in the list, and a custom value no parameter reads', function (array $overrides, string $field): void {
    /** @var array<string, mixed> $overrides */
    tcSubmit(mrdCreator(), $overrides)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'a list no parameter reads' => [['lists' => ['social-sources' => 'src-facebook']], 'lists.social-sources'],
    'an option from another list' => [['lists' => ['audiences' => 'src-facebook']], 'lists.audiences'],
    'an option that is not text' => [['lists' => ['audiences' => ['aud-staff']]], 'lists.audiences'],
    'lists that are not a list of choices' => [['lists' => 'aud-staff'], 'lists'],
    'a custom value for a key nothing reads' => [['custom_values' => ['medium_x' => 'a']], 'custom_values.medium_x'],
    'a long custom value' => [['custom_values' => ['audiences' => str_repeat('a', 256)]], 'custom_values.audiences'],
    'a custom value that is not text' => [['custom_values' => ['source' => ['a']]], 'custom_values.source'],
    'custom values that are not a list' => [['custom_values' => 'a'], 'custom_values'],
]);

it('accepts a custom value at the length limit, beside a step', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), ['channel' => 'ch-social', 'steps' => ['source' => 'src-facebook'], 'custom_values' => ['source' => str_repeat('a', 255)]]));

    expect(tcField($id, 'custom_values'))->toBe(['source' => str_repeat('a', 255)]);
});

it('refuses a request field no parameter reads rather than dropping it', function (): void {
    // The site reads utm_campaign and the vanity path; utm_id is read by nothing
    tcSubmit(mrdCreator(), ['utm_id' => '42'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('utm_id');

    expect(mrdEntryCount())->toBe(0);
});

it('refuses a request field that is too long or not text', function (array $overrides, string $field): void {
    /** @var array<string, mixed> $overrides */
    tcSubmit(mrdCreator(), $overrides)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'a long request field' => [['vanity_path' => str_repeat('a', 256)], 'vanity_path'],
    'a request field that is not text' => [['vanity_path' => ['x']], 'vanity_path'],
]);

it('stores a request field named like a workflow field inside its own map', function (): void {
    tcParameters([
        'standard_parameters' => [],
        'custom_parameters' => [
            ['name' => 'state', 'enabled' => true, 'order' => 1, 'source_type' => 'request_field', 'request_field' => 'status'],
        ],
    ]);

    $id = tcId(tcSubmit(mrdCreator(), ['status' => 'approved', 'utm_campaign' => null]));

    expect(tcField($id, 'status'))->toBe('pending')
        ->and(tcField($id, 'request_fields'))->toBe(['status' => 'approved']);
});

it('issues a URL that carries every defined parameter with the right value when it is approved', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), tcChoices()));

    $url = 'https://www.uams.edu/flu?utm_source=facebook&utm_medium=feed&utm_campaign=flu-2026&audience=staff_extra&vanity=%2Fflu';

    tcApprove($id)
        ->assertOk()
        ->assertJsonPath('redirect.'.TaggedUrlRequestStore::TAGGED_URL_FIELD, $url);

    expect(tcField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe($url);
});

it('leaves an issued URL as it was when a list or a parameter changes later', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), tcChoices()));
    tcApprove($id)->assertOk();

    $issued = tcField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD);

    // Rename and retire options, repoint a pointer, and change the parameters
    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-facebook', ['title' => 'Meta', 'value' => 'meta', 'list' => ['social-sources'], 'medium' => ['generic-media']]);
    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'med-feed', ['title' => 'Feed', 'value' => 'feed', 'list' => ['facebook-media'], 'active' => false]);
    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'aud-staff', ['title' => 'Staff', 'value' => 'Everyone', 'list' => ['audiences']]);
    tcParameters(['standard_parameters' => ['utm_campaign'], 'custom_parameters' => []]);

    $store = app(TaggedUrlRequestStore::class);
    $row = $store->find($id);

    expect($issued)->toBeString()
        ->and($row)->not->toBeNull()
        ->and($row[TaggedUrlRequestStore::TAGGED_URL_FIELD] ?? null)->toBe($issued)
        ->and(tcField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe($issued);
});

it('composes from the choices as they stand when the request is approved, not as they stood when it was saved', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), tcChoices()));

    // A pending request has issued nothing, so a rename before approval is what gets issued
    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-facebook', ['title' => 'Meta', 'value' => 'meta', 'list' => ['social-sources'], 'medium' => ['facebook-media']]);

    tcApprove($id)->assertOk();

    expect(tcField($id, TaggedUrlRequestStore::TAGGED_URL_FIELD))->toBe('https://www.uams.edu/flu?utm_source=meta&utm_medium=feed&utm_campaign=flu-2026&audience=staff_extra&vanity=%2Fflu');
});

it('approves a request saved before the choices were stored to the same URL', function (): void {
    tcParameters(['standard_parameters' => ['utm_id', 'utm_campaign', 'utm_marketing_tactic']]);

    // The shape the store wrote before: the three fixed fields on the entry itself
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->collection(TaggedUrlRequestStore::COLLECTION);
    $entry->data([
        'title' => 'https://www.uams.edu/flu',
        'destination' => 'https://www.uams.edu/flu',
        'utm_campaign' => 'flu-2026',
        'utm_id' => '42',
        'utm_marketing_tactic' => 'paid social',
        TaggedUrlRequestStore::SUPPRESS_FIELD => false,
        'status' => 'pending',
        'submitted_by' => 'someone',
        TaggedUrlRequestStore::TAGGED_URL_FIELD => null,
    ]);
    $entry->save();

    $id = $entry->id();
    Assert::assertIsString($id);

    tcApprove($id)
        ->assertOk()
        ->assertJsonPath('redirect.'.TaggedUrlRequestStore::TAGGED_URL_FIELD, 'https://www.uams.edu/flu?utm_id=42&utm_campaign=flu-2026&utm_marketing_tactic=paid%20social');
});

it('leaves every utm_ parameter off the issued URL when the request suppresses them, and keeps the others', function (): void {
    $id = tcId(tcSubmit(mrdCreator(), [...tcChoices(), TaggedUrlRequestStore::SUPPRESS_FIELD => true]));

    tcApprove($id)
        ->assertOk()
        ->assertJsonPath('redirect.'.TaggedUrlRequestStore::TAGGED_URL_FIELD, 'https://www.uams.edu/flu?audience=staff_extra&vanity=%2Fflu');
});

it('shows a pending request with the fields it was saved with', function (): void {
    tcSubmit(mrdCreator(), tcChoices())->assertCreated();

    test()->actingAs(mrdApprover());
    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('redirects.0.fields', ['utm_campaign' => 'flu-2026', 'vanity_path' => '/flu']));
});
