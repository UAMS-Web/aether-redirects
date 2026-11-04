<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\RedirectStatus;

covers(RedirectStatus::class);

/**
 * Unit tests for the {@see RedirectStatus} enum — resolving a stored row's
 * approval status (grandfathering pre-#1357 rows) and deciding whether it may
 * serve traffic (UAMS-Web/uams-statamic-archive#1357).
 *
 * @command  vendor/bin/pest tests/Unit/Support/RedirectStatusTest.php
 */
it('resolves each explicit status from a stored row', function (string $stored, RedirectStatus $expected): void {
    expect(RedirectStatus::fromRow(['status' => $stored]))->toBe($expected);
})->with([
    'pending' => ['pending', RedirectStatus::Pending],
    'approved' => ['approved', RedirectStatus::Approved],
    'rejected' => ['rejected', RedirectStatus::Rejected],
]);

it('grandfathers a row with no status key as approved', function (): void {
    // Legacy globals-editor + WordPress-importer rows predate the field.
    expect(RedirectStatus::fromRow([]))->toBe(RedirectStatus::Approved)
        ->and(RedirectStatus::fromRow(['source' => '/old']))->toBe(RedirectStatus::Approved);
});

it('fails open to approved for a non-string or unrecognized status', function (mixed $value): void {
    expect(RedirectStatus::fromRow(['status' => $value]))->toBe(RedirectStatus::Approved);
})->with([
    'unknown string' => ['garbage'],
    'empty string' => [''],
    'null' => [null],
    'integer' => [1],
    'array' => [['pending']],
    'boolean' => [true],
]);

it('serves traffic only when approved', function (): void {
    expect(RedirectStatus::Approved->servesTraffic())->toBeTrue()
        ->and(RedirectStatus::Pending->servesTraffic())->toBeFalse()
        ->and(RedirectStatus::Rejected->servesTraffic())->toBeFalse();
});
