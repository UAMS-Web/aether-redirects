<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Services\RedirectCollisionDetector;
use UAMSWeb\Aether\Redirects\Support\ValidityWindow;

covers(RedirectCollisionDetector::class);

/**
 * Unit tests for {@see RedirectCollisionDetector} (UAMS-Web/uams-statamic-archive#1356).
 *
 * @command  vendor/bin/pest tests/Unit/Services/RedirectCollisionDetectorTest.php
 */

/**
 * Build a stored redirect row with an optional validity window.
 *
 * @return array<string, mixed>
 */
function collisionRow(string $source, ?string $from = null, ?string $until = null): array
{
    return [
        'id' => 'row-'.md5($source.$from.$until),
        'type' => 'redirect',
        'source' => $source,
        'target_url' => '/existing-target',
        'valid_from' => $from,
        'valid_until' => $until,
    ];
}

it('rejects a redirect whose source and window overlap an existing one', function (): void {
    $detector = new RedirectCollisionDetector;

    /** @var array<string, mixed> $conflict */
    $conflict = $detector->firstConflict(
        '/flu',
        ValidityWindow::fromDates('2026-01-05', '2026-01-15'),
        [collisionRow('/flu', '2026-01-10', '2026-01-20')],
    );

    expect($conflict)->not->toBeNull()
        ->and($conflict['source'])->toBe('/flu');
});

it('allows two redirects on the same source with non-overlapping windows', function (): void {
    $detector = new RedirectCollisionDetector;

    $conflict = $detector->firstConflict(
        '/flu',
        ValidityWindow::fromDates('2026-02-01', '2026-02-10'),
        [collisionRow('/flu', '2026-01-01', '2026-01-31')],
    );

    expect($conflict)->toBeNull();
});

it('collides a windowless candidate with any redirect on the same source', function (): void {
    $detector = new RedirectCollisionDetector;

    $conflict = $detector->firstConflict(
        '/flu',
        new ValidityWindow,
        [collisionRow('/flu', '2030-01-01', '2030-01-31')],
    );

    expect($conflict)->not->toBeNull();
});

it('collides a bounded candidate with an existing windowless redirect on the same source', function (): void {
    $detector = new RedirectCollisionDetector;

    $conflict = $detector->firstConflict(
        '/flu',
        ValidityWindow::fromDates('2030-06-01', '2030-06-30'),
        [collisionRow('/flu')],
    );

    expect($conflict)->not->toBeNull();
});

it('matches sources case-insensitively and ignoring trailing slashes', function (): void {
    $detector = new RedirectCollisionDetector;

    /** @var array<string, mixed> $conflict */
    $conflict = $detector->firstConflict(
        '/Flu/',
        new ValidityWindow,
        [collisionRow('/flu')],
    );

    expect($conflict)->not->toBeNull()
        ->and($conflict['source'])->toBe('/flu');
});

it('does not collide with a different source', function (): void {
    $detector = new RedirectCollisionDetector;

    $conflict = $detector->firstConflict(
        '/flu',
        new ValidityWindow,
        [collisionRow('/covid'), collisionRow('/measles', '2026-01-01', '2026-12-31')],
    );

    expect($conflict)->toBeNull();
});

it('returns the first conflicting row when several overlap', function (): void {
    $detector = new RedirectCollisionDetector;

    /** @var array<string, mixed> $conflict */
    $conflict = $detector->firstConflict(
        '/flu',
        new ValidityWindow,
        [
            collisionRow('/other'),
            collisionRow('/flu', null, '2026-06-30'),
            collisionRow('/flu', '2026-07-01'),
        ],
    );

    expect($conflict)->not->toBeNull()
        ->and($conflict['valid_until'])->toBe('2026-06-30');
});

it('skips non-array rows and malformed rows without a string source', function (): void {
    $detector = new RedirectCollisionDetector;

    /** @var array<string, mixed> $conflict */
    $conflict = $detector->firstConflict(
        '/flu',
        new ValidityWindow,
        [
            'not-a-row',
            12,
            null,
            ['source' => 42],
            ['no_source' => true],
            collisionRow('/flu'),
        ],
    );

    expect($conflict)->not->toBeNull()
        ->and($conflict['source'])->toBe('/flu');
});

it('returns null when every stored row is skipped or non-overlapping', function (): void {
    $detector = new RedirectCollisionDetector;

    expect($detector->firstConflict(
        '/flu',
        ValidityWindow::fromDates('2026-02-01', '2026-02-10'),
        [
            'skip-me',
            ['source' => null],
            collisionRow('/flu', '2026-01-01', '2026-01-31'),
        ],
    ))->toBeNull();
});
