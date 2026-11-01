<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\ServiceProvider;

/**
 * `composer.json` registers the add-on's service provider for Laravel package
 * discovery, which is how a host application boots it.
 *
 * @command  vendor/bin/pest tests/Unit/PackageTest.php
 */
it('lists its service provider in composer.json so the host discovers it', function (): void {
    // Read composer.json and confirm it was readable
    $contents = file_get_contents(__DIR__.'/../../composer.json');
    expect($contents)->toBeString();

    // Decode the manifest
    /** @var array{extra: array{laravel: array{providers: list<string>}}} $manifest */
    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    // Expect the provider among the discovered providers
    expect($manifest['extra']['laravel']['providers'])->toContain(ServiceProvider::class);
});

it('autoloads its service provider under the UAMSWeb\\Aether\\Redirects namespace', function (): void {
    // Read the PSR-4 map from composer.json
    /** @var array{autoload: array{'psr-4': array<string, string>}} $manifest */
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // Expect the new prefix to map to src/ and the old prefix to be gone
    expect($manifest['autoload']['psr-4'])->toBe(['UAMSWeb\\Aether\\Redirects\\' => 'src/']);

    // Expect the class to load through the autoloader, not merely to name a string
    expect(class_exists(ServiceProvider::class))->toBeTrue()
        ->and(class_exists('UAMSWeb\\Redirects\\ServiceProvider'))->toBeFalse();
});
