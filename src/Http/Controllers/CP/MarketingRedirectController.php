<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Http\Controllers\CP;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\RedirectCollisionDetector;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainStore;
use UAMSWeb\Aether\Redirects\Services\RedirectRequestStore;
use UAMSWeb\Aether\Redirects\Services\TrustedDestinationReader;
use UAMSWeb\Aether\Redirects\Support\BackHalf;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RandomCode;
use UAMSWeb\Aether\Redirects\Support\RedirectHosts;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;
use UAMSWeb\Aether\Redirects\Support\ShortCode;

/**
 * Drives the marketing-redirect creation utility (UAMS-Web/uams-statamic-archive#1355).
 *
 * `create` renders the staff-facing screen; `store` validates the submission,
 * rejects a source collision within an overlapping validity window (UAMS-Web/uams-statamic-archive#1356), and
 * appends the redirect to the shared `redirects` global (per UAMS-Web/uams-statamic-archive#1110) — as a
 * *pending* request that does not serve until approved (UAMS-Web/uams-statamic-archive#1357). `index` renders
 * the review queue; `approve` / `reject` act on a pending request. Creation is
 * gated by the `create marketing redirects` permission; review is gated by the
 * separate `approve marketing redirects` permission, and a creator may never
 * review their own request. There is no public creation path in this slice (the
 * #56 trust-boundary decision).
 *
 * A request with a `host` is an entry of the `redirect_domains` collection that
 * serves on that vanity domain; one without is a row of the `redirects` global
 * that serves on the main site's 404 path (UAMS-Web/aether-redirects#83). Both
 * go through the same approval workflow, and the review queue lists both.
 *
 * A request for a vanity domain with no source gets a generated back-half
 * (UAMS-Web/aether-redirects#15): 7 base-62 characters never issued on that
 * domain in any case. Approval checks it again, so two pending requests whose
 * back-halves differ only in case cannot both go live.
 *
 * A short link is a request on `go.uams.edu` whose source is a generated
 * 6-character code (UAMS-Web/aether-redirects#84), behind its own `create short
 * links` permission. `shortCode` draws a code for the screen, which the creator
 * can draw again before saving. A code is never issued twice on the domain, and
 * one whose destination is on a trusted host is saved approved; any other waits
 * for an approver.
 */
class MarketingRedirectController extends CpController
{
    /**
     * The permission required to create a redirect request.
     */
    public const string PERMISSION = 'create marketing redirects';

    /**
     * The permission required to approve or reject a redirect request (UAMS-Web/uams-statamic-archive#1357).
     */
    public const string APPROVE_PERMISSION = 'approve marketing redirects';

    /**
     * The permission required to make a `go.uams.edu` short link (UAMS-Web/aether-redirects#84).
     */
    public const string SHORT_LINK_PERMISSION = 'create short links';

    /**
     * Render the marketing-redirect creation screen.
     */
    public function create(): InertiaResponse
    {
        // Only staff who can request redirects or make short links may open the utility.
        abort_unless(Gate::any([self::PERMISSION, self::SHORT_LINK_PERMISSION]), 403);

        // Hand the Vue screen its POST targets, the redirect-type options, the
        // domains a request can be made for and what this user may make.
        return Inertia::render('aether-redirects::MarketingRedirectCreate', [
            'storeUrl' => cp_route('marketing-redirects.store'),
            'shortCodeUrl' => cp_route('marketing-redirects.short-code'),
            'hostOptions' => $this->hostOptions(),
            'canApprove' => $this->canApprove(),
            'canCreateRedirects' => Gate::allows(self::PERMISSION),
            'shortLinkHost' => Gate::allows(self::SHORT_LINK_PERMISSION) && ! $this->shortLinkHostError() instanceof JsonResponse ? ShortCode::HOST : null,
            'generatedHosts' => RedirectHosts::vanity(),
            'responseOptions' => [
                ['value' => '301', 'label' => __('301 Moved Permanently')],
                ['value' => '302', 'label' => __('302 Found')],
            ],
        ]);
    }

    /**
     * Validate, collision-check, and persist a new marketing redirect.
     */
    public function store(
        Request $request,
        MarketingRedirectStore $globalStore,
        RedirectDomainStore $domainStore,
        RedirectCollisionDetector $detector,
        TrustedDestinationReader $trusted
    ): JsonResponse {
        // A short link has its own permission and rules (UAMS-Web/aether-redirects#84).
        if ($request->boolean('short_link')) {
            return $this->storeShortLink($request, $domainStore, $trusted);
        }

        // Gate the write behind the redirect permission.
        $this->authorize(self::PERMISSION);

        // Validate the raw submission before building the typed model.
        $validated = $this->validateInput($request);

        // A vanity-domain request with no source asks for a generated back-half (UAMS-Web/aether-redirects#15);
        // validation has already refused a blank source anywhere else.
        $generate = $validated['source'] === null || trim($validated['source']) === '';

        // Build the canonical redirect, tagging the authenticated creator so the
        // approval slice can forbid self-approval (UAMS-Web/uams-statamic-archive#1357).
        // The '' stands in only for a source that is about to be generated, which replaces it.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $redirect = MarketingRedirect::fromInput([...$validated, 'source' => $validated['source'] ?? ''], $this->currentUserId());

        // An inverted window (start after end) could never serve — reject it.
        if ($this->windowIsInverted($redirect)) {
            return $this->unprocessable(
                'valid_until',
                __('The "valid until" date must be on or after the "valid from" date.')
            );
        }

        // A request for a redirect domain must name a domain the application knows,
        // and pair it with a destination that domain serves unless an approver saves it.
        $hostError = $this->hostError($request, $redirect);

        if ($hostError instanceof JsonResponse) {
            return $hostError;
        }

        // The domain's entries hold a domain request; the global holds a main-site one.
        $store = $redirect->host === null ? $globalStore : $domainStore;

        // Draw the back-half against every row on the domain that has issued one; a
        // domain request always has a host here, since only a vanity domain may omit the source.
        if ($generate && $redirect->host !== null) {
            // The provider binds the back-half generator; a host that rebinds it to anything else has misconfigured it.
            $generator = app(BackHalf::GENERATOR);

            if (! $generator instanceof RandomCode) {
                throw new LogicException(sprintf('The "%s" binding must resolve to a %s.', BackHalf::GENERATOR, RandomCode::class));
            }

            try {
                $redirect = $redirect->withGeneratedBackHalf(BackHalf::generate(
                    $this->rowsOnHost($domainStore->all(), $redirect->host),
                    $generator,
                ));
            } catch (CodeGenerationException $exception) {
                return $this->unprocessable('source', $exception->getMessage());
            }
        }

        // Surface a same-source collision within an overlapping window (UAMS-Web/uams-statamic-archive#1356),
        // but only against redirects that serve or may yet serve. A rejected
        // request is retained for the record and never serves (UAMS-Web/uams-statamic-archive#1357), so it must
        // not reserve its source — otherwise a rejected request would permanently
        // block re-requesting that source through the utility.
        $conflict = $detector->firstConflict(
            $redirect->source,
            $redirect->window,
            $this->collisionCandidates($store),
            $redirect->host
        );

        if ($conflict !== null) {
            return $this->unprocessable('source', $this->conflictMessage($conflict));
        }

        // Persist the redirect onto the shared default-site list as pending.
        $row = $store->create($redirect);

        // Report success with the stored row for the screen to confirm.
        return response()->json([
            'saved' => true,
            'message' => $this->submittedMessage($redirect),
            'redirect' => $row,
        ], 201);
    }

    /**
     * Draw a short-link code the screen offers before saving (UAMS-Web/aether-redirects#84).
     *
     * The code is free when drawn; saving checks it again, since another short
     * link may take it in between.
     */
    public function shortCode(RedirectDomainStore $domainStore): JsonResponse
    {
        $this->authorize(self::SHORT_LINK_PERMISSION);

        $hostError = $this->shortLinkHostError();

        if ($hostError instanceof JsonResponse) {
            return $hostError;
        }

        try {
            $code = ShortCode::generate($this->rowsOnHost($domainStore->all(), ShortCode::HOST), $this->shortCodeGenerator());
        } catch (CodeGenerationException $codeGenerationException) {
            return $this->unprocessable('source', $codeGenerationException->getMessage());
        }

        return response()->json(['code' => $code]);
    }

    /**
     * Validate and save a short link on `go.uams.edu` (UAMS-Web/aether-redirects#84).
     *
     * The source is a code the screen drew, or blank to draw one here. A code is
     * refused when it is not in the short-code format or when any rule or request on
     * the domain has issued it, whatever its validity window. The link is saved
     * approved when its destination is on a trusted host, and pending otherwise.
     *
     * @param  Request  $request  The incoming create request
     * @param  RedirectDomainStore  $domainStore  The store of the domain's rules
     * @param  TrustedDestinationReader  $trusted  The reader of the trusted hosts
     * @return JsonResponse The saved link, or an error
     */
    private function storeShortLink(Request $request, RedirectDomainStore $domainStore, TrustedDestinationReader $trusted): JsonResponse
    {
        $this->authorize(self::SHORT_LINK_PERMISSION);

        // The same fields as any request, with the source optional: a blank one is drawn here.
        $validated = $this->validateInput($request, sourceOptional: true);

        // A short link is made on its one domain; naming any other is an error, never a silent move.
        $submittedHost = RedirectHosts::normalize($validated['host']);

        if ($submittedHost !== null && $submittedHost !== ShortCode::HOST) {
            return $this->unprocessable('host', __('A short link is made on :host.', ['host' => ShortCode::HOST]));
        }

        $hostError = $this->shortLinkHostError();

        if ($hostError instanceof JsonResponse) {
            return $hostError;
        }

        // The '' stands in only for the code, which replaces it below.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $redirect = MarketingRedirect::fromInput([...$validated, 'source' => '', 'host' => ShortCode::HOST], $this->currentUserId());

        if ($this->windowIsInverted($redirect)) {
            return $this->unprocessable(
                'valid_until',
                __('The "valid until" date must be on or after the "valid from" date.')
            );
        }

        // A typed code must be in the format and never issued; a blank one is drawn here.
        $rows = $this->rowsOnHost($domainStore->all(), ShortCode::HOST);
        $code = ltrim(trim($validated['source'] ?? ''), '/');

        if ($code === '') {
            try {
                $code = ShortCode::generate($rows, $this->shortCodeGenerator());
            } catch (CodeGenerationException $exception) {
                return $this->unprocessable('source', $exception->getMessage());
            }
        } elseif (! ShortCode::isCode($code)) {
            return $this->unprocessable('source', __("A short link's path is a generated code. Use Generate code to get one."));
        } elseif (BackHalf::isIssued($code, $rows)) {
            return $this->unprocessable('source', __('The code :code is already in use on :host. Generate another.', ['code' => $code, 'host' => ShortCode::HOST]));
        }

        // A destination on a trusted host goes live now; any other waits for an approver.
        $approved = $trusted->trusted()->allows($redirect->destination);
        $redirect = $redirect->withShortCode($code, $approved);
        $row = $domainStore->create($redirect);
        $url = ShortCode::HOST.$redirect->source;

        return response()->json([
            'saved' => true,
            'message' => $approved
                ? __('Short link created. It serves at :url.', ['url' => $url])
                : __('Short link submitted for review, because its destination is not on a UAMS domain. It serves at :url once an approver approves it.', ['url' => $url]),
            'redirect' => $row,
        ], 201);
    }

    /**
     * The error for a short link when its domain is not in the domain list.
     *
     * @return JsonResponse|null A 422, or null when the domain is listed
     */
    private function shortLinkHostError(): ?JsonResponse
    {
        // Both sides are normalized host strings, which compare the same loosely or strictly
        // @pest-mutate-ignore:TrueToFalse
        if (\in_array(ShortCode::HOST, $this->hostOptions(), true)) {
            return null;
        }

        return $this->unprocessable('host', __(':host is not a redirect domain.', ['host' => ShortCode::HOST]));
    }

    /**
     * The generator a short link draws with.
     *
     * @return RandomCode The bound generator
     *
     * @throws LogicException When a host rebinds it to anything else
     */
    private function shortCodeGenerator(): RandomCode
    {
        // The provider binds the generator; a host that rebinds it to anything else has misconfigured it.
        $generator = app(ShortCode::GENERATOR);

        if (! $generator instanceof RandomCode) {
            throw new LogicException(sprintf('The "%s" binding must resolve to a %s.', ShortCode::GENERATOR, RandomCode::class));
        }

        return $generator;
    }

    /**
     * Render the review queue of redirects awaiting approval (UAMS-Web/uams-statamic-archive#1357).
     */
    public function index(MarketingRedirectStore $globalStore, RedirectDomainStore $domainStore): InertiaResponse
    {
        // Only approvers may open the review queue.
        $this->authorize(self::APPROVE_PERMISSION);

        // Hand the Vue screen the pending requests, each with its action URLs.
        return Inertia::render('aether-redirects::MarketingRedirectReview', [
            'redirects' => $this->pendingPayload($globalStore, $domainStore),
        ]);
    }

    /**
     * Approve a pending redirect so it begins serving (UAMS-Web/uams-statamic-archive#1357).
     */
    public function approve(string $redirect, MarketingRedirectStore $globalStore, RedirectDomainStore $domainStore): JsonResponse
    {
        // Only approvers may approve, and never their own request.
        $this->authorize(self::APPROVE_PERMISSION);

        // Reject an unknown id, an already-reviewed row, or a self-review.
        $store = $this->storeHolding($redirect, $globalStore, $domainStore);
        $pending = $this->reviewableRow($redirect, $store);

        if ($pending instanceof JsonResponse) {
            return $pending;
        }

        // A generated back-half goes live only if no approved rule on its domain has issued it meanwhile.
        $reissue = $this->reissueError($pending, $store);

        if ($reissue instanceof JsonResponse) {
            return $reissue;
        }

        // Promote the request; it now passes the serve gate. The '' fallback is
        // unreachable: the CP auth middleware and the permission gate above both
        // need a signed-in user, so the id is never null here.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $row = $store->approve($redirect, $this->currentUserId() ?? '');

        return response()->json([
            'saved' => true,
            'message' => $store instanceof RedirectDomainStore
                ? __('Redirect approved. It now serves on its domain.')
                : __('Redirect approved. It now serves on a 404 for every site.'),
            'redirect' => $row,
        ]);
    }

    /**
     * Reject a pending redirect, retaining the reason for the record (UAMS-Web/uams-statamic-archive#1357).
     */
    public function reject(
        Request $request,
        string $redirect,
        MarketingRedirectStore $globalStore,
        RedirectDomainStore $domainStore
    ): JsonResponse {
        // Only approvers may reject, and never their own request.
        $this->authorize(self::APPROVE_PERMISSION);

        // A rejection must carry a reason so the record explains the decision.
        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2048'],
        ]);

        // Reject an unknown id, an already-reviewed row, or a self-review.
        $store = $this->storeHolding($redirect, $globalStore, $domainStore);
        $pending = $this->reviewableRow($redirect, $store);

        if ($pending instanceof JsonResponse) {
            return $pending;
        }

        // Record the rejection with its reason. The '' fallback is unreachable for
        // the same reason as in approve().
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $row = $store->reject($redirect, $this->currentUserId() ?? '', trim($validated['reason']));

        return response()->json([
            'saved' => true,
            'message' => __('Redirect rejected.'),
            'redirect' => $row,
        ]);
    }

    /**
     * Validate the create request.
     *
     * A source may be left out only on a vanity domain, where it asks for a
     * generated back-half (UAMS-Web/aether-redirects#15), or for a short link,
     * where it asks for a generated code (UAMS-Web/aether-redirects#84).
     *
     * @param  Request  $request  The incoming create request
     * @param  bool  $sourceOptional  Whether a blank source is allowed whatever the domain
     * @return array{source: string|null, destination: string, response: string, valid_from: string|null, valid_until: string|null, requester: string|null, notes: string|null, host: string|null}
     */
    private function validateInput(Request $request, bool $sourceOptional = false): array
    {
        $host = $request->input('host');
        $vanity = RedirectHosts::isVanity(RedirectHosts::normalize(\is_string($host) ? $host : null));

        /** @var array{source?: string|null, destination: string, response: string, valid_from?: string|null, valid_until?: string|null, requester?: string|null, notes?: string|null, host?: string|null} $validated */
        $validated = $request->validate([
            'source' => [Rule::requiredIf(! $vanity && ! $sourceOptional), 'nullable', 'string', 'max:2048'],
            'destination' => ['required', 'string', 'max:2048'],
            'response' => ['required', 'string', Rule::in(MarketingRedirect::ALLOWED_RESPONSES)],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'requester' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2048'],
            'host' => ['nullable', 'string'],
        ]);

        // Normalize optional keys to a stable shape for MarketingRedirect::fromInput().
        return [
            'source' => $validated['source'] ?? null,
            'destination' => $validated['destination'],
            'response' => $validated['response'],
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'requester' => $validated['requester'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'host' => $validated['host'] ?? null,
        ];
    }

    /**
     * Whether the redirect's validity window starts after it ends.
     *
     * @param  MarketingRedirect  $redirect  The redirect under consideration
     * @return bool True when both bounds are set and the start is after the end
     */
    private function windowIsInverted(MarketingRedirect $redirect): bool
    {
        $from = $redirect->window->from;
        $until = $redirect->window->until;

        // Only a fully-bounded window can be inverted.
        return $from instanceof CarbonImmutable && $until instanceof CarbonImmutable && $from->gt($until);
    }

    /**
     * The message confirming a submitted request, naming a generated back-half.
     *
     * @param  MarketingRedirect  $redirect  The stored request
     * @return string What the requester is told
     */
    private function submittedMessage(MarketingRedirect $redirect): string
    {
        if ($redirect->host === null) {
            return __('Redirect submitted for review. It serves on a 404 for every site once an approver approves it.');
        }

        if ($redirect->generatedBackHalf) {
            return __('Redirect submitted for review. It serves on :url once an approver approves it.', ['url' => $redirect->host.$redirect->source]);
        }

        return __('Redirect submitted for review. It serves on :host once an approver approves it.', ['host' => $redirect->host]);
    }

    /**
     * The error for approving a generated back-half that another rule on its domain has issued.
     *
     * Creation drew the back-half against every row on its domain, but another
     * request may have taken the same back-half in another case since, and been
     * approved first (UAMS-Web/aether-redirects#15). Only approved rows block
     * here: a pending one has not gone live, and counting it would let two
     * pending requests each block the other. Rows whose window has ended still
     * block, as at creation, because their links stay in circulation. The
     * request being approved is pending, so it never blocks itself.
     *
     * @param  array<string, mixed>  $row  The pending request being approved
     * @param  RedirectRequestStore  $store  The store holding it
     * @return JsonResponse|null A 422, or null when the request may go live
     */
    private function reissueError(array $row, RedirectRequestStore $store): ?JsonResponse
    {
        // Only a generated back-half carries this guarantee; a typed path keeps its own rules.
        if (! BackHalf::wasGenerated($row)) {
            return null;
        }

        $host = RedirectHosts::normalize($row['host'] ?? null);
        $source = $row['source'] ?? null;

        // A hand-edited entry can lose its host or source, and then there is nothing to compare.
        if ($host === null || ! \is_string($source)) {
            return null;
        }

        $approved = array_filter(
            $this->rowsOnHost($store->all(), $host),
            static fn (array $other): bool => RedirectStatus::fromRow($other) === RedirectStatus::Approved,
        );

        if (! BackHalf::isIssued($source, $approved)) {
            return null;
        }

        return $this->unprocessable('source', __(
            'The back-half :source has already been issued on :host in another case. Reject this request so it can be submitted again for a new back-half.',
            ['source' => $source, 'host' => $host]
        ));
    }

    /**
     * The rows that serve on one domain.
     *
     * @param  list<array<string, mixed>>  $rows  The stored rows
     * @param  string  $host  The normalized domain
     * @return list<array<string, mixed>> The rows whose host is that domain
     */
    private function rowsOnHost(array $rows, string $host): array
    {
        // Reindexing is equivalent: every caller only iterates the rows.
        // @pest-mutate-ignore:UnwrapArrayValues
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => RedirectHosts::normalize($row['host'] ?? null) === $host,
        ));
    }

    /**
     * Build a human message naming the conflicting redirect.
     *
     * @param  array<string, mixed>  $conflict  The colliding stored redirect row
     * @return string A message identifying the conflict
     */
    private function conflictMessage(array $conflict): string
    {
        // Name the conflicting source (and its target when available). The ''
        // fallback is unreachable: the detector only reports a row whose source
        // is a string matching the candidate.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $source = \is_string($conflict['source'] ?? null) ? $conflict['source'] : '';
        $host = RedirectHosts::normalize($conflict['host'] ?? null);

        // A rule on a redirect domain is named with its domain.
        if ($host !== null) {
            $source = $host.$source;
        }

        $target = \is_string($conflict['target_url'] ?? null) ? $conflict['target_url'] : null;

        if ($target !== null && $target !== '') {
            return __(
                'A redirect from :source to :target already exists within an overlapping validity window.',
                ['source' => $source, 'target' => $target]
            );
        }

        return __(
            'A redirect from :source already exists within an overlapping validity window.',
            ['source' => $source]
        );
    }

    /**
     * Build a 422 response carrying a single field error.
     *
     * @param  string  $field  The offending field handle
     * @param  string  $message  The error message
     * @return JsonResponse The unprocessable-entity response
     */
    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    /**
     * Build the review-screen payload from the pending redirect rows.
     *
     * Each entry carries the display fields plus its own approve/reject URLs and
     * a `can_review` flag — false for a request the current user submitted, so
     * the screen can disable the actions the server also forbids (UAMS-Web/uams-statamic-archive#1357).
     *
     * @param  MarketingRedirectStore  $globalStore  The main-site redirect store
     * @param  RedirectDomainStore  $domainStore  The redirect domain store
     * @return list<array<string, mixed>> The pending requests ready for the screen
     */
    private function pendingPayload(MarketingRedirectStore $globalStore, RedirectDomainStore $domainStore): array
    {
        // Resolve the current approver once for the self-review comparison.
        $currentUserId = $this->currentUserId();

        // Map each pending row to the shape the review screen consumes.
        return array_map(static function (array $row) use ($currentUserId): array {
            // The '' fallback cannot render: a row without an id fails the action
            // URL generation below either way, and every row the store writes
            // carries an id.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
            $submittedBy = \is_string($row['submitted_by'] ?? null) ? $row['submitted_by'] : null;

            return [
                'id' => $id,
                'host' => RedirectHosts::normalize($row['host'] ?? null),
                'source' => \is_string($row['source'] ?? null) ? $row['source'] : '',
                'destination' => \is_string($row['target_url'] ?? null) ? $row['target_url'] : '',
                'response' => \is_string($row['response'] ?? null) ? $row['response'] : '301',
                'requester' => \is_string($row['requester'] ?? null) ? $row['requester'] : null,
                'notes' => \is_string($row['notes'] ?? null) ? $row['notes'] : null,
                'valid_from' => \is_string($row['valid_from'] ?? null) ? $row['valid_from'] : null,
                'valid_until' => \is_string($row['valid_until'] ?? null) ? $row['valid_until'] : null,
                'can_review' => $submittedBy === null || $submittedBy !== $currentUserId,
                'approve_url' => cp_route('marketing-redirects.approve', ['redirect' => $id]),
                'reject_url' => cp_route('marketing-redirects.reject', ['redirect' => $id]),
            ];
        }, [...$globalStore->pending(), ...$domainStore->pending()]);
    }

    /**
     * The pending request a redirect id names, when the current user may review it.
     *
     * Returns the stored row when the request may be reviewed, or a JsonResponse
     * describing why it may not: a 404 for an unknown id, a 422 for an
     * already-reviewed request, and a 403 when the current user is the one who
     * submitted it (no self-approve).
     *
     * @param  string  $id  The candidate redirect row id
     * @param  RedirectRequestStore  $store  The store that holds, or would hold, the request
     * @return array<string, mixed>|JsonResponse The reviewable row, or an error response
     */
    private function reviewableRow(string $id, RedirectRequestStore $store): array|JsonResponse
    {
        // An unknown id has nothing to review.
        $row = $store->find($id);

        if ($row === null) {
            return response()->json(['message' => __('Redirect not found.')], 404);
        }

        // Only a pending request can be approved or rejected.
        if (RedirectStatus::fromRow($row) !== RedirectStatus::Pending) {
            return $this->unprocessable('status', __('This redirect has already been reviewed.'));
        }

        // A creator may never review their own request (UAMS-Web/uams-statamic-archive#1357 — no self-approve).
        $submittedBy = \is_string($row['submitted_by'] ?? null) ? $row['submitted_by'] : null;

        if ($submittedBy !== null && $submittedBy === $this->currentUserId()) {
            return response()->json(
                ['message' => __('You cannot review a redirect you requested.')],
                403
            );
        }

        // No error: the request is reviewable.
        return $row;
    }

    /**
     * The stored rows collision detection must consider (UAMS-Web/uams-statamic-archive#1357).
     *
     * Every stored row except rejected ones: an approved or legacy redirect
     * serves, and a pending request may yet be approved, so both must block a
     * duplicate request on the same source. A rejected request never serves, so
     * it does not conflict — excluding it keeps the reject → correct → resubmit
     * loop open (a rejected source can be requested again).
     *
     * @param  RedirectRequestStore  $store  The store the candidate would be saved in
     * @return list<array<string, mixed>> The rows eligible to collide
     */
    private function collisionCandidates(RedirectRequestStore $store): array
    {
        // Drop rejected rows; they are retained but never serve, so cannot conflict.
        // Reindexing is equivalent: the detector only iterates the rows.
        // @pest-mutate-ignore:UnwrapArrayValues
        return array_values(array_filter(
            $store->all(),
            static fn (array $row): bool => RedirectStatus::fromRow($row) !== RedirectStatus::Rejected,
        ));
    }

    /**
     * The store that holds a request.
     *
     * An id that is in neither store goes to the domain store, whose lookup reports it as not found.
     *
     * @param  string  $id  The request id
     * @param  MarketingRedirectStore  $globalStore  The main-site redirect store
     * @param  RedirectDomainStore  $domainStore  The redirect domain store
     * @return RedirectRequestStore The store holding the request, or the domain store when none does
     */
    private function storeHolding(string $id, MarketingRedirectStore $globalStore, RedirectDomainStore $domainStore): RedirectRequestStore
    {
        return $globalStore->find($id) === null ? $domainStore : $globalStore;
    }

    /**
     * The error for a request whose domain is unknown or does not suit its destination.
     *
     * A submitted domain that is not a valid host, or is not a vanity domain or a
     * domain the collection already serves, is refused outright. A vanity domain
     * paired with a destination it does not serve is refused unless the user can
     * approve, since an approver is who may allow the exception (UAMS-Web/aether-redirects#83).
     *
     * @param  Request  $request  The incoming create request
     * @param  MarketingRedirect  $redirect  The redirect built from it
     * @return JsonResponse|null A 422, or null when the domain is acceptable
     */
    private function hostError(Request $request, MarketingRedirect $redirect): ?JsonResponse
    {
        $submitted = $request->input('host');

        // No domain means a main-site request; a domain that does not parse is an error, never a silent main-site save.
        if ($redirect->host === null) {
            return \is_string($submitted) && trim($submitted) !== ''
                ? $this->unprocessable('host', __('Enter a valid domain, such as uams.info.'))
                : null;
        }

        // Both sides are normalized host strings, which compare the same loosely or strictly
        // @pest-mutate-ignore:TrueToFalse
        if (! \in_array($redirect->host, $this->hostOptions(), true)) {
            return $this->unprocessable('host', __(':host is not a redirect domain.', ['host' => $redirect->host]));
        }

        if (! RedirectHosts::accepts($redirect->host, $redirect->destination) && ! $this->canApprove()) {
            return $this->unprocessable('host', __(':host does not serve this destination. An approver can save the exception.', ['host' => $redirect->host]));
        }

        return null;
    }

    /**
     * The domains a request can be made for: the hosts in the domain list.
     *
     * @return list<string> The hosts in alphabetical order
     */
    private function hostOptions(): array
    {
        return RedirectDomainList::hosts();
    }

    /**
     * Whether the current user may approve requests.
     */
    private function canApprove(): bool
    {
        $user = User::current();

        // Every caller runs behind the CP auth middleware, so there is always a current user and the null-safe
        // calls never short-circuit; a super user holds every permission without listing it
        // @pest-mutate-ignore:RemoveNullSafeOperator
        return $user?->isSuper() === true || $user?->hasPermission(self::APPROVE_PERMISSION) === true;
    }

    /**
     * The authenticated Control Panel user's id, or null when unresolved.
     *
     * @return string|null The current user id
     */
    private function currentUserId(): ?string
    {
        // Resolve the acting CP user's identifier; null means no attributable user.
        // Every caller runs behind the CP auth middleware and a permission gate,
        // so there is always a current user and the null-safe call never short-circuits.
        // @pest-mutate-ignore:RemoveNullSafeOperator
        $id = User::current()?->getAuthIdentifier();

        // Only a scalar identifier coerces to a stable string id. Removing the cast
        // is equivalent for the string ids Statamic users carry.
        // @pest-mutate-ignore:RemoveStringCast
        return \is_scalar($id) ? (string) $id : null;
    }
}
