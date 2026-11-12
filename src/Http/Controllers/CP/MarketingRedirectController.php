<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Http\Controllers\CP;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use UAMSWeb\Aether\Redirects\Services\MarketingRedirectStore;
use UAMSWeb\Aether\Redirects\Services\RedirectCollisionDetector;
use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;

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
     * Render the marketing-redirect creation screen.
     */
    public function create(): InertiaResponse
    {
        // Only staff granted the redirect permission may open the utility.
        $this->authorize(self::PERMISSION);

        // Hand the Vue screen its POST target and the redirect-type options.
        return Inertia::render('aether-redirects::MarketingRedirectCreate', [
            'storeUrl' => cp_route('marketing-redirects.store'),
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
        MarketingRedirectStore $store,
        RedirectCollisionDetector $detector
    ): JsonResponse {
        // Gate the write behind the same permission as the screen.
        $this->authorize(self::PERMISSION);

        // Validate the raw submission before building the typed model.
        $validated = $this->validateInput($request);

        // Build the canonical redirect, tagging the authenticated creator so the
        // approval slice can forbid self-approval (UAMS-Web/uams-statamic-archive#1357).
        $redirect = MarketingRedirect::fromInput($validated, $this->currentUserId());

        // An inverted window (start after end) could never serve — reject it.
        if ($this->windowIsInverted($redirect)) {
            return $this->unprocessable(
                'valid_until',
                __('The "valid until" date must be on or after the "valid from" date.')
            );
        }

        // Surface a same-source collision within an overlapping window (UAMS-Web/uams-statamic-archive#1356),
        // but only against redirects that serve or may yet serve. A rejected
        // request is retained for the record and never serves (UAMS-Web/uams-statamic-archive#1357), so it must
        // not reserve its source — otherwise a rejected request would permanently
        // block re-requesting that source through the utility.
        $conflict = $detector->firstConflict(
            $redirect->source,
            $redirect->window,
            $this->collisionCandidates($store)
        );

        if ($conflict !== null) {
            return $this->unprocessable('source', $this->conflictMessage($conflict));
        }

        // Persist the redirect onto the shared default-site list as pending.
        $row = $store->create($redirect);

        // Report success with the stored row for the screen to confirm.
        return response()->json([
            'saved' => true,
            'message' => __('Redirect submitted for review. It serves on a 404 for every site once an approver approves it.'),
            'redirect' => $row,
        ], 201);
    }

    /**
     * Render the review queue of redirects awaiting approval (UAMS-Web/uams-statamic-archive#1357).
     */
    public function index(MarketingRedirectStore $store): InertiaResponse
    {
        // Only approvers may open the review queue.
        $this->authorize(self::APPROVE_PERMISSION);

        // Hand the Vue screen the pending requests, each with its action URLs.
        return Inertia::render('aether-redirects::MarketingRedirectReview', [
            'redirects' => $this->pendingPayload($store),
        ]);
    }

    /**
     * Approve a pending redirect so it begins serving (UAMS-Web/uams-statamic-archive#1357).
     */
    public function approve(string $redirect, MarketingRedirectStore $store): JsonResponse
    {
        // Only approvers may approve, and never their own request.
        $this->authorize(self::APPROVE_PERMISSION);

        // Reject an unknown id, an already-reviewed row, or a self-review.
        $error = $this->guardReviewable($redirect, $store);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        // Promote the request; it now passes the serve gate. The '' fallback is
        // unreachable: the CP auth middleware and the permission gate above both
        // need a signed-in user, so the id is never null here.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $row = $store->approve($redirect, $this->currentUserId() ?? '');

        return response()->json([
            'saved' => true,
            'message' => __('Redirect approved. It now serves on a 404 for every site.'),
            'redirect' => $row,
        ]);
    }

    /**
     * Reject a pending redirect, retaining the reason for the record (UAMS-Web/uams-statamic-archive#1357).
     */
    public function reject(Request $request, string $redirect, MarketingRedirectStore $store): JsonResponse
    {
        // Only approvers may reject, and never their own request.
        $this->authorize(self::APPROVE_PERMISSION);

        // A rejection must carry a reason so the record explains the decision.
        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2048'],
        ]);

        // Reject an unknown id, an already-reviewed row, or a self-review.
        $error = $this->guardReviewable($redirect, $store);

        if ($error instanceof JsonResponse) {
            return $error;
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
     * @param  Request  $request  The incoming create request
     * @return array{source: string, destination: string, response: string, valid_from: string|null, valid_until: string|null, requester: string|null, notes: string|null}
     */
    private function validateInput(Request $request): array
    {
        /** @var array{source: string, destination: string, response: string, valid_from?: string|null, valid_until?: string|null, requester?: string|null, notes?: string|null} $validated */
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:2048'],
            'destination' => ['required', 'string', 'max:2048'],
            'response' => ['required', 'string', Rule::in(MarketingRedirect::ALLOWED_RESPONSES)],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'requester' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2048'],
        ]);

        // Normalize optional keys to a stable shape for MarketingRedirect::fromInput().
        return [
            'source' => $validated['source'],
            'destination' => $validated['destination'],
            'response' => $validated['response'],
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'requester' => $validated['requester'] ?? null,
            'notes' => $validated['notes'] ?? null,
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
     * @param  MarketingRedirectStore  $store  The redirect store
     * @return list<array<string, mixed>> The pending requests ready for the screen
     */
    private function pendingPayload(MarketingRedirectStore $store): array
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
        }, $store->pending());
    }

    /**
     * Guard that a redirect id is a pending request the current user may review.
     *
     * Returns null when the request may be reviewed, or a JsonResponse describing
     * why it may not: a 404 for an unknown id, a 422 for an already-reviewed
     * request, and a 403 when the current user is the one who submitted it (no
     * self-approve).
     *
     * @param  string  $id  The candidate redirect row id
     * @param  MarketingRedirectStore  $store  The redirect store
     * @return JsonResponse|null An error response, or null when the request is reviewable
     */
    private function guardReviewable(string $id, MarketingRedirectStore $store): ?JsonResponse
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
        return null;
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
     * @param  MarketingRedirectStore  $store  The redirect store
     * @return list<array<string, mixed>> The rows eligible to collide
     */
    private function collisionCandidates(MarketingRedirectStore $store): array
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
