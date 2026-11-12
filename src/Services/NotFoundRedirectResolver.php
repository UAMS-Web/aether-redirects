<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Sites\Site as StatamicSite;
use Statamic\Statamic;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Exceptions\SiteHttpException;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;

/**
 * Answers a request that is about to render a 404 with the redirect rule stored
 * for its path, if one applies (UAMS-Web/uams-statamic#398).
 *
 * The rules are the `redirects` global set's, read for the current site with the
 * default site's merged beneath, and selected by {@see RedirectRuleMatcher}, the
 * engine the `find_redirect_rule` tag uses. The path is tidied the way Statamic's
 * cascade derives `current_uri`, and the query string is read raw, so a rule
 * matches here exactly when the tag matches it on the 404 page.
 *
 * What a matched rule becomes:
 *
 * - `403`, `410` or `451`: that status, rendered by {@see SiteHttpException} with
 *   the host's `errors/<code>` view, its layout and the rule's message.
 * - `404`: nothing. The response stays the 404 it already is, and the host's 404
 *   page renders.
 * - Any other status: a redirect to the rule's target, with the rule's query
 *   string, as `302`, `303`, `307` or `308` when the rule names one and `301`
 *   otherwise. An entry target redirects to the entry's URL on the current site,
 *   or to the entry's own URL when it has no localization there. A rule with no
 *   target, or an entry target that is unpublished or has no URL, is nothing.
 *
 * Control Panel and API requests are never answered: Statamic renders their 404s
 * itself.
 */
class NotFoundRedirectResolver
{
    /**
     * The global set the rules are stored in.
     */
    public const string GLOBAL_HANDLE = 'redirects';

    /**
     * The statuses a matched rule redirects with besides 301, which is what any
     * other status falls back to.
     *
     * @var list<int>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the redirect-status dataset in `NotFoundRedirectsTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem,IncrementInteger,DecrementInteger
    private const array REDIRECT_STATUSES = [302, 303, 307, 308];

    /**
     * The statuses a matched rule answers with by rendering the host's error view,
     * carrying no target.
     *
     * @var list<int>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the site-rendered-status dataset in `NotFoundRedirectsTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem,IncrementInteger,DecrementInteger
    private const array SITE_RENDERED_STATUSES = [403, 410, 451];

    /**
     * @param  RedirectRuleMatcher  $matcher  Selects the rule that applies to a path.
     */
    public function __construct(
        private readonly RedirectRuleMatcher $matcher,
    ) {}

    /**
     * The response a stored redirect rule gives this request, or null to let the
     * 404 render.
     *
     * @param  Request  $request  The request that is about to render a 404.
     * @return Response|null The rule's response, or null when no rule answers.
     */
    public function responseFor(Request $request): ?Response
    {
        // Leave the Control Panel's and the API's 404s to Statamic
        if (Statamic::isCpRoute() || Statamic::isApiRoute()) {
            return null;
        }

        $rule = $this->matchedRule($request);

        if ($rule === null) {
            return null;
        }

        $status = $this->statusCode($rule['response'] ?? null);

        // Render the host's error view for a targetless status, with the rule's message.
        // Statuses are integers, so strict comparison changes nothing
        // @pest-mutate-ignore:TrueToFalse
        if (\in_array($status, self::SITE_RENDERED_STATUSES, true)) {
            $message = $rule['message'] ?? '';

            return $this->render($request, new SiteHttpException($status, \is_string($message) ? $message : ''));
        }

        // A 404 rule leaves the response the 404 it already is
        if ($status === 404) {
            return null;
        }

        $target = $this->target($rule);

        if ($target === null) {
            return null;
        }

        // Redirect with the rule's status, or a permanent move when it names another.
        // Statuses are integers, so strict comparison changes nothing
        // @pest-mutate-ignore:TrueToFalse
        return redirect()->to(
            $this->withQueryString($target, $rule['target_query_string'] ?? null),
            \in_array($status, self::REDIRECT_STATUSES, true) ? $status : 301,
        );
    }

    /**
     * The rule stored for this request's path on the current site, if any.
     *
     * @param  Request  $request  The request.
     * @return array<string, mixed>|null The matched rule, or null.
     */
    private function matchedRule(Request $request): ?array
    {
        $global = GlobalSet::findByHandle(self::GLOBAL_HANDLE);

        if (! $global instanceof GlobalSetContract) {
            return null;
        }

        // Statamic always resolves a current site, from the request or the default
        /** @var StatamicSite $site */
        $site = Site::current();
        /** @var string $siteHandle */
        $siteHandle = $site->handle();

        /** @var StatamicSite $default */
        $default = Site::default();
        /** @var string $defaultHandle */
        $defaultHandle = $default->handle();

        $matched = $this->matcher->match(
            $this->matcher->rulesForSite($global, $siteHandle, $defaultHandle),
            RedirectSource::normalize(URL::tidy($request->path())),
            $this->rawQueryString($request),
        );

        return $matched[0] ?? null;
    }

    /**
     * Render an error the way Laravel's handler renders it, so a code the host has
     * no view for still answers with its own status.
     *
     * @param  Request  $request  The request.
     * @param  SiteHttpException  $exception  The error to render.
     * @return Response The rendered error.
     */
    private function render(Request $request, SiteHttpException $exception): Response
    {
        // Typed as the contract, since a dev tool may bind its own adapter in its place
        /** @var ExceptionHandler $handler */
        $handler = app(ExceptionHandler::class);

        return $handler->render($request, $exception);
    }

    /**
     * The URL a rule sends the visitor to, or null when it has none.
     *
     * @param  array<string, mixed>  $rule  The matched rule.
     * @return string|null The target URL, or null.
     */
    private function target(array $rule): ?string
    {
        if (($rule['target_type'] ?? null) === 'entry') {
            return $this->entryUrl($rule['target_entry'] ?? null);
        }

        if (($rule['target_type'] ?? null) !== 'url') {
            return null;
        }

        $target = $rule['target_url'] ?? null;

        return \is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * The URL of an entry target on the current site, or null when the entry is
     * missing, unpublished or has no URL.
     *
     * @param  mixed  $stored  The rule's `target_entry`: a list of one id, or a bare id.
     * @return string|null The entry's URL, or null.
     */
    private function entryUrl(mixed $stored): ?string
    {
        $id = \is_array($stored) ? ($stored[0] ?? null) : $stored;

        // Statamic finds no entry for an empty or non-string id either, so this guard
        // only narrows the type for static analysis
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (! \is_string($id)) {
            return null;
        }

        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry || ! $entry->published()) {
            return null;
        }

        // Prefer the entry's localization on the current site
        /** @var StatamicSite $site */
        $site = Site::current();
        $localized = $entry->in($site->handle());
        $url = ($localized instanceof StatamicEntry ? $localized : $entry)->url();

        // An entry in a collection with no route has no URL
        return \is_string($url) ? $url : null;
    }

    /**
     * The request's query string as it arrived, without re-ordering.
     *
     * @param  Request  $request  The request.
     * @return string The raw query string, or an empty string when there is none.
     */
    private function rawQueryString(Request $request): string
    {
        $query = parse_url($request->getRequestUri(), PHP_URL_QUERY);

        return \is_string($query) ? $query : '';
    }

    /**
     * A target with a rule's query string appended.
     *
     * @param  string  $target  The target URL.
     * @param  mixed  $query  The rule's `target_query_string`.
     * @return string The URL to redirect to.
     */
    private function withQueryString(string $target, mixed $query): string
    {
        if (! \is_string($query) || $query === '') {
            return $target;
        }

        return $target.(str_contains($target, '?') ? '&' : '?').$query;
    }

    /**
     * A rule's stored response as an integer status, or null when it carries none.
     *
     * @param  mixed  $value  The rule's `response` value.
     * @return int|null The status, or null.
     */
    private function statusCode(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        // With either operator a stored value that is not all digits ends as null:
        // `ctype_digit()` is false for every value that is not a string
        // @pest-mutate-ignore:BooleanAndToBooleanOr
        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
