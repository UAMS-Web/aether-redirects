<?php

declare(strict_types=1);

use Illuminate\Foundation\PackageManifest;

/**
 * Pins the per-worker package manifest (#74): Testbench names the manifest file
 * after the worker only when `LARAVEL_PARALLEL_TESTING` is set, and Pest does not
 * set it for a package suite, so `TestCase::setUp()` sets it.
 */
it('names the package manifest after the worker in a parallel run', function (): void {
    $token = $_SERVER['TEST_TOKEN'] ?? null;
    if (! is_string($token) && ! is_int($token)) {
        $this->markTestSkipped('Only a parallel worker has a token.');
    }

    expect(basename((string) app(PackageManifest::class)->manifestPath))->toBe('test_'.$token.'_packages.php');
});
