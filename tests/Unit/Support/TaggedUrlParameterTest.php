<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;

covers(TaggedUrlParameter::class);

/**
 * Unit tests for {@see TaggedUrlParameter}: the parameters a tagged URL carries
 * and where each value comes from (UAMS-Web/aether-redirects#26).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter
 *
 * @command  vendor/bin/pest tests/Unit/Support/TaggedUrlParameterTest.php
 */

/**
 * Each parameter as name, source type and source.
 *
 * @param  list<TaggedUrlParameter>  $parameters  The parameters.
 * @return list<array{0: string, 1: string, 2: string}>
 */
function taggedParameterRows(array $parameters): array
{
    return array_map(static fn (TaggedUrlParameter $p): array => [$p->name, $p->from, $p->source], $parameters);
}

it('maps every standard parameter to its cascade step or request field', function (): void {
    $all = TaggedUrlParameter::fromGlobal(array_keys(TaggedUrlParameter::standard()), null);

    expect(taggedParameterRows($all))->toBe([
        ['utm_id', 'request_field', 'utm_id'],
        ['utm_source', 'step', 'source'],
        ['utm_medium', 'step', 'medium'],
        ['utm_campaign', 'request_field', 'utm_campaign'],
        ['utm_source_platform', 'step', 'source_platform'],
        ['utm_term', 'step', 'term'],
        ['utm_content', 'step', 'content'],
        ['utm_creative_format', 'step', 'creative_format'],
        ['utm_marketing_tactic', 'request_field', 'utm_marketing_tactic'],
    ]);
});

it('carries only the checked standard parameters, in the standard order', function (): void {
    $parameters = TaggedUrlParameter::fromGlobal(['utm_campaign', 'utm_source', 'not_standard'], []);

    expect(array_column(taggedParameterRows($parameters), 0))->toBe(['utm_source', 'utm_campaign']);
});

it('carries no standard parameter when none is checked or the value is not a list', function (mixed $standard): void {
    expect(TaggedUrlParameter::fromGlobal($standard, []))->toBe([]);
})->with(['null' => [null], 'empty' => [[]], 'a string' => ['utm_source'], 'a non-string value' => [[true]]]);

it('adds the enabled custom parameters after the standard ones, by order, ties in grid order', function (): void {
    $parameters = TaggedUrlParameter::fromGlobal(['utm_source'], [
        ['name' => 'vanity', 'order' => 3, 'source_type' => 'request_field', 'request_field' => 'vanity_path'],
        ['name' => 'org_unit', 'order' => 1, 'source_type' => 'list', 'list' => ['list-org']],
        ['name' => 'qr', 'order' => '3', 'source_type' => 'fixed_value', 'fixed_value' => '1'],
        ['name' => 'region', 'source_type' => 'list', 'list' => 'list-region'],
    ]);

    expect(taggedParameterRows($parameters))->toBe([
        ['utm_source', 'step', 'source'],
        ['region', 'list', 'list-region'],
        ['org_unit', 'list', 'list-org'],
        ['vanity', 'request_field', 'vanity_path'],
        ['qr', 'fixed_value', '1'],
    ]);
});

it('compares orders as whole numbers, and gives a row with no order zero', function (): void {
    $parameters = TaggedUrlParameter::fromGlobal([], [
        ['name' => 'a', 'order' => '1.5', 'source_type' => 'fixed_value', 'fixed_value' => 'x'],
        ['name' => 'b', 'order' => 1, 'source_type' => 'fixed_value', 'fixed_value' => 'x'],
        ['name' => 'c', 'order' => 0, 'source_type' => 'fixed_value', 'fixed_value' => 'x'],
        ['name' => 'd', 'source_type' => 'fixed_value', 'fixed_value' => 'x'],
        ['name' => 'e', 'order' => 'soon', 'source_type' => 'fixed_value', 'fixed_value' => 'x'],
    ]);

    expect(array_column(taggedParameterRows($parameters), 0))->toBe(['c', 'd', 'e', 'a', 'b']);
});

it('treats a custom row with no source type as a list, as the blueprint defaults it', function (): void {
    $parameters = TaggedUrlParameter::fromGlobal([], [['name' => 'agency', 'list' => ['list-agency']]]);

    expect(taggedParameterRows($parameters))->toBe([['agency', 'list', 'list-agency']]);
});

it('leaves out a custom row that is off or unusable', function (mixed $row): void {
    expect(TaggedUrlParameter::fromGlobal([], [$row]))->toBe([]);
})->with([
    'switched off' => [['enabled' => false, 'name' => 'job', 'source_type' => 'fixed_value', 'fixed_value' => 'x']],
    'no name' => [['source_type' => 'fixed_value', 'fixed_value' => 'x']],
    'a name with a space' => [['name' => 'org unit', 'source_type' => 'fixed_value', 'fixed_value' => 'x']],
    'a name with a trailing newline' => [['name' => "job\n", 'source_type' => 'fixed_value', 'fixed_value' => 'x']],
    'an unknown source type' => [['name' => 'job', 'source_type' => 'other', 'fixed_value' => 'x']],
    'a list type with no list' => [['name' => 'job', 'source_type' => 'list', 'list' => []]],
    'a request field type with an empty handle' => [['name' => 'job', 'source_type' => 'request_field', 'request_field' => '']],
    'a fixed type with no value' => [['name' => 'job', 'source_type' => 'fixed_value']],
    'not a row' => ['job'],
]);

it('keeps a custom row whose enabled flag is on or unset', function (mixed $enabled): void {
    $row = ['name' => 'job', 'source_type' => 'fixed_value', 'fixed_value' => 'x'];

    if ($enabled !== 'unset') {
        $row['enabled'] = $enabled;
    }

    expect(TaggedUrlParameter::fromGlobal([], [$row]))->toHaveCount(1);
})->with(['on' => [true], 'unset' => ['unset']]);

it('reads no custom parameters when the grid value is not a list', function (): void {
    expect(TaggedUrlParameter::fromGlobal([], 'not a grid'))->toBe([]);
});

it('reads the first id of an entries field value', function (mixed $value, ?string $expected): void {
    expect(TaggedUrlParameter::firstId($value))->toBe($expected);
})->with([
    'an id' => ['abc', 'abc'],
    'a list of ids' => [['abc', 'def'], 'abc'],
    'an empty list' => [[], null],
    'an empty string' => ['', null],
    'null' => [null, null],
    'a number' => [5, null],
]);
