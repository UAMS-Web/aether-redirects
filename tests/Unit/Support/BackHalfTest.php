<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;

covers(BackHalf::class);

/**
 * Unit tests for {@see BackHalf}, the generated 7-character base-62 back-half
 * of a vanity URL request (UAMS-Web/aether-redirects#15).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\BackHalf
 *
 * @command  vendor/bin/pest tests/Unit/Support/BackHalfTest.php
 */
it('uses exactly the 62 characters A-Z, a-z and 0-9', function (): void {
    $expected = [...range('A', 'Z'), ...range('a', 'z'), ...range('0', '9')];

    expect(str_split(BackHalf::ALPHABET))->toBe(array_map(strval(...), $expected))
        ->and(BackHalf::generator()->alphabet)->toBe(BackHalf::ALPHABET)
        ->and(BackHalf::generator()->length)->toBe(7)
        ->and(BackHalf::generator()->maxAttempts)->toBe(10);
});

it('generates 7 alphabet characters, and every character occurs over a large sample', function (): void {
    $seen = [];

    // 2,000 back-halves are 14,000 characters: the chance of missing a given one is about e^-226.
    for ($draw = 0; $draw < 2000; $draw++) {
        $backHalf = BackHalf::generate([]);

        expect($backHalf)->toMatch('/\A[A-Za-z0-9]{7}\z/');

        foreach (str_split($backHalf) as $character) {
            $seen[$character] = true;
        }
    }

    expect(\count($seen))->toBe(62);
});

it('treats a back-half as issued by a row with the same source in any case', function (string $candidate, string $stored): void {
    expect(BackHalf::isIssued($candidate, [['source' => $stored]]))->toBeTrue();
})->with([
    'same case' => ['aB3xY9q', '/aB3xY9q'],
    'lowercase draw against mixed case' => ['ab3xy9q', '/aB3xY9q'],
    'uppercase draw against mixed case' => ['AB3XY9Q', '/aB3xY9q'],
    'candidate with a leading slash' => ['/ab3xy9q', '/aB3xY9q'],
    'stored without a leading slash' => ['ab3xy9q', 'aB3xY9q'],
]);

it('counts pending, approved and legacy rows as issuing their source', function (array $row): void {
    /** @var array<string, mixed> $row */
    expect(BackHalf::isIssued('aB3xY9q', [$row]))->toBeTrue();
})->with([
    'pending request' => [['source' => '/aB3xY9q', 'status' => 'pending']],
    'approved redirect' => [['source' => '/aB3xY9q', 'status' => 'approved']],
    'legacy row with no status' => [['source' => '/aB3xY9q']],
]);

it('counts a redirect whose validity window has ended, so a back-half is never issued twice', function (): void {
    $expired = [
        'source' => '/aB3xY9q',
        'status' => 'approved',
        'valid_from' => '2020-01-01',
        'valid_until' => '2020-01-31',
    ];

    expect(BackHalf::isIssued('ab3xy9q', [$expired]))->toBeTrue();
});

it('counts a redirect whose validity window has not started yet', function (): void {
    $future = ['source' => '/aB3xY9q', 'valid_from' => '2999-01-01'];

    expect(BackHalf::isIssued('aB3xY9q', [$future]))->toBeTrue();
});

it('does not count a rejected request, which never served', function (): void {
    $rejected = ['source' => '/aB3xY9q', 'status' => 'rejected', 'rejection_reason' => 'Duplicate'];

    expect(BackHalf::isIssued('aB3xY9q', [$rejected]))->toBeFalse();
});

it('does not count a different source', function (): void {
    expect(BackHalf::isIssued('aB3xY9q', [['source' => '/aB3xY9r'], ['source' => '/aB3xY9q/x']]))->toBeFalse();
});

it('ignores rows that are not usable redirect rows, and still finds a later match', function (): void {
    $rows = ['not a row', ['target' => '/somewhere'], ['source' => 42], ['source' => '/aB3xY9q']];

    expect(BackHalf::isIssued('ab3xy9q', $rows))->toBeTrue()
        ->and(BackHalf::isIssued('ab3xy9q', \array_slice($rows, 0, 3)))->toBeFalse();
});

it('finds a match after a rejected row with the same source', function (): void {
    $rows = [
        ['source' => '/aB3xY9q', 'status' => 'rejected'],
        ['source' => '/AB3XY9Q', 'status' => 'pending'],
    ];

    expect(BackHalf::isIssued('aB3xY9q', $rows))->toBeTrue();
});

it('replaces a draw that matches an issued back-half in another case', function (): void {
    // Over a two-letter alphabet, `A` is issued (as `/a`), so every draw of `A` is redrawn.
    $generator = new RandomCode('AB', 1, 200);

    for ($draw = 0; $draw < 50; $draw++) {
        expect(BackHalf::generate([['source' => '/a', 'status' => 'pending']], $generator))->toBe('B');
    }
});

it('reads rows from a one-pass iterable on every redraw', function (): void {
    // Half of the runs draw `A` first and must read the rows a second time.
    for ($draw = 0; $draw < 40; $draw++) {
        $rows = (static function (): Generator {
            yield ['source' => '/A'];
        })();

        expect(BackHalf::generate($rows, new RandomCode('AB', 1, 200)))->toBe('B');
    }
});

it('reports failure when every possible back-half is issued', function (): void {
    $rows = [['source' => '/a'], ['source' => '/b']];

    expect(fn (): string => BackHalf::generate($rows, new RandomCode('AB', 1, 5)))
        ->toThrow(CodeGenerationException::class, 'after 5 attempts');
});

it('reads the generated flag only when it is exactly true', function (array $row, bool $generated): void {
    /** @var array<string, mixed> $row */
    expect(BackHalf::wasGenerated($row))->toBe($generated);
})->with([
    'flagged' => [[BackHalf::FIELD => true, 'source' => '/aB3xY9q'], true],
    'flagged false' => [[BackHalf::FIELD => false, 'source' => '/typed'], false],
    'no flag' => [['source' => '/typed'], false],
    'a truthy string' => [[BackHalf::FIELD => '1'], false],
]);
