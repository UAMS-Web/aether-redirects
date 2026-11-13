<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;
use UAMSWeb\Aether\Redirects\Support\RandomCode;

covers(RandomCode::class, CodeGenerationException::class);

/**
 * Unit tests for {@see RandomCode}, the shared random code helper (UAMS-Web/aether-redirects#15).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\RandomCode
 * @covers \UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException
 *
 * @command  vendor/bin/pest tests/Unit/Support/RandomCodeTest.php
 */
it('draws codes of the configured length from the configured alphabet only', function (): void {
    $generator = new RandomCode('abc', 5, 1);

    for ($draw = 0; $draw < 200; $draw++) {
        expect($generator->draw())->toMatch('/\A[abc]{5}\z/');
    }
});

it('draws every character of the alphabet, the first and the last included', function (): void {
    $generator = new RandomCode('xyz', 1, 1);

    // 600 one-character draws: the chance of missing a given character is (2/3)^600.
    $seen = [];

    for ($draw = 0; $draw < 600; $draw++) {
        $seen[$generator->draw()] = true;
    }

    ksort($seen);

    expect(array_keys($seen))->toBe(['x', 'y', 'z']);
});

it('draws each character about equally often', function (): void {
    $generator = new RandomCode('xyz', 1, 1);
    $counts = ['x' => 0, 'y' => 0, 'z' => 0];

    // 3,000 draws expect 1,000 of each, with a standard deviation near 26; the bounds sit about 7.7 deviations out.
    for ($draw = 0; $draw < 3000; $draw++) {
        $counts[$generator->draw()]++;
    }

    foreach ($counts as $count) {
        expect($count)->toBeGreaterThan(800)->toBeLessThan(1200);
    }
});

it('returns the first draw when nothing is taken, calling the check once', function (): void {
    $calls = 0;

    $code = new RandomCode('ab', 4, 3)->generate(function (string $candidate) use (&$calls): bool {
        $calls++;

        return false;
    });

    expect($code)->toMatch('/\A[ab]{4}\z/')
        ->and($calls)->toBe(1);
});

it('redraws while a draw is taken, and returns the first free one', function (): void {
    $checked = [];

    // The first two draws are reported taken; the third is free.
    $code = new RandomCode('ab', 4, 3)->generate(function (string $candidate) use (&$checked): bool {
        $checked[] = $candidate;

        return \count($checked) < 3;
    });

    expect($checked)->toHaveCount(3)
        ->and($code)->toBe($checked[2]);
});

it('succeeds on the last allowed attempt', function (): void {
    $calls = 0;

    $code = new RandomCode('ab', 2, 3)->generate(function () use (&$calls): bool {
        $calls++;

        return $calls < 3;
    });

    expect($code)->toMatch('/\A[ab]{2}\z/')
        ->and($calls)->toBe(3);
});

it('stops after the allowed number of attempts with a message the requester can read', function (): void {
    $calls = 0;

    $generate = function () use (&$calls): void {
        new RandomCode('ab', 2, 4)->generate(function () use (&$calls): bool {
            $calls++;

            return true;
        });
    };

    expect($generate)->toThrow(
        CodeGenerationException::class,
        'A unique code could not be generated after 4 attempts. Try again, and report it if this keeps happening.',
    )->and($calls)->toBe(4);
});

it('refuses a configuration that cannot produce a usable code', function (string $alphabet, int $length, int $attempts, string $message): void {
    expect(fn (): RandomCode => new RandomCode($alphabet, $length, $attempts))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty alphabet' => ['', 3, 1, 'A code alphabet needs at least two characters.'],
    'one-character alphabet' => ['a', 3, 1, 'A code alphabet needs at least two characters.'],
    'repeated character' => ['aba', 3, 1, 'A code alphabet must not repeat a character.'],
    'zero length' => ['ab', 0, 1, 'A code needs at least one character.'],
    'negative length' => ['ab', -1, 1, 'A code needs at least one character.'],
    'zero attempts' => ['ab', 3, 0, 'A code generator needs at least one attempt.'],
]);

it('accepts the smallest usable configuration', function (): void {
    $generator = new RandomCode('ab', 1, 1);

    expect($generator->alphabet)->toBe('ab')
        ->and($generator->length)->toBe(1)
        ->and($generator->maxAttempts)->toBe(1)
        ->and($generator->draw())->toMatch('/\A[ab]\z/');
});
