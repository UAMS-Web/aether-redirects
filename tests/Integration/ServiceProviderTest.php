<?php

declare(strict_types=1);

use Statamic\Facades\Addon;
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
