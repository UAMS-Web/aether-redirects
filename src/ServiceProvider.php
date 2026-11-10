<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Providers\AddonServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\NotFoundRedirectResolver;

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
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and `ViteBuildTest` and `ServiceProviderTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem
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
     * Registers the middleware that answers the redirect domains' requests, and the
     * hooks that answer a 404 with the `redirects` global set's rule for its path.
     * The host owns the `redirect_domains` collection, the `redirects` global set,
     * their config and their blueprints, and its error views.
     */
    public function bootAddon(): void
    {
        // Answer a redirect domain's request on the global stack, after the host's own
        // global middleware, so it runs before routing and never reaches Statamic's
        // catch-all route
        // Every Laravel HTTP kernel extends the framework's, which has pushMiddleware()
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->pushMiddleware(ServeRedirectDomainRequests::class);

        $this->answerNotFoundWithRedirects();
        $this->provideRedirectsBlueprint();
    }

    /**
     * Serve the `redirects` global set's blueprint from this package when the host
     * has none of its own (UAMS-Web/uams-statamic#398).
     *
     * Statamic looks a global set's blueprint up only in the host's blueprint
     * directory, as `globals/<handle>.yaml`, so an add-on namespace cannot serve it.
     * A fallback can: Statamic uses it only when that file is absent, so a host that
     * keeps or saves its own copy, as the Control Panel's blueprint editor does,
     * overrides this one. Its `target_url` field reads the host's `uamsweb_common`
     * fieldset, as the redirect domains blueprint does.
     */
    private function provideRedirectsBlueprint(): void
    {
        $path = __DIR__.'/../resources/blueprints/globals/'.NotFoundRedirectResolver::GLOBAL_HANDLE.'.yaml';

        // Resolved as the concrete repository rather than through the `Blueprint`
        // facade, whose `setFallback()` docblock leaves `Closure` unimported
        app(BlueprintRepository::class)->setFallback(
            'globals.'.NotFoundRedirectResolver::GLOBAL_HANDLE,
            function () use ($path): StatamicBlueprint {
                $blueprint = new StatamicBlueprint;
                $blueprint->setContents(YAML::file($path)->parse());

                return $blueprint;
            },
        );
    }

    /**
     * Answer a 404 with the stored redirect rule for its path, before the 404 page
     * renders (UAMS-Web/uams-statamic#398).
     *
     * Statamic's own 404, which its routes raise for a URL with no content, runs
     * its render hook first, ahead of any cached error page. A plain `abort(404)`
     * raises Symfony's exception, which has no render of its own, so Laravel's
     * handler reaches the `renderable()` callback for it. Either way a null from
     * the resolver lets the 404 render as before.
     */
    private function answerNotFoundWithRedirects(): void
    {
        // Statamic binds the hook to the exception, so it reads the request it is given
        NotFoundHttpException::renderUsing(
            fn (Request $request): ?Response => app(NotFoundRedirectResolver::class)->responseFor($request)
        );

        // Every Laravel exception handler extends the framework's, which has renderable().
        // Typed as the contract, since a dev tool may bind its own adapter in its place
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // Laravel's handler and every application's extend the framework's, so this
        // only narrows the type for static analysis
        // @pest-mutate-ignore:InstanceOfToTrue
        if ($handler instanceof Handler) {
            $handler->renderable(
                fn (SymfonyNotFoundHttpException $exception, Request $request): ?Response => app(NotFoundRedirectResolver::class)->responseFor($request)
            );
        }
    }
}
