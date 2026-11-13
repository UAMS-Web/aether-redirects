<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Support\ShortCode;

covers(ShortCode::class);

/**
 * Unit tests for {@see ShortCode}, the generated code of a `go.uams.edu` short link
 * (UAMS-Web/aether-redirects#84).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\ShortCode
 *
 * @command  vendor/bin/pest tests/Unit/Support/ShortCodeTest.php
 */
it('uses lowercase letters and digits without the look-alikes 0, o, 1, l and i', function (): void {
    $expected = array_values(array_diff([...range('a', 'z'), ...range('2', '9')], ['i', 'l', 'o']));

    expect(str_split(ShortCode::ALPHABET))->toBe(array_map(strval(...), $expected))
        ->and(strlen(ShortCode::ALPHABET))->toBe(31)
        ->and(ShortCode::ALPHABET)->not->toMatch('/[01oli]/')
        ->and(ShortCode::HOST)->toBe('go.uams.edu')
        ->and(ShortCode::generator()->alphabet)->toBe(ShortCode::ALPHABET)
        ->and(ShortCode::generator()->length)->toBe(6)
        ->and(ShortCode::generator()->maxAttempts)->toBe(10);
});

it('generates 6 alphabet characters, and every character occurs over a large sample', function (): void {
    $seen = [];

    // 2,000 codes are 12,000 characters: the chance of missing a given one is about e^-387.
    for ($draw = 0; $draw < 2000; $draw++) {
        $code = ShortCode::generate([]);

        expect($code)->toMatch('/\A[a-hjkmnp-z2-9]{6}\z/');

        foreach (str_split($code) as $character) {
            $seen[$character] = true;
        }
    }

    expect(count($seen))->toBe(31);
});

it('replaces a draw that matches an existing source on the host, a vanity path or an imported rule', function (array $row): void {
    // Over a two-letter alphabet, `a` is taken, so every draw of `a` is redrawn.
    $generator = new RandomCode('ab', 1, 200);

    for ($draw = 0; $draw < 40; $draw++) {
        expect(ShortCode::generate([$row], $generator))->toBe('b');
    }
})->with([
    'an imported rule with no status' => [['source' => '/a']],
    'an approved vanity path in another case' => [['source' => '/A', 'status' => 'approved']],
    'a pending code' => [['source' => '/a', 'status' => 'pending']],
]);

it('never reissues the code of a rule whose window has ended', function (): void {
    $expired = ['source' => '/a', 'status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31'];

    for ($draw = 0; $draw < 40; $draw++) {
        expect(ShortCode::generate([$expired], new RandomCode('ab', 1, 200)))->toBe('b');
    }
});

it('reads rows from a one-pass iterable on every redraw', function (): void {
    for ($draw = 0; $draw < 40; $draw++) {
        $rows = (static function (): Generator {
            yield ['source' => '/a'];
        })();

        expect(ShortCode::generate($rows, new RandomCode('ab', 1, 200)))->toBe('b');
    }
});

it('reports failure when every possible code is taken', function (): void {
    expect(fn (): string => ShortCode::generate([['source' => '/a'], ['source' => '/b']], new RandomCode('ab', 1, 3)))
        ->toThrow(CodeGenerationException::class, 'after 3 attempts');
});

it('recognizes the short-code format', function (string $value, bool $expected): void {
    expect(ShortCode::isCode($value))->toBe($expected);
})->with([
    'a code' => ['abc234', true],
    'every letter kind' => ['zyx987', true],
    'five characters' => ['abc23', false],
    'seven characters' => ['abc2345', false],
    'a zero' => ['abc230', false],
    'an o' => ['abco34', false],
    'a one' => ['abc214', false],
    'an l' => ['abcl34', false],
    'an i' => ['abci34', false],
    'uppercase' => ['ABC234', false],
    'a leading slash' => ['/abc23', false],
    'empty' => ['', false],
]);
