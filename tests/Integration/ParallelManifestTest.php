<?php

declare(strict_types=1);

use Illuminate\Foundation\PackageManifest;
use UAMSWeb\Aether\Redirects\Tests\TestCase;

/**
 * Pins the per-worker package manifest (#74): Testbench names the manifest file
 * after the worker only when `LARAVEL_PARALLEL_TESTING` is set, and Pest does not
 * set it for a package suite.
 */
describe('markParallelRun', function (): void {
    beforeEach(function (): void {
        $this->saved = [$_SERVER['LARAVEL_PARALLEL_TESTING'] ?? null, $_ENV['LARAVEL_PARALLEL_TESTING'] ?? null];
        unset($_SERVER['LARAVEL_PARALLEL_TESTING'], $_ENV['LARAVEL_PARALLEL_TESTING']);
    });

    afterEach(function (): void {
        if ($this->saved[0] === null) {
            unset($_SERVER['LARAVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['LARAVEL_PARALLEL_TESTING'] = $this->saved[0];
        }

        if ($this->saved[1] === null) {
            unset($_ENV['LARAVEL_PARALLEL_TESTING']);
        } else {
            $_ENV['LARAVEL_PARALLEL_TESTING'] = $this->saved[1];
        }
    });

    it('sets the variable for a worker token', function (mixed $token): void {
        expect(TestCase::markParallelRun($token))->toBeTrue()
            ->and($_SERVER['LARAVEL_PARALLEL_TESTING'])->toBe('1')
            ->and($_ENV['LARAVEL_PARALLEL_TESTING'])->toBe('1');
    })->with(['string token' => ['3'], 'integer token' => [3]]);

    it('leaves the variable unset outside a parallel run', function (mixed $token): void {
        expect(TestCase::markParallelRun($token))->toBeFalse()
            ->and(isset($_SERVER['LARAVEL_PARALLEL_TESTING']))->toBeFalse();
    })->with(['no token' => [null], 'array token' => [[]]]);

    it('does not report a variable that was already set', function (): void {
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';

        expect(TestCase::markParallelRun('3'))->toBeFalse();
    });
});

it('names the package manifest after the worker in a parallel run', function (): void {
    $token = $_SERVER['TEST_TOKEN'] ?? null;
    if (! is_string($token) && ! is_int($token)) {
        $this->markTestSkipped('Only a parallel worker has a token.');
    }

    expect(basename((string) app(PackageManifest::class)->manifestPath))->toBe('test_'.$token.'_packages.php');
});
