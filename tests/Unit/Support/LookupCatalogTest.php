<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\LookupCatalog;
use UAMSWeb\Aether\Redirects\Support\LookupOption;

covers(LookupCatalog::class, LookupOption::class);

/**
 * Unit tests for {@see LookupCatalog}: composed lists and the cascade between
 * lookup lists (UAMS-Web/aether-redirects#26, rules from UAMS-Web/aether-redirects#45).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\LookupCatalog
 * @covers \UAMSWeb\Aether\Redirects\Support\LookupOption
 *
 * @command  vendor/bin/pest tests/Unit/Support/LookupCatalogTest.php
 */

/**
 * The values of a list of options, in order.
 *
 * @param  list<LookupOption>  $options  The options.
 * @return list<string>
 */
function lookupValues(array $options): array
{
    return array_map(static fn (LookupOption $option): string => $option->value, $options);
}

it("offers a list's active options in order, keeping stored order on ties", function (): void {
    $catalog = new LookupCatalog([], [
        new LookupOption('o1', 'social', 'Instagram', 'instagram', order: 2),
        new LookupOption('o2', 'social', 'Facebook', 'facebook', order: 1),
        new LookupOption('o3', 'social', 'Retired', 'myspace', active: false, order: 0),
        new LookupOption('o4', 'social', 'LinkedIn', 'linkedin', order: 2),
        new LookupOption('o5', 'print', 'Poster', 'poster'),
    ]);

    expect(lookupValues($catalog->options('social')))->toBe(['facebook', 'instagram', 'linkedin'])
        ->and(lookupValues($catalog->options('print')))->toBe(['poster']);
});

it('offers nothing for an unknown list, and ignores an option that belongs to no list', function (): void {
    $catalog = new LookupCatalog([], [new LookupOption('o1', null, 'Loose', 'loose')]);

    expect($catalog->options('missing'))->toBe([])
        ->and($catalog->option('o1'))->toBeNull();
});

it('shows a composed list as its own options then its members in member order, without repeats', function (): void {
    $catalog = new LookupCatalog(
        ['all' => ['digital', 'print']],
        [
            new LookupOption('a1', 'all', 'Other', 'other'),
            new LookupOption('d1', 'digital', 'Email', 'email'),
            new LookupOption('d2', 'digital', 'Web', 'web', order: 1),
            new LookupOption('p1', 'print', 'Web flyer', 'web'),
            new LookupOption('p2', 'print', 'Poster', 'poster', order: 1),
            new LookupOption('p3', 'print', 'Other print', 'other', order: 2),
        ],
    );

    $options = $catalog->options('all');

    expect(lookupValues($options))->toBe(['other', 'email', 'web', 'poster'])
        ->and($options[2]->id)->toBe('d2')
        ->and($options[0]->id)->toBe('a1');
});

it('reads nested members and stops at a member that leads back to a list being read', function (): void {
    $catalog = new LookupCatalog(
        ['a' => ['b'], 'b' => ['c', 'a'], 'c' => ['a']],
        [
            new LookupOption('a1', 'a', 'A', 'a'),
            new LookupOption('b1', 'b', 'B', 'b'),
            new LookupOption('c1', 'c', 'C', 'c'),
        ],
    );

    expect(lookupValues($catalog->options('a')))->toBe(['a', 'b', 'c'])
        ->and(lookupValues($catalog->options('c')))->toBe(['c', 'a', 'b']);
});

it('reads a member list twice when two branches include it, still without repeats', function (): void {
    $catalog = new LookupCatalog(
        ['top' => ['left', 'right'], 'left' => ['shared'], 'right' => ['shared']],
        [
            new LookupOption('s1', 'shared', 'Shared', 'shared'),
            new LookupOption('r1', 'right', 'Right', 'right'),
        ],
    );

    expect(lookupValues($catalog->options('top')))->toBe(['shared', 'right']);
});

it('skips a retired option in a member list', function (): void {
    $catalog = new LookupCatalog(
        ['all' => ['print']],
        [new LookupOption('p1', 'print', 'Old', 'old', active: false), new LookupOption('p2', 'print', 'New', 'new')],
    );

    expect(lookupValues($catalog->options('all')))->toBe(['new']);
});

it('finds an option by id in any list, retired ones included', function (): void {
    $retired = new LookupOption('p1', 'print', 'Old', 'old', active: false);
    $catalog = new LookupCatalog([], [new LookupOption('d1', 'digital', 'Web', 'web'), $retired]);

    expect($catalog->option('p1'))->toBe($retired)
        ->and($catalog->option('d1')?->value)->toBe('web')
        ->and($catalog->option('nope'))->toBeNull();
});

it('uses the deepest choice that names a list for a step', function (): void {
    $channel = new LookupOption('c', 'channels', 'Social', 'social', pointers: ['medium' => 'social-media', 'placement' => 'social-placements']);
    $source = new LookupOption('s', 'social-sources', 'Facebook', 'facebook', pointers: ['medium' => 'facebook-media']);
    $platform = new LookupOption('p', 'facebook-platforms', 'Ads', 'ads');

    $choices = [$channel, $source, $platform];

    expect(LookupCatalog::listForStep('medium', $choices, 'generic-media'))->toBe('facebook-media')
        ->and(LookupCatalog::listForStep('placement', $choices, 'generic-placements'))->toBe('social-placements');
});

it("falls back to the step's generic list when no choice names one", function (): void {
    $choices = [new LookupOption('c', 'channels', 'Print', 'print', pointers: ['medium' => 'print-media'])];

    expect(LookupCatalog::listForStep('creative_format', $choices, 'generic-formats'))->toBe('generic-formats')
        ->and(LookupCatalog::listForStep('creative_format', [], 'generic-formats'))->toBe('generic-formats')
        ->and(LookupCatalog::listForStep('creative_format', $choices, null))->toBeNull();
});

it('reads a pointer by step and reports none for a step it does not name', function (): void {
    $option = new LookupOption('o', 'l', 'L', 'v', pointers: ['medium' => 'm']);

    expect($option->pointerFor('medium'))->toBe('m')
        ->and($option->pointerFor('placement'))->toBeNull()
        ->and($option->active)->toBeTrue()
        ->and($option->order)->toBe(0)
        ->and($option->label)->toBe('L');
});
