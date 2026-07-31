<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Policies\ChargeRegisterPolicy;
use App\Domain\Loans\Services\ChargeLedgerQueries;
use App\Domain\Organization\Services\BranchScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Charges\IndexChargeRegisterRequest;
use App\Http\Resources\DeductedIncomeResource;
use App\Http\Resources\PaidPenaltyResource;
use App\Http\Resources\PenaltyResource;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three charge registers — Penalty → Penalty List, Penalty → Paid Penalty,
 * and Loan Fee → Deducted Income.
 *
 * Read-only. Every figure here is written by another module — the overdue job
 * accrues, the repayment engine collects, disbursement charges — and this
 * projects those records into the shapes the three screens draw.
 *
 * See docs/modules/penalties-and-fees.md.
 */
final class ChargeRegisterController extends Controller
{
    public function __construct(
        private readonly ChargeLedgerQueries $charges,
        private readonly BranchScope $scope,
    ) {}

    /** GET /api/v1/penalties */
    public function penalties(IndexChargeRegisterRequest $request): JsonResponse
    {
        $this->authorizeRead($request);

        $filters = $request->validated();

        $query = $this->charges->accruedPenalties();
        $query = $this->charges->searchThroughLoan($query, (string) ($filters['search'] ?? ''), 'loan');

        /*
         * Branch and customer filters reach through the loan, because a
         * schedule carries neither — the loan owns the branch, which is what
         * makes "this branch's penalties" mean the penalties on its book rather
         * than the ones its staff happened to key in.
         */
        $query = $this->filterThroughLoan($query, $filters, 'loan', $this->actor($request));

        // The installment's own due date is the date this register is about.
        $query = $this->applyDateRange($query, $filters, 'due_date');
        $query = $this->applySort($query, $filters, 'due_date', 'penalty_due');

        $totals = $this->aggregate(
            $query,
            'COALESCE(SUM(penalty_due), 0) AS charged, COALESCE(SUM(penalty_paid), 0) AS paid',
        );

        $charged = Money::of((string) ($totals->charged ?? '0'));
        $paid = Money::of((string) ($totals->paid ?? '0'));

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            PenaltyResource::class,
            [
                'totalCharged' => $charged->toDecimalString(),
                'totalPaid' => $paid->toDecimalString(),
                // What collections are still chasing.
                'totalOutstanding' => $charged->subtract($paid)->toDecimalString(),
            ],
        );
    }

    /** GET /api/v1/penalties/paid */
    public function paidPenalties(IndexChargeRegisterRequest $request): JsonResponse
    {
        $this->authorizeRead($request);

        $filters = $request->validated();

        $query = $this->charges->paidPenalties();
        $query = $this->charges->searchThroughLoan($query, (string) ($filters['search'] ?? ''), 'schedule.loan');
        $query = $this->filterThroughLoan($query, $filters, 'schedule.loan', $this->actor($request));

        /*
         * Dated by the payment, not the allocation row. `payment_allocations`
         * has its own `created_at`, but that records when the engine wrote the
         * row — which for a payment allocated out of suspense weeks later is
         * not when the money arrived. The screen is about money arriving.
         */
        $query = $this->applyDateRangeThroughPayment($query, $filters);
        $query = $this->applySortForPaid($query, $filters);

        $total = Money::of((string) (
            $this->aggregate($query, 'COALESCE(SUM(penalty_allocated), 0) AS collected')->collected ?? '0'
        ));

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            PaidPenaltyResource::class,
            ['totalPaid' => $total->toDecimalString()],
        );
    }

    /** GET /api/v1/loan-fees/income */
    public function deductedIncome(IndexChargeRegisterRequest $request): JsonResponse
    {
        $this->authorizeRead($request);

        $filters = $request->validated();

        $query = $this->charges->deductedIncome();
        $query = $this->charges->searchLoans($query, (string) ($filters['search'] ?? ''));

        $query = $query
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']));

        $query = $this->scope->applyToColumn($query, $this->actor($request));

        // The fee is taken at disbursement, so that is the date it happened.
        $query = $this->applyDateRange($query, $filters, 'disbursement_date');
        $query = $this->applySort($query, $filters, 'disbursement_date', 'fee_charged');

        $totals = $this->aggregate(
            $query,
            'COALESCE(SUM(fee_charged), 0) AS income, COALESCE(SUM(principal_amount), 0) AS approved',
        );

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            DeductedIncomeResource::class,
            [
                'totalIncome' => Money::of((string) ($totals->income ?? '0'))->toDecimalString(),
                'totalApproved' => Money::of((string) ($totals->approved ?? '0'))->toDecimalString(),
            ],
        );
    }

    /**
     * Sums the filtered set, without fetching it.
     *
     * Three details that all have to be right together:
     *
     *   `toBase()` drops to the underlying query builder, so the eager loads
     *   configured for the page do not fire against a result set that has no
     *   `customer_id` in it to hydrate from.
     *
     *   `select()` REPLACES the select list rather than adding to it —
     *   `selectRaw` would leave `select *` in place, and MySQL's default
     *   ONLY_FULL_GROUP_BY rejects a bare column beside an aggregate.
     *
     *   `reorder()` clears the ORDER BY, which is meaningless over one
     *   aggregate row and expensive to compute.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     */
    private function aggregate(Builder $query, string $expression): object
    {
        return (clone $query)->toBase()
            ->reorder()
            ->select(DB::raw($expression))
            ->first() ?? (object) [];
    }

    private function authorizeRead(IndexChargeRegisterRequest $request): void
    {
        abort_unless(
            app(ChargeRegisterPolicy::class)->view($this->actor($request)),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Branch, customer and §13 branch scope, applied through the owning loan.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @param array<string, mixed> $filters
     * @return Builder<TModel>
     */
    private function filterThroughLoan(Builder $query, array $filters, string $loanPath, User $actor): Builder
    {
        /*
         * §13 branch scope, applied the way BranchScope::applyToColumn applies
         * it: short-circuit for a user who sees everything, then constrain
         * unconditionally.
         *
         * The short-circuit is not an optimisation. `visibleBranchIds` returns
         * the complete visible set in every other case, and an EMPTY array
         * means "sees no branch at all" — which is what a user with no branch
         * and no view-all grant gets. Treating empty as "no restriction" would
         * hand that user the entire book.
         */
        $visible = $this->scope->seesAllBranches($actor)
            ? null
            : $this->scope->visibleBranchIds($actor);

        return $query->whereHas($loanPath, function (Builder $loan) use ($filters, $visible): void {
            if (isset($filters['branch_id'])) {
                $loan->where('branch_id', $filters['branch_id']);
            }

            if (isset($filters['customer_id'])) {
                $loan->where('customer_id', $filters['customer_id']);
            }

            if ($visible !== null) {
                $loan->whereIn('branch_id', $visible);
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @param array<string, mixed> $filters
     * @return Builder<TModel>
     */
    private function applyDateRange(Builder $query, array $filters, string $column): Builder
    {
        return $query
            ->when(isset($filters['from']), fn ($q) => $q->whereDate($column, '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate($column, '<=', $filters['to']));
    }

    /**
     * @param Builder<PaymentAllocation> $query
     * @param array<string, mixed> $filters
     * @return Builder<PaymentAllocation>
     */
    private function applyDateRangeThroughPayment(Builder $query, array $filters): Builder
    {
        if (! isset($filters['from']) && ! isset($filters['to'])) {
            return $query;
        }

        return $query->whereHas('payment', function (Builder $payment) use ($filters): void {
            if (isset($filters['from'])) {
                $payment->whereDate('received_at', '>=', $filters['from']);
            }

            if (isset($filters['to'])) {
                $payment->whereDate('received_at', '<=', $filters['to']);
            }
        });
    }

    /**
     * Newest first unless asked otherwise — the order every one of these
     * screens opens in.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @param array<string, mixed> $filters
     * @return Builder<TModel>
     */
    private function applySort(Builder $query, array $filters, string $dateColumn, string $amountColumn): Builder
    {
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return match ($filters['sort'] ?? 'date') {
            'amount' => $query->orderBy($amountColumn, $direction)->orderBy('id', $direction),
            // Sorting by customer means sorting by the customer's name, which
            // lives two tables away; the subquery keeps it to one statement.
            'customer' => $query->orderBy($this->customerNameSubquery($query), $direction)->orderBy('id', $direction),
            default => $query->orderBy($dateColumn, $direction)->orderBy('id', $direction),
        };
    }

    /**
     * @param Builder<PaymentAllocation> $query
     * @param array<string, mixed> $filters
     * @return Builder<PaymentAllocation>
     */
    private function applySortForPaid(Builder $query, array $filters): Builder
    {
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return match ($filters['sort'] ?? 'date') {
            'amount' => $query->orderBy('penalty_allocated', $direction)->orderBy('id', $direction),
            'customer' => $query->orderBy($this->customerNameSubquery($query), $direction)->orderBy('id', $direction),
            // Through the payment, for the same reason the date filter is.
            default => $query->orderBy(
                Payment::query()
                    ->select('received_at')
                    ->whereColumn('payments.id', 'payment_allocations.payment_id'),
                $direction,
            )->orderBy('id', $direction),
        };
    }

    /**
     * The customer's assembled name, as a correlated subquery.
     *
     * Which join to correlate on depends on the register, so it is chosen from
     * the model rather than passed in — three call sites naming their own path
     * would be three chances to name the wrong one.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     */
    private function customerNameSubquery(Builder $query): QueryBuilder
    {
        $model = $query->getModel();

        $customers = DB::table('customers')
            ->selectRaw("CONCAT_WS(' ', first_name, middle_name, last_name)");

        return match (true) {
            $model instanceof Loan => $customers->whereColumn('customers.id', 'loans.customer_id'),

            $model instanceof LoanSchedule => $customers->whereIn(
                'customers.id',
                DB::table('loans')
                    ->select('customer_id')
                    ->whereColumn('loans.id', 'loan_schedules.loan_id'),
            ),

            default => $customers->whereIn(
                'customers.id',
                DB::table('loans')
                    ->select('loans.customer_id')
                    ->join('loan_schedules', 'loan_schedules.loan_id', '=', 'loans.id')
                    ->whereColumn('loan_schedules.id', 'payment_allocations.loan_schedule_id'),
            ),
        };
    }
}
