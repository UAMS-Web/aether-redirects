<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Fields\Field;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test points the blueprint directory at its own and saves collections.
uses(IsolatesStache::class);

/**
 * The blueprints of the tagged URL builder's lookup data (UAMS-Web/aether-redirects#51).
 * This package serves the `tagged_url_parameters` global set's. The lists and options
 * collections cannot be served from an add-on without displacing a host's own
 * blueprints, so theirs are the reference files `docs/blueprints/lookup_list.yaml` and
 * `docs/blueprints/lookup_option.yaml`, which the host's content repository copies.
 * The host defines the collections and the global set; each test makes the ones it reads.
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/LookupBlueprintsTest.php
 */

/**
 * The path of a reference blueprint under `docs/blueprints`.
 *
 * @param  string  $file  The file's name.
 */
function lookupReferencePath(string $file): string
{
    return __DIR__.'/../../../docs/blueprints/'.$file;
}

/**
 * A reference entry blueprint, read as the host's content repository would copy it.
 *
 * @param  string  $file  The reference file's name.
 */
function lookupEntryBlueprint(string $file): StatamicBlueprint
{
    $blueprint = new StatamicBlueprint;
    $blueprint->setContents(YAML::file(lookupReferencePath($file))->parse());

    return $blueprint;
}

/**
 * The `tagged_url_parameters` global set's blueprint, as its variables resolve it.
 */
function lookupParametersBlueprint(): StatamicBlueprint
{
    $global = GlobalSet::make(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->title('Tagged URL parameters');
    $global->save();

    $variables = $global->makeLocalization('default');
    Assert::assertInstanceOf(Variables::class, $variables);

    $blueprint = $variables->blueprint();
    Assert::assertInstanceOf(StatamicBlueprint::class, $blueprint);

    return $blueprint;
}

/**
 * A field of a blueprint.
 *
 * @param  StatamicBlueprint  $blueprint  The blueprint.
 * @param  string  $handle  The field's handle.
 */
function lookupField(StatamicBlueprint $blueprint, string $handle): Field
{
    $field = $blueprint->field($handle);
    Assert::assertInstanceOf(Field::class, $field);

    return $field;
}

/**
 * The handles of a blueprint's fields, in order.
 *
 * @param  StatamicBlueprint  $blueprint  The blueprint.
 * @return list<string> The handles.
 */
function lookupFieldHandles(StatamicBlueprint $blueprint): array
{
    return array_values(array_map(strval(...), $blueprint->fields()->all()->keys()->all()));
}

/**
 * The keys of a field's `options` setting.
 *
 * @param  Field  $field  The field.
 * @return list<string> The keys.
 */
function lookupOptionKeys(Field $field): array
{
    $options = $field->get('options');
    Assert::assertIsArray($options);

    return array_map(strval(...), array_keys($options));
}

/**
 * The config of each field in the custom parameters grid, by handle.
 *
 * @return array<string, array<string, mixed>> The configs.
 */
function lookupCustomParameterFields(): array
{
    $rows = lookupField(lookupParametersBlueprint(), 'custom_parameters')->get('fields');
    Assert::assertIsArray($rows);

    $fields = [];

    foreach ($rows as $row) {
        Assert::assertIsArray($row);
        Assert::assertIsString($row['handle']);

        /** @var array<string, mixed> $config */
        $config = $row['field'];
        $fields[$row['handle']] = $config;
    }

    return $fields;
}

it("gives the lists collection's entry blueprint a title and member lists", function (): void {
    $blueprint = lookupEntryBlueprint('lookup_list.yaml');

    expect(lookupFieldHandles($blueprint))->toBe(['title', 'members'])
        ->and(lookupField($blueprint, 'title')->get('required'))->toBeTrue();
});

it('lets a composed list name its member lists, in order, from the lists collection only', function (): void {
    $members = lookupField(lookupEntryBlueprint('lookup_list.yaml'), 'members');

    expect($members->type())->toBe('entries')
        ->and($members->get('collections'))->toBe([TaggedUrlLookup::LISTS_COLLECTION])
        ->and($members->get('max_items'))->toBeNull();
});

it("gives the options collection's entry blueprint its label, value, list, pointers, flag and order", function (): void {
    $blueprint = lookupEntryBlueprint('lookup_option.yaml');

    expect(lookupFieldHandles($blueprint))->toBe(['title', 'value', 'list', ...TaggedUrlLookup::STEPS, 'active', 'order'])
        ->and(lookupField($blueprint, 'title')->display())->toBe('Label')
        ->and(lookupField($blueprint, 'value')->get('required'))->toBeTrue()
        ->and(lookupField($blueprint, 'active')->defaultValue())->toBeTrue()
        ->and(lookupField($blueprint, 'order')->type())->toBe('integer');
});

it('makes an option belong to exactly one list', function (): void {
    $list = lookupField(lookupEntryBlueprint('lookup_option.yaml'), 'list');

    expect($list->type())->toBe('entries')
        ->and($list->get('collections'))->toBe([TaggedUrlLookup::LISTS_COLLECTION])
        ->and($list->get('max_items'))->toBe(1)
        ->and($list->get('required'))->toBeTrue();
});

it('gives an option one optional downstream list pointer for each cascade step', function (string $step): void {
    $pointer = lookupField(lookupEntryBlueprint('lookup_option.yaml'), $step);

    expect($pointer->type())->toBe('entries')
        ->and($pointer->get('collections'))->toBe([TaggedUrlLookup::LISTS_COLLECTION])
        ->and($pointer->get('max_items'))->toBe(1)
        ->and($pointer->isRequired())->toBeFalse();
})->with(TaggedUrlLookup::STEPS);

it("serves the parameters global set's blueprint from this package", function (): void {
    $blueprint = lookupParametersBlueprint();

    expect($blueprint->hasField('standard_parameters'))->toBeTrue()
        ->and($blueprint->hasField('custom_parameters'))->toBeTrue();
});

it('offers the Google Analytics standard parameters as checkboxes', function (): void {
    $standard = lookupField(lookupParametersBlueprint(), 'standard_parameters');

    expect($standard->type())->toBe('checkboxes')
        ->and(lookupOptionKeys($standard))->toBe([
            'utm_id',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_source_platform',
            'utm_term',
            'utm_content',
            'utm_creative_format',
            'utm_marketing_tactic',
        ]);
});

it("offers a grid for custom parameters: each one's name, value source, order and state", function (): void {
    $fields = lookupCustomParameterFields();
    $sources = $fields['source_type']['options'] ?? null;
    Assert::assertIsArray($sources);

    expect(lookupField(lookupParametersBlueprint(), 'custom_parameters')->type())->toBe('grid')
        ->and(array_keys($fields))->toBe(['enabled', 'name', 'order', 'source_type', 'request_field', 'list', 'fixed_value'])
        ->and(array_keys($sources))->toBe(['request_field', 'list', 'fixed_value'])
        ->and($fields['enabled']['default'])->toBeTrue()
        ->and($fields['name']['validate'])->toContain('required')
        ->and($fields['list']['collections'])->toBe([TaggedUrlLookup::LISTS_COLLECTION]);
});

it('shows only the value field that matches the chosen source', function (string $field, string $source): void {
    expect(lookupCustomParameterFields()[$field]['if'] ?? null)->toBe(['source_type' => 'equals '.$source]);
})->with([
    'a request field' => ['request_field', 'request_field'],
    'a list' => ['list', 'list'],
    'a fixed value' => ['fixed_value', 'fixed_value'],
]);

it('lets the generic fallback list of each cascade step be chosen', function (string $step): void {
    $fallback = lookupField(lookupParametersBlueprint(), 'fallback_'.$step);

    expect($fallback->type())->toBe('entries')
        ->and($fallback->get('collections'))->toBe([TaggedUrlLookup::LISTS_COLLECTION])
        ->and($fallback->get('max_items'))->toBe(1);
})->with(TaggedUrlLookup::STEPS);

it('offers a fallback field for exactly the steps an option can point at', function (): void {
    $fallbacks = collect(lookupFieldHandles(lookupParametersBlueprint()))
        ->filter(fn (string $handle): bool => str_starts_with($handle, 'fallback_'))
        ->map(fn (string $handle): string => substr($handle, strlen('fallback_')))
        ->values()
        ->all();

    expect($fallbacks)->toBe(TaggedUrlLookup::STEPS);
});

it('defines no collection, global set or entry of its own', function (): void {
    expect(Collection::findByHandle(TaggedUrlLookup::LISTS_COLLECTION))->toBeNull()
        ->and(Collection::findByHandle(TaggedUrlLookup::OPTIONS_COLLECTION))->toBeNull()
        ->and(GlobalSet::findByHandle(TaggedUrlLookup::PARAMETERS_GLOBAL))->toBeNull()
        ->and(Entry::all()->count())->toBe(0);
});

it("uses the host's own parameters blueprint when it has one", function (): void {
    File::ensureDirectoryExists(Blueprint::directory().'/globals');
    File::put(Blueprint::directory().'/globals/'.TaggedUrlLookup::PARAMETERS_GLOBAL.'.yaml', "fields:\n  -\n    handle: host_field\n    field:\n      type: text\n");
    Blink::flush();

    $blueprint = lookupParametersBlueprint();

    expect($blueprint->hasField('host_field'))->toBeTrue()
        ->and($blueprint->hasField('standard_parameters'))->toBeFalse();
});

it("serves no blueprint for the lookup collections, so a host without its own gets Statamic's default", function (string $handle): void {
    $collection = Collection::make($handle);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();

    $blueprint = $collection->entryBlueprint();
    Assert::assertInstanceOf(StatamicBlueprint::class, $blueprint);

    $fields = lookupFieldHandles($blueprint);

    expect($fields)->not->toContain('members')
        ->and($fields)->not->toContain('value');
})->with([TaggedUrlLookup::LISTS_COLLECTION, TaggedUrlLookup::OPTIONS_COLLECTION]);

it("resolves a lookup collection's entry blueprint from the reference file copied into the host", function (string $handle, string $file, string $field): void {
    File::ensureDirectoryExists(Blueprint::directory().'/collections/'.$handle);
    File::copy(lookupReferencePath($file), Blueprint::directory().'/collections/'.$handle.'/'.$file);
    Blink::flush();

    $collection = Collection::make($handle);
    Assert::assertInstanceOf(StatamicCollection::class, $collection);
    $collection->save();

    $blueprint = $collection->entryBlueprint();
    Assert::assertInstanceOf(StatamicBlueprint::class, $blueprint);

    expect($blueprint->hasField($field))->toBeTrue();
})->with([
    'the lists collection' => [TaggedUrlLookup::LISTS_COLLECTION, 'lookup_list.yaml', 'members'],
    'the options collection' => [TaggedUrlLookup::OPTIONS_COLLECTION, 'lookup_option.yaml', 'list'],
]);

it('builds the publish form and field meta the Control Panel renders for each blueprint', function (Closure $blueprint): void {
    // The entries fields read the lists collection, which the host defines
    $lists = Collection::make(TaggedUrlLookup::LISTS_COLLECTION);
    Assert::assertInstanceOf(StatamicCollection::class, $lists);
    $lists->save();

    $resolved = $blueprint();
    Assert::assertInstanceOf(StatamicBlueprint::class, $resolved);

    $meta = $resolved->fields()->addValues([])->preProcess()->meta()->all();

    expect($resolved->toPublishArray())->toHaveKey('tabs')
        ->and(array_keys($meta))->toBe(lookupFieldHandles($resolved));
})->with([
    'the lists collection' => [fn (): StatamicBlueprint => lookupEntryBlueprint('lookup_list.yaml')],
    'the options collection' => [fn (): StatamicBlueprint => lookupEntryBlueprint('lookup_option.yaml')],
    'the parameters global set' => [fn (): StatamicBlueprint => lookupParametersBlueprint()],
]);
