<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Exceptions;

use Statamic\Exceptions\Concerns\RendersHttpExceptions;
use Statamic\View\Cascade;
use Statamic\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UAMSWeb\Aether\Redirects\Services\NotFoundRedirectResolver;

/**
 * An HTTP error response rendered with the *site's* own error template and
 * layout, instead of the framework's fallback page (UAMS-Web/uams-statamic-archive#1942).
 *
 * ## The gap this closes
 *
 * `abort($code, $message)` raises a bare {@see HttpException}, which carries no
 * `render()` of its own. Laravel therefore falls through to
 * `Handler::renderHttpException()`, which looks for an `errors::<code>` view and,
 * finding none for `410` or `451`, answers from `convertExceptionToResponse()`:
 * the generic Symfony error page, with no slot for the message and none of the
 * site's recovery aids. Measured against the running site with `APP_DEBUG=false`,
 * a `410` rule carrying a message returned a 7,785-byte page titled
 * "An Error Occurred: Gone" and the message appeared nowhere in it.
 *
 * Adding an `errors/410.antlers.html` alone does *not* fix that. Laravel renders
 * an error view through a plain `response()->view()`, which applies **no layout**.
 * The site layout is only applied by Statamic's own {@see View}, which is what
 * this class reaches.
 *
 * ## How
 *
 * Statamic already solves exactly this for its own `404`: {@see RendersHttpExceptions}
 * renders `errors.<code>` through {@see View} with a hydrated cascade and the
 * resolved site layout. That trait is public, so this class is a bare Symfony
 * `HttpException` wearing it, the same treatment `Statamic\Exceptions\NotFoundHttpException`
 * gets, extended to the targetless codes a redirect rule can emit.
 *
 * `contents()` is overridden only to add `response_message`. The trait already
 * publishes `response_code` on the cascade the same way; the message needs the
 * same treatment because Statamic's `View`, unlike Laravel's error path, does
 * not hand the exception itself to the template.
 *
 * ## The host's contract
 *
 * The host supplies an `errors/<code>` view for each code it wants rendered with
 * its layout, and reads the rule's message from `response_message`. A code with
 * no view renders as Laravel's own error page for that status.
 *
 * ## Not for 404
 *
 * {@see NotFoundRedirectResolver} raises this only for `403`, `410` and `451`.
 * A `404` rule leaves the response the 404 it already is, so the host's own 404
 * page renders, and that page shows the rule's message itself.
 */
final class SiteHttpException extends HttpException
{
    use RendersHttpExceptions;

    /**
     * Render the site's error template for this status, with its message.
     */
    protected function contents(): string
    {
        // Resolved as the concrete singleton rather than through the `Cascade`
        // facade, whose `@method static self hydrated(Closure $callback)` docblock
        // leaves `Closure` unimported, so it resolves to `Statamic\Facades\Closure`
        // and PHPStan rejects a real one. Same object either way; the facade
        // accessor is this class.
        $cascade = app(Cascade::class);

        $cascade->hydrated(function (Cascade $hydrated): void {
            $hydrated->set('response_code', $this->getStatusCode());
            $hydrated->set('response_message', $this->getMessage());
        });

        $view = app(View::class);

        // Called as statements rather than chained. `template()` and `layout()`
        // return `$this` at runtime but carry no return type upstream, so a fluent
        // chain widens to `mixed` and loses `render()`'s `string`.
        $view->template('errors.'.$this->getStatusCode());
        $view->layout($this->layout());

        return $view->render();
    }
}
