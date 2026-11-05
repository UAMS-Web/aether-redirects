<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\RedirectSource;

covers(RedirectSource::class);

/**
 * Unit tests for {@see RedirectSource} — source-path canonicalization for
 * storage and the lowercased collision key used by collision detection (UAMS-Web/uams-statamic-archive#1356).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\RedirectSource
 *
 * @command  vendor/bin/pest tests/Unit/Support/RedirectSourceTest.php
 */
it('normalizes a source path to a single leading slash and no trailing slash', function (string $raw, string $expected): void {
    expect(RedirectSource::normalize($raw))->toBe($expected);
})->with([
    'plain' => ['/flu-shot', '/flu-shot'],
    'missing leading slash' => ['flu-shot', '/flu-shot'],
    'trailing slash' => ['/flu-shot/', '/flu-shot'],
    'both fixes' => ['flu-shot/', '/flu-shot'],
    'surrounding whitespace' => ['  /flu-shot  ', '/flu-shot'],
    'multiple leading slashes' => ['///flu-shot', '/flu-shot'],
    'nested path' => ['/a/b/c/', '/a/b/c'],
    'empty' => ['', '/'],
    'whitespace only' => ['   ', '/'],
    'root' => ['/', '/'],
    'all slashes' => ['////', '/'],
    'case preserved' => ['/Flu-Shot', '/Flu-Shot'],
]);

it('derives a lowercased collision key from the canonical form', function (string $raw, string $expected): void {
    expect(RedirectSource::collisionKey($raw))->toBe($expected);
})->with([
    'lowercases' => ['/Flu-Shot', '/flu-shot'],
    'lowercases and trims slash' => ['/FLU/', '/flu'],
    'adds leading slash' => ['Flu', '/flu'],
    'root' => ['/', '/'],
]);

it('gives trivial case and slash variants the same collision key', function (): void {
    $key = RedirectSource::collisionKey('/flu');

    expect(RedirectSource::collisionKey('/Flu'))->toBe($key)
        ->and(RedirectSource::collisionKey('flu/'))->toBe($key)
        ->and(RedirectSource::collisionKey('  /FLU/  '))->toBe($key);
});
