<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Loans\Actions\ApplyForLoanAction;
use App\Domain\Loans\Actions\CloseLoanAction;
use App\Domain\Loans\Actions\DecideLoanApprovalAction;
use App\Domain\Loans\Actions\PrepareDisbursementAction;
use App\Domain\Loans\Actions\RunTelcoVerificationAction;
use App\Domain\Loans\Actions\VerifyMandateAction;
use App\Domain\Loans\Enums\DisbursementChannel;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanEligibilityChecker;
use App\Domain\Loans\Services\TopupEligibilityChecker;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ApplyForLoanRequest;
use App\Http\Requests\Loans\ApproveLoanRequest;
use App\Http\Requests\Loans\CloseLoanRequest;
use App\Http\Requests\Loans\IndexLoanRequest;
use App\Http\Requests\Loans\PrepareDisbursementRequest;
use App\Http\Requests\Loans\TelcoVerificationRequest;
use App\Http\Requests\Loans\VerifyMandateRequest;
use App\Http\Resources\DisbursementBatchResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Http\Resources\LoanStatusHistoryResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loan origination — the §10 workflow end to end.
 *
 * Every endpoint is branch-scoped (§13). The one nuance is credit review: §13
 * says the Credit Officer is strictly branch-scoped "no exceptions", liftable
 * only by the explicit `loans.review_cross_branch` grant, which is checked
 * separately from ordinary visibility.
 */
final class LoanController extends Controller
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchScopeGuard $guard,
    ) {}

    /**
     * GET /api/v1/loans
     */
    public function index(IndexLoanRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        $filters = $request->validated();

        $query = Loan::query()
            ->with(['customer', 'branch', 'product'])
            // Two SQL sums rather than a schedule load per row — see
            // Loan::scopeWithScheduleTotals(). This is what lets a list row
            // carry a balance at all.
            ->withScheduleTotals()
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                fn ($q) => $q->search((string) $filters['search']),
            )
            ->when(! empty($filters['status']), fn ($q) => $q->whereIn('status', $filters['status']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['loan_product_id']), fn ($q) => $q->where('loan_product_id', $filters['loan_product_id']))
            ->when(isset($filters['officer_id']), fn ($q) => $q->where('officer_id', $filters['officer_id']))
            ->when(
                isset($filters['stage']),
                fn ($q) => $q->whereIn('status', $this->stageStatuses((string) $filters['stage'])),
            )
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->latest('id');

        $query = $this->scope->applyToColumn($query, $this->actor($request));

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            LoanResource::class,
        );
    }

    /**
     * POST /api/v1/loans — spec §15.2.
     */
    public function store(ApplyForLoanRequest $request, ApplyForLoanAction $action): JsonResponse
    {
        $this->authorize('create', Loan::class);

        $actor = $this->actor($request);

        $customer = Customer::query()->findOrFail($request->validated('customerId'));

        // A loan belongs to the customer's branch, so an officer must be able
        // to reach that branch — otherwise scoping is bypassed by lending to
        // someone else's customer.
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Loan::class);

        $product = LoanProduct::query()->findOrFail($request->validated('loanProductId'));

        $loan = $action->handle(
            customer: $customer,
            product: $product,
            repaymentScheduleId: (int) $request->validated('repaymentScheduleId'),
            principalAmount: Money::of((string) $request->validated('principalAmount')),
            tenureDays: (int) $request->validated('tenureDays'),
            groupId: $request->validated('groupId'),
            officer: $actor,
        );

        return ApiResponse::data(new LoanResource($loan), status: Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/loans/{loan}
     */
    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);
        $this->guard->authorizeBranchId($this->actor($request), $loan->branch_id, Loan::class);

        return ApiResponse::data(new LoanResource(
            $loan->load([
                'customer', 'branch', 'product.interestFormula', 'repaymentSchedule', 'schedules',
                // The detail page shows the settlement record when there is
                // one; loading them here is what turns `earlySettlement` from
                // an absent key into a served answer.
                'earlySettledBy', 'earlySettlementPayment',
            ]),
        ));
    }

    /**
     * GET /api/v1/loans/{loan}/schedule
     */
    public function schedule(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);
        $this->guard->authorizeBranchId($this->actor($request), $loan->branch_id, Loan::class);

        return ApiResponse::data(
            LoanScheduleResource::collection($loan->schedules),
            [
                'totalPayable' => $loan->totalPayable()->toDecimalString(),
                'outstandingTotal' => $loan->outstandingTotal()->toDecimalString(),
                'installments' => $loan->schedules->count(),
            ],
        );
    }

    /**
     * GET /api/v1/loans/{loan}/history — the §10 transition trail.
     */
    public function history(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);
        $this->guard->authorizeBranchId($this->actor($request), $loan->branch_id, Loan::class);

        return ApiResponse::data(
            LoanStatusHistoryResource::collection($loan->statusHistory()->orderBy('id')->get()),
        );
    }

    /**
     * POST /api/v1/loans/{loan}/approve-manager — spec §15.2.
     */
    public function decide(ApproveLoanRequest $request, Loan $loan, DecideLoanApprovalAction $action): JsonResponse
    {
        $this->authorize('decideApproval', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $updated = $request->validated('decision') === 'approve'
            ? $action->approve($loan, $actor)
            : $action->reject($loan, (string) $request->validated('reason'), $actor);

        return ApiResponse::data(new LoanResource($updated->load(['customer', 'product', 'schedules'])));
    }

    /**
     * POST /api/v1/loans/{loan}/mandate/verify-otp — spec §15.2.
     */
    public function verifyMandate(VerifyMandateRequest $request, Loan $loan, VerifyMandateAction $action): JsonResponse
    {
        $this->authorize('verifyMandate', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $updated = $action->verify($loan, (string) $request->validated('otp'), $actor);

        return ApiResponse::data(new LoanResource($updated));
    }

    /**
     * POST /api/v1/loans/{loan}/mandate/retry
     */
    public function retryMandate(Request $request, Loan $loan, VerifyMandateAction $action): JsonResponse
    {
        $this->authorize('verifyMandate', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        return ApiResponse::data(new LoanResource($action->retry($loan, $actor)));
    }

    /**
     * POST /api/v1/loans/{loan}/telco-verify — spec §15.2.
     */
    public function telcoVerify(TelcoVerificationRequest $request, Loan $loan, RunTelcoVerificationAction $action): JsonResponse
    {
        $this->authorize('creditReview', $loan);

        $actor = $this->actor($request);

        /*
         * §13: cross-branch loan REVIEW is never implied by visibility — it
         * needs the explicit `loans.review_cross_branch` grant. So a user who
         * can see a loan (a Zone Manager, say) still cannot act on it here
         * unless that separate grant is attached.
         */
        if ($loan->branch_id !== $actor->branch_id
            && ! $actor->hasPermission(PermissionName::LoansReviewCrossBranch)) {
            $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);
        }

        $updated = $action->handle($loan, $request->boolean('passed'), $actor);

        return ApiResponse::data(new LoanResource($updated));
    }

    /**
     * POST /api/v1/loans/{loan}/prepare-disbursement — spec §15.2.
     */
    public function prepareDisbursement(
        PrepareDisbursementRequest $request,
        Loan $loan,
        PrepareDisbursementAction $action,
    ): JsonResponse {
        $this->authorize('disburse', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $batch = $action->prepare(
            $loan,
            DisbursementChannel::from((string) $request->validated('channel')),
            $actor,
        );

        return ApiResponse::data(
            new DisbursementBatchResource($batch),
            ['loan' => new LoanResource($loan->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/loans/{loan}/retry-disbursement — spec §15.2, max 3.
     */
    public function retryDisbursement(Request $request, Loan $loan, PrepareDisbursementAction $action): JsonResponse
    {
        $this->authorize('disburse', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $batch = $action->retry($loan, $actor);

        return ApiResponse::data(
            new DisbursementBatchResource($batch),
            ['loan' => new LoanResource($loan->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/loans/{loan}/topup-eligibility — spec §15.2, read-only.
     */
    public function topupEligibility(Request $request, Loan $loan, TopupEligibilityChecker $checker): JsonResponse
    {
        $this->authorize('view', $loan);
        $this->guard->authorizeBranchId($this->actor($request), $loan->branch_id, Loan::class);

        return ApiResponse::data($checker->check($loan->load('schedules'))->toArray());
    }

    /**
     * POST /api/v1/loans/{loan}/close — spec §15.2.
     */
    public function close(CloseLoanRequest $request, Loan $loan, CloseLoanAction $action): JsonResponse
    {
        $this->authorize('close', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $updated = $action->close(
            $loan,
            (int) ($request->validated('freezeDays') ?? CloseLoanAction::DEFAULT_FREEZE_DAYS),
            $actor,
        );

        return ApiResponse::data(new LoanResource($updated));
    }

    /**
     * POST /api/v1/loans/{loan}/cancel
     */
    public function cancel(Request $request, Loan $loan, CloseLoanAction $action): JsonResponse
    {
        $this->authorize('cancel', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $reason = $request->string('reason')->toString() ?: null;

        return ApiResponse::data(new LoanResource($action->cancel($loan, $reason, $actor)));
    }

    /**
     * POST /api/v1/loans/check-eligibility
     *
     * A dry run of the §6 gate. Not in §15.2, but the frontend's application
     * form runs `checkLoanApplication` client-side before submitting so it can
     * explain a refusal in place — this is the same check server-side, so the
     * form does not have to hold its own copy of the rules.
     */
    public function checkEligibility(
        ApplyForLoanRequest $request,
        LoanEligibilityChecker $checker,
    ): JsonResponse {
        $this->authorize('create', Loan::class);

        $actor = $this->actor($request);
        $customer = Customer::query()->findOrFail($request->validated('customerId'));
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Loan::class);

        $product = LoanProduct::query()->with('repaymentSchedules')->findOrFail($request->validated('loanProductId'));

        $violations = $checker->check(
            customer: $customer,
            product: $product,
            repaymentScheduleId: (int) $request->validated('repaymentScheduleId'),
            principalAmount: Money::of((string) $request->validated('principalAmount')),
            tenureDays: (int) $request->validated('tenureDays'),
            customerLoans: $customer->loans()->get()->all(),
            guarantorCount: $customer->guarantors()->count(),
        );

        return ApiResponse::data([
            'eligible' => $violations === [],
            'violations' => array_map(fn ($v): array => $v->toArray(), $violations),
        ]);
    }

    /**
     * @return list<string>
     */
    private function stageStatuses(string $stage): array
    {
        return array_values(array_map(
            static fn (LoanStatus $s): string => $s->value,
            array_filter(
                LoanStatus::cases(),
                static fn (LoanStatus $s): bool => $stage === 'origination' ? $s->isOrigination() : $s->isOpenBook(),
            ),
        ));
    }
}
