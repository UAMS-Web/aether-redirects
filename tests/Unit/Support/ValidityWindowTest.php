<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use UAMSWeb\Aether\Redirects\Support\ValidityWindow;

/**
 * Unit tests for the redirect {@see ValidityWindow} value object — the shared
 * date-window logic behind capture (UAMS-Web/uams-statamic-archive#1355), collision detection (UAMS-Web/uams-statamic-archive#1356), and
 * serve-time gating.
 *
 * @command  vendor/bin/pest tests/Unit/Support/ValidityWindowTest.php
 */
it('treats blank and null date strings as open bounds', function (?string $from, ?string $until): void {
    $window = ValidityWindow::fromDates($from, $until);

    expect($window->from)->toBeNull()
        ->and($window->until)->toBeNull();
})->with([
    'both null' => [null, null],
    'both blank' => ['', ''],
    'whitespace' => ['   ', "\t"],
]);

it('snaps from to start-of-day and until to end-of-day', function (): void {
    $window = ValidityWindow::fromDates('2026-01-10', '2026-01-20');

    expect($window->from?->toDateTimeString())->toBe('2026-01-10 00:00:00')
        ->and($window->until?->toDateTimeString())->toBe('2026-01-20 23:59:59');
});

it('reads both bounds from a stored row', function (): void {
    $window = ValidityWindow::fromRow([
        'valid_from' => '2026-02-01',
        'valid_until' => '2026-02-28',
    ]);

    expect($window->from?->toDateString())->toBe('2026-02-01')
        ->and($window->until?->toDateString())->toBe('2026-02-28');
});

it('reads bounds from a stored row and ignores non-string values', function (): void {
    $window = ValidityWindow::fromRow([
        'valid_from' => '2026-02-01',
        'valid_until' => ['not', 'a', 'string'],
    ]);

    expect($window->from?->toDateString())->toBe('2026-02-01')
        ->and($window->until)->toBeNull();
});

it('trims whitespace around a date string before parsing', function (): void {
    $window = ValidityWindow::fromDates('  2026-01-10  ', "\t2026-01-20\n");

    expect($window->from?->toDateString())->toBe('2026-01-10')
        ->and($window->until?->toDateString())->toBe('2026-01-20');
});

it('an open window contains any moment', function (): void {
    $window = new ValidityWindow;

    expect($window->contains(CarbonImmutable::parse('1999-12-31')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2099-01-01')))->toBeTrue();
});

it('contains is inclusive of both day boundaries', function (): void {
    $window = ValidityWindow::fromDates('2026-01-10', '2026-01-20');

    // The whole of the first and last day is inside the window.
    expect($window->contains(CarbonImmutable::parse('2026-01-10 00:00:00')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-01-20 23:59:59')))->toBeTrue()
        // A moment on the day before / day after is outside.
        ->and($window->contains(CarbonImmutable::parse('2026-01-09 23:59:59')))->toBeFalse()
        ->and($window->contains(CarbonImmutable::parse('2026-01-21 00:00:00')))->toBeFalse();
});

it('honors a half-open window on each side', function (): void {
    $openStart = ValidityWindow::fromDates(null, '2026-01-20');
    $openEnd = ValidityWindow::fromDates('2026-01-10', null);

    expect($openStart->contains(CarbonImmutable::parse('1990-01-01')))->toBeTrue()
        ->and($openStart->contains(CarbonImmutable::parse('2026-01-21')))->toBeFalse()
        ->and($openEnd->contains(CarbonImmutable::parse('2099-01-01')))->toBeTrue()
        ->and($openEnd->contains(CarbonImmutable::parse('2026-01-09')))->toBeFalse();
});

it('detects overlap between two bounded windows that intersect', function (): void {
    $a = ValidityWindow::fromDates('2026-01-01', '2026-01-15');
    $b = ValidityWindow::fromDates('2026-01-10', '2026-01-31');

    expect($a->overlaps($b))->toBeTrue()
        ->and($b->overlaps($a))->toBeTrue();
});

it('reports no overlap for disjoint bounded windows', function (): void {
    $a = ValidityWindow::fromDates('2026-01-01', '2026-01-10');
    $b = ValidityWindow::fromDates('2026-01-11', '2026-01-20');

    expect($a->overlaps($b))->toBeFalse()
        ->and($b->overlaps($a))->toBeFalse();
});

it('treats touching endpoints as an overlap', function (): void {
    $a = ValidityWindow::fromDates('2026-01-01', '2026-01-10');
    $b = ValidityWindow::fromDates('2026-01-10', '2026-01-20');

    // Both windows include the whole of 2026-01-10, so they share a day.
    expect($a->overlaps($b))->toBeTrue()
        ->and($b->overlaps($a))->toBeTrue();
});

it('an open window overlaps every other window', function (): void {
    $open = new ValidityWindow;
    $bounded = ValidityWindow::fromDates('2026-05-01', '2026-05-31');

    expect($open->overlaps($bounded))->toBeTrue()
        ->and($bounded->overlaps($open))->toBeTrue()
        ->and($open->overlaps(new ValidityWindow))->toBeTrue();
});
