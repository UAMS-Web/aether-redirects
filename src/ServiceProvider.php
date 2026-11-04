<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;

/**
 * Service provider for the add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. Register the add-on's tags, fieldtypes, listeners, routes and
 * the like here, using the properties `AddonServiceProvider` documents, or in
 * `bootAddon()` for anything that needs Statamic booted first.
 */
class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Vite build of the Control Panel assets. `input` and `publicDirectory`
     * must match `vite.config.js`; the built files are committed under
     * `resources/dist`.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    /**
     * Boot the add-on once Statamic has booted.
     *
     * Registers the middleware that answers the redirect domains' requests. The
     * host owns the `redirect_domains` collection, its config and its blueprint.
     */
    public function bootAddon(): void
    {
        // Answer a redirect domain's request on the global stack, after the host's own
        // global middleware, so it runs before routing and never reaches Statamic's
        // catch-all route
        // Every Laravel HTTP kernel extends the framework's, which has pushMiddleware()
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->pushMiddleware(ServeRedirectDomainRequests::class);
    }
}
