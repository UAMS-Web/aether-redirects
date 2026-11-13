<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Statamic\CP\Navigation\NavItem;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Providers\AddonServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\MarketingRedirectController;
use UAMSWeb\Aether\Redirects\Http\Middleware\ServeRedirectDomainRequests;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\NotFoundRedirectResolver;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;

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
     * Register the add-on's container bindings.
     *
     * Binds the generator a vanity URL request draws its back-half with
     * (UAMS-Web/aether-redirects#15), so a host or a test can replace it.
     */
    public function register(): void
    {
        $this->app->bind(BackHalf::GENERATOR, static fn (): RandomCode => BackHalf::generator());
    }

    /**
     * Boot the add-on once Statamic has booted.
     *
     * Registers the middleware that answers the redirect domains' requests, the
     * hooks that answer a 404 with the `redirects` global set's rule for its path,
     * and the marketing-redirect workflow's permissions, Control Panel routes and
     * Tools nav items.
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
        $this->provideParametersBlueprint();

        $this->bootMarketingRedirectPermissions();
        $this->bootMarketingRedirectRoutes();
        $this->bootMarketingRedirectNav();
    }

    /**
     * Register the marketing-redirect workflow's two permissions
     * (UAMS-Web/uams-statamic#398).
     *
     * Requesting and approving are separate permissions, so a requester can be
     * kept from approving their own request.
     */
    private function bootMarketingRedirectPermissions(): void
    {
        Permission::group('redirects', 'Redirects', function (): void {
            Permission::register(MarketingRedirectController::PERMISSION)
                ->label(__('Create marketing redirects'));
            Permission::register(MarketingRedirectController::APPROVE_PERMISSION)
                ->label(__('Approve marketing redirects'));
            Permission::register(RedirectDomainList::PERMISSION)
                ->label(__('Manage redirect domains'));
        });
    }

    /**
     * Register the create screen, the review queue and their endpoints as
     * Control Panel routes, under the `marketing-redirects.` route names
     * (UAMS-Web/uams-statamic#398).
     */
    private function bootMarketingRedirectRoutes(): void
    {
        $this->registerCpRoutes(function (): void {
            Route::prefix('redirects/marketing')->name('marketing-redirects.')->group(function (): void {
                Route::get('/', [MarketingRedirectController::class, 'create'])->name('create');
                Route::post('/', [MarketingRedirectController::class, 'store'])->name('store');
                Route::get('review', [MarketingRedirectController::class, 'index'])->name('review');
                Route::post('{redirect}/approve', [MarketingRedirectController::class, 'approve'])->name('approve');
                Route::post('{redirect}/reject', [MarketingRedirectController::class, 'reject'])->name('reject');
            });
        });
    }

    /**
     * Add the Tools nav items, each shown only to a user holding its permission
     * (UAMS-Web/uams-statamic#398).
     *
     * Requesters see the creation utility; approvers see the review queue,
     * labelled with the number of requests awaiting approval.
     */
    private function bootMarketingRedirectNav(): void
    {
        Nav::extend(function ($nav): void {
            /** @var \Statamic\Auth\File\User|null $user */
            $user = User::current();

            // Show nothing to a request with no signed-in user
            if ($user === null) {
                return;
            }

            // Show the creation utility to users who can request redirects
            if ($user->can(MarketingRedirectController::PERMISSION)) {
                /**
                 * @var \Statamic\CP\Navigation\Nav $nav
                 * @var NavItem $item
                 */
                $item = $nav->tools(__('Marketing Redirects')); // @phpstan-ignore method.notFound
                $item->route('marketing-redirects.create');
                $item->icon('hyperlink');
            }

            // Show the review queue, with its pending count, to approvers
            if ($user->can(MarketingRedirectController::APPROVE_PERMISSION)) {
                // Read the pending count once so approvers see outstanding work in the nav
                $pending = app(MarketingRedirectStore::class)->pendingCount()
                    + count(app(RedirectDomainStore::class)->pending())
                    + count(app(TaggedUrlRequestStore::class)->pending());

                $label = $pending > 0
                    ? sprintf('%s (%d)', __('Review Redirects'), $pending)
                    : __('Review Redirects');

                /**
                 * @var \Statamic\CP\Navigation\Nav $nav
                 * @var NavItem $reviewItem
                 */
                $reviewItem = $nav->tools($label); // @phpstan-ignore method.notFound
                $reviewItem->route('marketing-redirects.review');
                $reviewItem->icon('flag');
            }
        });
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
        $this->provideGlobalBlueprint(NotFoundRedirectResolver::GLOBAL_HANDLE);
    }

    /**
     * Serve the `tagged_url_parameters` global set's blueprint from this package when
     * the host has none of its own (UAMS-Web/aether-redirects#51).
     *
     * The lists and options collections get no blueprint from here. Statamic reads a
     * collection's entry blueprints from files only, with no fallback to fall back on,
     * and registering this package's directory for them would make Statamic ignore a
     * host's own. Their blueprints are `docs/blueprints/lookup_list.yaml` and
     * `docs/blueprints/lookup_option.yaml`, for the host's content repository to copy.
     */
    private function provideParametersBlueprint(): void
    {
        $this->provideGlobalBlueprint(TaggedUrlLookup::PARAMETERS_GLOBAL);
    }

    /**
     * Serve a global set's blueprint from `resources/blueprints/globals`, which
     * Statamic uses only when the host has no blueprint file of its own for the set.
     *
     * @param  string  $handle  The global set's handle.
     */
    private function provideGlobalBlueprint(string $handle): void
    {
        $path = __DIR__.'/../resources/blueprints/globals/'.$handle.'.yaml';

        // Resolved as the concrete repository rather than through the `Blueprint`
        // facade, whose `setFallback()` docblock leaves `Closure` unimported
        app(BlueprintRepository::class)->setFallback(
            'globals.'.$handle,
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
