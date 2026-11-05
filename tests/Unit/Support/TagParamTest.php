<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use UAMSWeb\Aether\Redirects\Support\TagParam;

covers(TagParam::class);

/**
 * @covers \UAMSWeb\Aether\Redirects\Support\TagParam
 *
 * Mutation: 100% on UAMSWeb\Aether\Redirects\Support\TagParam (6 mutants, --covered-only).
 *
 * @command  vendor/bin/pest tests/Unit/Support/TagParamTest.php
 */
it('returns an array unchanged', function (): void {
    $raw = [
        'a' => 1,
        'b' => 'two',
    ];

    expect(TagParam::all($raw))->toBe($raw);
});

it('unwraps a collection via all()', function (): void {
    /** @var Collection<string, mixed> $collection */
    $collection = Collection::make([
        'x' => 10,
        'y' => 20,
    ]);

    expect(TagParam::all($collection))->toBe([
        'x' => 10,
        'y' => 20,
    ]);
});

it('reads a key from an array and a collection', function (): void {
    /** @var Collection<string, mixed> $collection */
    $collection = Collection::make(['handle' => 'ok']);

    expect(TagParam::get(['handle' => 'ok'], 'handle'))->toBe('ok');
    expect(TagParam::get($collection, 'handle'))->toBe('ok');
});

it('uses the default when the key is missing', function (): void {
    expect(TagParam::get([], 'missing', 'fallback'))->toBe('fallback');
});
