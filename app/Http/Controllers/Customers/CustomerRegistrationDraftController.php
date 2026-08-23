<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerRegistrationDraftResource;
use App\Models\Customer;
use App\Models\CustomerRegistrationDraft;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Save and resume for a registration in progress.
 *
 * See the 2026_08_26 migration for why a draft is a row of its own rather
 * than a half-created Customer or a `localStorage` key.
 *
 * SCOPING. A draft is visible to its author, and to anyone who may see the
 * branch it belongs to — a supervisor picking up work an officer left, which
 * is the case this exists for. `BranchScopeGuard` applies exactly as it does
 * to the customer the draft will become, so §13 is not weakened by the
 * intermediate state.
 *
 * Nothing here is trusted. The payload is stored verbatim and replayed into
 * the ordinary form on resume; the customer is created by
 * `POST /customers` under RegisterCustomerRequest like any other. A draft
 * cannot smuggle a value past validation because a draft never writes a
 * customer.
 */
final class CustomerRegistrationDraftController extends Controller
{
    public function __construct(
        private readonly BranchScopeGuard $guard,
        private readonly BranchScope $scope,
    ) {}

    /**
     * GET /api/v1/customer-drafts — what is still open.
     *
     * Own drafts first, then the rest of the branch's. An officer resuming
     * their own work is the common case and should not have to look for it
     * among a supervisor's list.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $actor = $this->actor($request);

        $drafts = CustomerRegistrationDraft::query()
            ->open()
            ->with('author')
            ->where(function ($query) use ($actor): void {
                /*
                 * Own drafts always, plus the branch's — but only the branches
                 * §13 already lets this user see. `visibleBranchIds` is the
                 * same source every other branch-scoped query uses, so a draft
                 * is never reachable by someone who could not reach the
                 * customer it will become.
                 */
                $query->where('created_by', $actor->getKey())
                    ->orWhereIn('branch_id', $this->scope->visibleBranchIds($actor));
            })
            ->orderByRaw('created_by = ? DESC', [$actor->getKey()])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return ApiResponse::data(CustomerRegistrationDraftResource::collection($drafts));
    }

    /**
     * GET /api/v1/customer-drafts/{draft} — the payload, to reopen the wizard.
     */
    public function show(Request $request, CustomerRegistrationDraft $draft): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $this->guard->authorizeBranchId($this->actor($request), $draft->branch_id, Customer::class);

        return ApiResponse::data(new CustomerRegistrationDraftResource($draft->load('author')));
    }

    /**
     * POST /api/v1/customer-drafts — create or overwrite.
     *
     * The client sends `id` to overwrite the draft it is already editing, and
     * omits it for a new one. Upserting on an id the client owns rather than
     * on "the current user's draft" is what allows an officer to have two
     * registrations open at once, which the browser-key version could not.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $actor = $this->actor($request);

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'branchId' => ['required', 'integer', 'exists:branches,id'],
            'label' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'step' => ['required', 'integer', 'min:0', 'max:20'],
            /*
             * The wizard's own shape, stored as sent. Validating it here would
             * mean a second schema that has to track the form's — and the form
             * changes far more often than this endpoint. What matters is that
             * nothing reaches `customers` from here; see the class note.
             */
            /* `present`, not `required`: an officer who saves a draft having
               typed only a name sends an object with empty values, and
               `required` treats an empty array as absent. */
            'payload' => ['present', 'array'],
        ]);

        $this->guard->authorizeBranchId($actor, (int) $data['branchId'], Customer::class);

        $draft = null;

        if (isset($data['id'])) {
            $draft = CustomerRegistrationDraft::query()->find($data['id']);

            /*
             * Only the author may overwrite. A supervisor may READ a branch
             * draft and resume it — which creates their own — but silently
             * writing over what an officer is still typing on another machine
             * is a lost afternoon nobody can reconstruct.
             */
            if ($draft !== null && $draft->created_by !== $actor->getKey()) {
                abort(Response::HTTP_FORBIDDEN, 'This draft belongs to another officer.');
            }

            /* Already submitted: the customer exists, and re-saving over the
               draft would reopen a registration that is finished. */
            if ($draft !== null && $draft->submitted_at !== null) {
                abort(Response::HTTP_CONFLICT, 'This registration has already been submitted.');
            }
        }

        if ($draft === null) {
            $draft = new CustomerRegistrationDraft;
            $draft->created_by = $actor->getKey();
        }

        $draft->fill([
            'branch_id' => (int) $data['branchId'],
            'label' => $data['label'],
            'phone' => $data['phone'] ?? null,
            'step' => (int) $data['step'],
            'payload' => $data['payload'],
        ])->save();

        return ApiResponse::data(
            new CustomerRegistrationDraftResource($draft->load('author')),
            status: $draft->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * POST /api/v1/customer-drafts/{draft}/submitted
     *
     * Marks a draft as having become a customer. Called after
     * `POST /customers` succeeds, rather than deleting the row: keeping it is
     * what makes "this registration took three sittings" answerable, and what
     * stops a retried submit creating a second customer from the same draft.
     */
    public function markSubmitted(Request $request, CustomerRegistrationDraft $draft): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $draft->branch_id, Customer::class);

        $data = $request->validate([
            'customerId' => ['required', 'integer', 'exists:customers,id'],
        ]);

        /* Idempotent. The client calls this immediately after a successful
           registration, and a retry must not look like a second one. */
        if ($draft->submitted_at === null) {
            $draft->update([
                'customer_id' => (int) $data['customerId'],
                'submitted_at' => now(),
            ]);
        }

        return ApiResponse::data(new CustomerRegistrationDraftResource($draft->fresh()->load('author')));
    }

    /**
     * DELETE /api/v1/customer-drafts/{draft} — the officer discarding it.
     */
    public function destroy(Request $request, CustomerRegistrationDraft $draft): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $actor = $this->actor($request);

        abort_unless(
            $draft->created_by === $actor->getKey(),
            Response::HTTP_FORBIDDEN,
            'This draft belongs to another officer.',
        );

        $draft->delete();

        return ApiResponse::data(['removed' => true]);
    }
}
