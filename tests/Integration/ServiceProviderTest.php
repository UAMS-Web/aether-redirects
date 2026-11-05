<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Facades\Addon;
use Statamic\Statamic;
use UAMSWeb\Aether\Redirects\ServiceProvider;
use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * The add-on boots inside a minimal Statamic.
 *
 * @command  vendor/bin/pest tests/Integration/ServiceProviderTest.php
 */
it('is discovered by Statamic as an add-on', function (): void {
    // Expect the add-on among those Statamic booted
    expect(Addon::all()->map->package()->all())->toContain('uams-web/aether-redirects');
});

it('registers the find_redirect_rule tag', function (): void {
    // Read the class Statamic resolves for the handle a template calls
    $tags = app('statamic.tags');

    // Expect the add-on's tag class under that handle
    expect($tags->get('find_redirect_rule'))->toBe(FindRedirectRule::class);
});

it('publishes its config file under the tag the README names', function (): void {
    // Read the paths published under the config tag
    $paths = ServiceProvider::pathsToPublish(null, 'aether-redirects-config');

    // Expect the package config to publish to the app's config directory
    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/aether-redirects.php')])
        ->and(array_values($paths))->toBe([config_path('aether-redirects.php')]);
});

it('registers the Control Panel assets the committed build carries, from the directory it publishes', function (): void {
    // Read the entry points of the committed Vite build
    $directory = realpath(__DIR__.'/../../resources/dist/build');
    Assert::assertIsString($directory);

    /** @var array<string, array{isEntry?: bool}> $manifest */
    $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = array_keys(array_filter($manifest, fn (array $chunk): bool => $chunk['isEntry'] ?? false));
    sort($entries);

    // Expect the registered inputs to be exactly those entry points
    /** @var array<string, mixed> $vites */
    $vites = Statamic::availableVites(request());
    expect($vites)->toHaveKey('aether-redirects');

    /** @var array{input: list<string>, hotFile: string} $vite */
    $vite = $vites['aether-redirects'];
    $input = $vite['input'];
    sort($input);
    expect($input)->toBe($entries);

    // Expect the dev server's hot file beside the build, and the build to publish
    // from the directory that holds the manifest
    expect(realpath(dirname($vite['hotFile'])))->toBe(dirname($directory))
        ->and(basename($vite['hotFile']))->toBe('hot');

    $paths = ServiceProvider::pathsToPublish(ServiceProvider::class, 'aether-redirects');
    expect(array_map(realpath(...), array_keys($paths)))->toContain($directory);
});
