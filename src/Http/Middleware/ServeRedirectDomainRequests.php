<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Support\RedirectRuleMatcher;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;

/**
 * Answers the requests the edge sends on for the vanity redirect domains (UAMS-Web/uams-statamic-archive#2813).
 *
 * UAMS-Web/uams-statamic-archive#2811 decided that the application is the origin for the domains authored in the
 * `redirect_domains` collection. The edge manifests answer what they can and send
 * the rest here: a request that matched nothing, and one that reached a rule the
 * edge cannot reproduce (a regex rule, every pattern rule after the first regex, a
 * windowed rule, a non-redirect status, or an entry target that could not be
 * resolved).
 *
 * A request is answered here when its host is not the host of any Statamic site
 * and has at least one entry in the collection. Its rules are read from that host's
 * entries alone, through `RedirectDomainRules`, and the rule is selected by
 * `RedirectRuleMatcher`, the engine the main site's `find_redirect_rule` tag uses,
 * so regex, ordering, validity windows and approval behave exactly as they do
 * there. A request no rule answers is a bare 404.
 *
 * Every response from this class is built here and nothing is passed on, so these
 * hosts never reach Statamic's catch-all route and never render site content. Any
 * other host passes through untouched, which is what keeps the Statamic sites
 * unchanged.
 */
class ServeRedirectDomainRequests
{
    /**
     * The statuses a matched rule redirects with.
     *
     * @var list<int>
     */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * The statuses a matched rule answers with directly, carrying no target.
     *
     * @var list<int>
     */
    private const array TARGETLESS_STATUSES = [403, 404, 410, 451];

    /**
     * @param  RedirectDomainRules  $domainRules  Reads the collection's rules by host.
     * @param  RedirectRuleMatcher  $matcher  Selects the rule that applies to a path.
     */
    public function __construct(
        private readonly RedirectDomainRules $domainRules,
        private readonly RedirectRuleMatcher $matcher,
    ) {}

    /**
     * Answer a redirect domain's request, or pass any other request on.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The redirect domain's answer, or the pipeline's response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Leave a host that cannot name a redirect domain to the rest of the app
        $host = RedirectDomainRules::normalizeHost($request->getHost());

        if ($host === null || \in_array($host, $this->siteHosts(), true)) {
            return $next($request);
        }

        // Leave a host with no entries in the collection to the rest of the app,
        // checked before the full read so an alias host does not pay for one
        if (! \in_array($host, RedirectDomainRules::publishedHosts(), true)) {
            return $next($request);
        }

        $rules = $this->domainRules->read()['rules'][$host] ?? null;

        if ($rules === null) {
            return $next($request);
        }

        // Select the rule through the engine the main site uses
        $matched = $this->matcher->match(
            $rules,
            RedirectSource::normalize($request->path()),
            $this->rawQueryString($request),
        );

        return $this->answer($matched[0] ?? null);
    }

    /**
     * The response a matched rule stands for, or a 404 when there is none.
     *
     * @param  array<string, mixed>|null  $rule  The matched rule, or null.
     * @return Response The response.
     */
    private function answer(?array $rule): Response
    {
        // Answer an unmatched path with a bare 404
        if ($rule === null) {
            return $this->bare(404);
        }

        $status = $this->statusCode($rule['response'] ?? null);

        // Answer a targetless rule with its own status and message
        if (\in_array($status, self::TARGETLESS_STATUSES, true)) {
            $message = $rule['message'] ?? null;

            return $this->bare($status, \is_string($message) ? $message : '');
        }

        // Answer a rule with nowhere to send the visitor with a bare 404, which is
        // what an entry target the read could not resolve to a URL is
        $target = $rule['target_url'] ?? null;

        if (($rule['target_type'] ?? null) !== 'url' || ! \is_string($target) || $target === '') {
            return $this->bare(404);
        }

        // Redirect with the rule's status, or a permanent move when it names another
        return redirect()->away(
            $this->withQueryString($target, $rule['target_query_string'] ?? null),
            \in_array($status, self::REDIRECT_STATUSES, true) ? $status : 301,
        );
    }

    /**
     * The hosts of the Statamic sites, which this class never answers for.
     *
     * A site configured with a relative URL is served on the application URL's
     * host, so that host stands for it.
     *
     * @return list<string> The hosts.
     */
    private function siteHosts(): array
    {
        $appHost = RedirectDomainRules::normalizeHost(parse_url(config()->string('app.url'), PHP_URL_HOST));
        $hosts = $appHost === null ? [] : [$appHost];

        // The facade returns a loosely typed collection; the checks below narrow it
        /** @var Collection<array-key, mixed> $sites */
        $sites = Site::all();

        foreach ($sites as $site) {
            if (! $site instanceof StatamicSite) {
                continue;
            }

            $url = $site->url();

            if (! \is_string($url)) {
                continue;
            }

            // Read the host of a site configured with an absolute URL
            $host = RedirectDomainRules::normalizeHost(parse_url($url, PHP_URL_HOST));

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * The request's query string as it arrived, without re-ordering.
     *
     * @param  Request  $request  The incoming request.
     * @return string The raw query string, or an empty string when there is none.
     */
    private function rawQueryString(Request $request): string
    {
        $query = parse_url($request->getRequestUri(), PHP_URL_QUERY);

        return \is_string($query) ? $query : '';
    }

    /**
     * A target with a rule's query string appended, as the serving template does.
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
     * A rule's stored response as an integer status, or 301 when it carries none.
     *
     * @param  mixed  $value  The rule's `response` value.
     * @return int The status.
     */
    private function statusCode(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) ? (int) $value : 301;
    }

    /**
     * A plain-text response carrying a status and an optional message, and nothing
     * from the site.
     *
     * @param  int  $status  The status.
     * @param  string  $message  The message, or an empty string.
     * @return Response The response.
     */
    private function bare(int $status, string $message = ''): Response
    {
        $body = $message !== '' ? $message : (Response::$statusTexts[$status] ?? '');

        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
