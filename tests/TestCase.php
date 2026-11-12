<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Tests;

use Illuminate\Foundation\Application;
use Statamic\Testing\AddonTestCase;
use UAMSWeb\Aether\Redirects\ServiceProvider;

/**
 * Base class for the Integration suite: boots a minimal Statamic through
 * Orchestra Testbench with this add-on's provider registered.
 */
abstract class TestCase extends AddonTestCase
{
    /**
     * The add-on provider Statamic's test case registers before each test.
     *
     * @var string Class name of this add-on's service provider.
     */
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Tell Testbench it is in a parallel run before it boots the application.
     *
     * Pest sets `LARAVEL_PARALLEL_TESTING` only for a Laravel application, and
     * Testbench's `TestCase` marks this suite as a package, so the variable is
     * never set here. Testbench then treats every worker as the only process and
     * writes the shared `bootstrap/cache/packages.php`, which on Windows fails
     * with "Access is denied" when one worker replaces it while another holds it
     * open (#74). With the variable set, each worker gets its own manifest file.
     */
    #[\Override]
    protected function setUp(): void
    {
        self::markParallelRun($_SERVER['TEST_TOKEN'] ?? null);

        parent::setUp();
    }

    /**
     * Set `LARAVEL_PARALLEL_TESTING` for a parallel worker, and only for one.
     *
     * @param  mixed  $token  The parallel worker token, or null outside a parallel run.
     * @return bool Whether the variable was set by this call.
     */
    public static function markParallelRun(mixed $token): bool
    {
        if (! is_string($token) && ! is_int($token) || ! empty($_SERVER['LARAVEL_PARALLEL_TESTING'])) {
            return false;
        }

        $_SERVER['LARAVEL_PARALLEL_TESTING'] = $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';

        return true;
    }

    /**
     * Give each parallel worker its own license-check cache, after Statamic's
     * test case sets the shared one.
     *
     * Every authenticated Control Panel page asks Statamic's license check, which
     * takes a file lock in the `outpost` cache store. Statamic's `AddonTestCase`
     * points that store at one directory for every worker, so on Windows a worker
     * opening the lock file while another holds it fails with "Permission denied",
     * failing whichever test was rendering a page (#126). A directory per worker
     * keeps the lock and the cached response apart, and still keeps the response
     * between tests, so the check does not reach the network on every test.
     *
     * Statamic sets the store here rather than in `defineEnvironment()`, which runs
     * first, so the override must follow the parent call.
     *
     * @param  Application  $app  The application being configured.
     */
    #[\Override]
    protected function getEnvironmentSetUp($app): void
    {
        // Let Statamic's test case configure the environment first
        parent::getEnvironmentSetUp($app);

        // Point the license-check cache at this worker's directory, if any
        $path = self::outpostCachePath($_SERVER['TEST_TOKEN'] ?? null);
        if ($path !== null) {
            $app['config']->set('cache.stores.outpost.path', $path);
        }
    }

    /**
     * The license-check cache directory for a parallel worker token, or null
     * outside a parallel run.
     *
     * @param  mixed  $token  The parallel worker token, or null outside a parallel run.
     * @return ?string The worker's cache directory, or null without a usable token.
     */
    public static function outpostCachePath(mixed $token): ?string
    {
        return is_string($token) || is_int($token) ? storage_path("framework/cache/outpost-data-{$token}") : null;
    }

    /**
     * Give the application an encryption key, which a Control Panel request
     * needs for its session, and register the REST and GraphQL routes.
     *
     * @param  Application  $app  The application being configured.
     */
    protected function defineEnvironment($app): void
    {
        // Set an encryption key for the session
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // Statamic registers the REST and GraphQL routes while booting, and only when
        // enabled, so they are enabled here; which resources they expose, and the Pro
        // edition they require, are read per request and left to each test.
        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.graphql.enabled', true);
    }
}
