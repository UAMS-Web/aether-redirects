<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\Fieldtype;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test points the blueprint and fieldset directories at its own.
uses(IsolatesStache::class);

// The rows' target URL field is imported from the host's common fieldset
beforeEach(function (): void {
    File::ensureDirectoryExists(Fieldset::directory());
    File::copy(__DIR__.'/../../Fixtures/fieldsets/uamsweb_common.yaml', Fieldset::directory().'/uamsweb_common.yaml');
});

/**
 * The `redirects` global set's blueprint, which this package serves when the host
 * has none of its own (UAMS-Web/uams-statamic#398).
 *
 * @command  vendor/bin/pest tests/Integration/Redirects/RedirectsBlueprintTest.php
 */

/**
 * The `redirects` global set's blueprint, as its variables resolve it.
 */
function redirectsBlueprint(): StatamicBlueprint
{
    $global = GlobalSet::make('redirects');
    Assert::assertInstanceOf(StatamicGlobalSet::class, $global);
    $global->title('Redirects');
    $global->save();

    $variables = $global->makeLocalization('default');
    Assert::assertInstanceOf(Variables::class, $variables);

    $blueprint = $variables->blueprint();
    Assert::assertInstanceOf(StatamicBlueprint::class, $blueprint);

    return $blueprint;
}

/**
 * The fields of a redirect row, by handle.
 *
 * @return array<string, Field> The fields.
 */
function redirectRowFields(): array
{
    $replicator = redirectsBlueprint()->field('redirects');
    Assert::assertInstanceOf(Field::class, $replicator);

    /** @var array{sets: array{redirect_group: array{sets: array{redirect: array{fields: list<array{handle: string, field: array<string, mixed>|string}>}}}}} $config */
    $config = $replicator->config();

    $resolved = [];

    foreach (new Fields($config['sets']['redirect_group']['sets']['redirect']['fields'])->all() as $handle => $field) {
        Assert::assertInstanceOf(Field::class, $field);
        $resolved[(string) $handle] = $field;
    }

    return $resolved;
}

it("serves this package's blueprint when the host has none", function (): void {
    $replicator = redirectsBlueprint()->field('redirects');

    Assert::assertInstanceOf(Field::class, $replicator);

    expect($replicator->type())->toBe('replicator')
        ->and($replicator->isLocalizable())->toBeFalse();
});

it('declares every field the engine reads from a redirect row', function (): void {

    expect(array_keys(redirectRowFields()))->toContain(
        'enabled',
        'source',
        'use_regex',
        'target_type',
        'target_entry',
        'target_query_string',
        'target_url',
        'response',
        'order',
        'message',
        'valid_from',
        'valid_until',
        'status',
    );
});

it("reads the target URL field from the host's common fieldset", function (): void {

    expect(redirectRowFields()['target_url']->type())->toBe('text')
        ->and(redirectRowFields()['target_url']->get('input_type'))->toBe('url');
});

it('offers each response code as a string, with the default among them', function (): void {
    $fieldtype = redirectRowFields()['response']->fieldtype();
    Assert::assertInstanceOf(Fieldtype::class, $fieldtype);

    /** @var array{options: list<array{value: mixed, label: mixed}>} $preload */
    $preload = $fieldtype->preload();
    $options = array_column($preload['options'], 'value');

    // The Control Panel's radio selects an option only when its key is identical
    // to the stored value, and every writer stores a string
    expect($options)->toBe(['301', '302', '303', '304', '307', '308', '403', '404', '410', '451'])
        ->and(redirectRowFields()['response']->defaultValue())->toBe('301');
});

it("uses the host's own blueprint when it has one", function (): void {
    File::ensureDirectoryExists(Blueprint::directory().'/globals');
    File::put(Blueprint::directory().'/globals/redirects.yaml', "fields:\n  -\n    handle: host_field\n    field:\n      type: text\n");
    Blink::flush();

    $blueprint = redirectsBlueprint();

    expect($blueprint->hasField('host_field'))->toBeTrue()
        ->and($blueprint->hasField('redirects'))->toBeFalse();
});
