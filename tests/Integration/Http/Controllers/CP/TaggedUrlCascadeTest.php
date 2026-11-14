<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
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
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\TaggedUrlCascadeController;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(TaggedUrlCascadeController::class);

/**
 * HTTP tests for the endpoint that serves the tagged URL cascade's options
 * (UAMS-Web/aether-redirects#118).
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/TaggedUrlCascadeTest.php
 */
beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();
    mrdSetUp();
});

/**
 * Save a lookup entry.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function tcaEntry(string $collection, string $id, array $data): void
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->id($id);
    $entry->collection($collection);
    $entry->locale('default');
    $entry->published(true);
    $entry->data($data);
    $entry->save();
}

/**
 * Save the two lookup collections and the example's lists, options and parameters.
 *
 * @param  array<string, mixed>  $parameters  The parameters global's values, over the defaults.
 */
function tcaExample(array $parameters = []): void
{
    foreach ([TaggedUrlLookup::LISTS_COLLECTION, TaggedUrlLookup::OPTIONS_COLLECTION] as $handle) {
        $collection = Collection::make($handle);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->save();
    }

    $options = TaggedUrlLookup::OPTIONS_COLLECTION;
    tcaEntry($options, 'ch-social', ['title' => 'Social', 'value' => 'social', 'list' => 'channels', 'order' => 2, 'source' => 'social-sources']);
    tcaEntry($options, 'ch-print', ['title' => 'Print', 'value' => 'print', 'list' => 'channels', 'order' => 1]);
    tcaEntry($options, 'ch-old', ['title' => 'Old', 'value' => 'old', 'list' => 'channels', 'active' => false]);
    tcaEntry($options, 'src-facebook', ['title' => 'Facebook', 'value' => 'facebook', 'list' => 'social-sources', 'medium' => 'facebook-media']);
    tcaEntry($options, 'med-paid', ['title' => 'Paid social', 'value' => 'paid_social', 'list' => 'facebook-media']);
    tcaEntry($options, 'med-other', ['title' => 'Other', 'value' => 'other', 'list' => 'generic-media']);
    tcaEntry($options, 'org-com', ['title' => 'Communications', 'value' => 'com', 'list' => 'org-units']);

    $set = GlobalSet::make(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Tagged URL parameters');
    $set->save();

    $site = Site::default();
    Assert::assertInstanceOf(StatamicSite::class, $site);
    $variables = $set->makeLocalization($site->handle());
    Assert::assertInstanceOf(Variables::class, $variables);
    $variables->data([
        TaggedUrlLookup::CHANNEL_LIST_FIELD => ['channels'],
        'fallback_medium' => ['generic-media'],
        'standard_parameters' => ['utm_source', 'utm_medium', 'utm_campaign'],
        'custom_parameters' => [
            ['name' => 'org_unit', 'enabled' => true, 'order' => 1, 'source_type' => 'list', 'list' => ['org-units']],
            ['name' => 'vanity', 'enabled' => true, 'order' => 2, 'source_type' => 'request_field', 'request_field' => 'vanity_path'],
        ],
        ...$parameters,
    ]);
    $variables->save();
}

/**
 * Ask for the cascade as a user who may request a tagged URL.
 *
 * @param  array<string, mixed>  $query  The choices so far.
 * @return TestResponse<Response>
 */
function tcaGet(array $query = []): TestResponse
{
    $test = mrdTest();
    $test->actingAs(mrdUser([MarketingRedirectController::TAGGED_PERMISSION]));

    return $test->getJson(mrdRoute('marketing-redirects.tagged-url.cascade', $query));
}

/**
 * A part of the response as an array.
 *
 * @param  TestResponse<Response>  $response
 * @return array<mixed>
 */
function tcaPart(TestResponse $response, string $key): array
{
    $part = $response->json($key);
    Assert::assertIsArray($part);

    return $part;
}

/**
 * The option ids of a response part, in order.
 *
 * @param  array<mixed>  $options
 * @return list<string>
 */
function tcaIds(array $options): array
{
    $ids = [];
    foreach ($options as $option) {
        Assert::assertIsArray($option);
        Assert::assertIsString($option['id']);
        $ids[] = $option['id'];
    }

    return $ids;
}

it('offers the channel options, active only and in their order', function (): void {
    tcaExample();

    $response = tcaGet()->assertOk();

    expect($response->json('channel'))->toBe([
        ['id' => 'ch-print', 'label' => 'Print'],
        ['id' => 'ch-social', 'label' => 'Social'],
    ]);
});

it('offers each step the list the earlier choices name, the deepest choice first, then the generic list', function (): void {
    tcaExample();

    $none = tcaGet()->assertOk();
    $social = tcaGet(['channel' => 'ch-social'])->assertOk();
    $facebook = tcaGet(['channel' => 'ch-social', 'steps' => ['source' => 'src-facebook']])->assertOk();

    expect(array_column(tcaPart($none, 'steps'), 'step'))->toBe(['source', 'medium'])
        ->and($none->json('steps.0.options'))->toBe([])
        ->and(tcaIds(tcaPart($none, 'steps.1.options')))->toBe(['med-other'])
        ->and(tcaIds(tcaPart($social, 'steps.0.options')))->toBe(['src-facebook'])
        ->and(tcaIds(tcaPart($social, 'steps.1.options')))->toBe(['med-other'])
        ->and(tcaIds(tcaPart($facebook, 'steps.1.options')))->toBe(['med-paid']);
});

it('leaves out a step no enabled parameter reads', function (): void {
    tcaExample(['standard_parameters' => ['utm_medium', 'utm_campaign']]);

    expect(array_column(tcaPart(tcaGet(['channel' => 'ch-social'])->assertOk(), 'steps'), 'step'))->toBe(['medium']);
});

it('returns a list-sourced parameter with its options and a request-field parameter as its handle', function (): void {
    tcaExample();

    $response = tcaGet()->assertOk();

    expect($response->json('lists'))->toBe([
        ['parameter' => 'org_unit', 'list' => 'org-units', 'options' => [['id' => 'org-com', 'label' => 'Communications']]],
    ])->and($response->json('fields'))->toBe(['utm_campaign', 'vanity_path']);
});

it('ignores a choice that no longer exists', function (): void {
    tcaExample();

    $response = tcaGet(['channel' => 'gone', 'steps' => ['source' => 'gone', 'medium' => 'gone'], 'lists' => ['org-units' => 'gone']])->assertOk();

    expect(tcaIds(tcaPart($response, 'steps.1.options')))->toBe(['med-other']);
});

it('ignores a query value that is empty or not text', function (): void {
    tcaExample();

    $response = tcaGet(['channel' => '', 'steps' => ['source' => '', 'medium' => ['x']], 'lists' => ['org-units' => ['x'], 'empty' => '']])->assertOk();

    expect(tcaIds(tcaPart($response, 'steps.1.options')))->toBe(['med-other']);
});

it('answers an empty cascade when the host has no lookup data', function (): void {
    $response = tcaGet()->assertOk();

    expect($response->json())->toBe(['channel' => [], 'steps' => [], 'lists' => [], 'fields' => []]);
});

it('answers an empty cascade when the host names no channel list or parameters', function (): void {
    tcaExample([TaggedUrlLookup::CHANNEL_LIST_FIELD => [], 'standard_parameters' => [], 'custom_parameters' => []]);

    expect(tcaGet()->assertOk()->json())->toBe(['channel' => [], 'steps' => [], 'lists' => [], 'fields' => []]);
});

it('is open to each permission that may request a tagged URL and to nobody else', function (array $permissions, int $status): void {
    tcaExample();

    $test = mrdTest();
    $test->actingAs(mrdUser(array_values(array_filter($permissions, is_string(...)))));

    $test->getJson(mrdRoute('marketing-redirects.tagged-url.cascade'))->assertStatus($status);
})->with([
    'tagged' => [[MarketingRedirectController::TAGGED_PERMISSION], 200],
    'vanity' => [[MarketingRedirectController::VANITY_PERMISSION], 200],
    'custom vanity' => [[MarketingRedirectController::CUSTOM_VANITY_PERMISSION], 200],
    'redirects' => [[MarketingRedirectController::PERMISSION], 200],
    'approve only' => [[MarketingRedirectController::APPROVE_PERMISSION], 403],
    'none' => [[], 403],
]);
