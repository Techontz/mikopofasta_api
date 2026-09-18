<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomerAdvances;

use App\Domain\CustomerAdvances\Actions\CollectCustomerAdvanceAction;
use App\Domain\CustomerAdvances\Actions\CustomerAdvanceAction;
use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\CustomerAdvances\Queries\BranchMonthSummary;
use App\Domain\CustomerAdvances\Services\CustomerAdvanceCalculator;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAdvances\CollectCustomerAdvanceRequest;
use App\Http\Requests\CustomerAdvances\DisburseCustomerAdvanceRequest;
use App\Http\Requests\CustomerAdvances\IndexCustomerAdvanceRequest;
use App\Http\Requests\CustomerAdvances\StoreCustomerAdvanceRequest;
use App\Http\Resources\CustomerAdvancePaymentResource;
use App\Http\Resources\CustomerAdvanceResource;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvancePayment;
use App\Support\ApiResponse;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Salary Advance (Customer) — the register, the lifecycle and the collections.
 *
 * One collection filtered by status feeds the Request, Approved, Active and
 * Paid screens, the same decision the staff register and the expense queue
 * make: an approved advance is not a different record from a requested one, it
 * is the same record later.
 *
 * See docs/modules/salary-advance-customer.md.
 */
final class CustomerAdvanceController extends Controller
{
    public function __construct(private readonly CustomerAdvanceCalculator $calculator) {}

    /**
     * GET /api/v1/customer-advances?status=&branch_id=&search=…
     *
     * One endpoint, four screens. `status` picks which: absent for everything,
     * `approved` for Approved, `active` for the disbursed book, `paid` for the
     * settled list.
     */
    public function index(IndexCustomerAdvanceRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerAdvance::class);

        $filters = $request->validated();

        $query = CustomerAdvance::query()
            ->withListRelations()
            ->when(
                isset($filters['status']),
                fn (Builder $q) => $q->where(
                    'status',
                    CustomerAdvanceStatus::fromFrontend((string) $filters['status']),
                ),
            )
            ->when(isset($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(
                isset($filters['category_id']),
                fn (Builder $q) => $q->where('salary_advance_category_id', $filters['category_id']),
            )
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('requested_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('requested_at', '<=', $filters['to']))
            ->when(
                trim((string) ($filters['search'] ?? '')) !== '',
                fn (Builder $q) => $this->applySearch($q, trim((string) $filters['search'])),
            )
            ->latest('id');

        $advances = $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString();

        return ApiResponse::paginated(
            $advances,
            CustomerAdvanceResource::class,
            $this->totalsFor(collect($advances->items())),
        );
    }

    /** GET /api/v1/customer-advances/{advance} */
    public function show(Request $request, CustomerAdvance $advance): JsonResponse
    {
        $this->authorize('view', $advance);

        return ApiResponse::data(
            new CustomerAdvanceResource($advance->load(CustomerAdvance::LIST_RELATIONS)),
        );
    }

    /** POST /api/v1/customer-advances */
    public function store(StoreCustomerAdvanceRequest $request, CustomerAdvanceAction $action): JsonResponse
    {
        $this->authorize('create', CustomerAdvance::class);

        $customer = Customer::query()->findOrFail($request->integer('customer_id'));

        $advance = $action->request(
            $customer,
            Money::of((string) $request->validated('amount')),
            $this->actor($request),
        );

        return ApiResponse::data(new CustomerAdvanceResource($advance), status: Response::HTTP_CREATED);
    }

    /** POST /api/v1/customer-advances/{advance}/approve */
    public function approve(Request $request, CustomerAdvance $advance, CustomerAdvanceAction $action): JsonResponse
    {
        $this->authorize('decide', CustomerAdvance::class);

        return ApiResponse::data(
            new CustomerAdvanceResource($action->approve($advance, $this->actor($request))),
        );
    }

    /** POST /api/v1/customer-advances/{advance}/reject */
    public function reject(Request $request, CustomerAdvance $advance, CustomerAdvanceAction $action): JsonResponse
    {
        $this->authorize('decide', CustomerAdvance::class);

        $reason = $request->input('reason');

        return ApiResponse::data(
            new CustomerAdvanceResource(
                $action->reject($advance, $this->actor($request), is_string($reason) ? $reason : null),
            ),
        );
    }

    /**
     * POST /api/v1/customer-advances/{advance}/disburse
     *
     * A different permission from approval on purpose — §14's separation, and
     * the step where the operational money actually leaves.
     */
    public function disburse(
        DisburseCustomerAdvanceRequest $request,
        CustomerAdvance $advance,
        CustomerAdvanceAction $action,
    ): JsonResponse {
        $this->authorize('disburse', CustomerAdvance::class);

        $advance = $action->disburse(
            $advance,
            $this->actor($request),
            $request->validated('bank_account_id') === null
                ? null
                : (int) $request->validated('bank_account_id'),
            (bool) ($request->validated('from_cash') ?? false),
        );

        return ApiResponse::data(new CustomerAdvanceResource($advance));
    }

    /**
     * POST /api/v1/customer-advances/{advance}/payments
     *
     * The capital returns to the operational account the advance was paid from,
     * and the profit is recognised — the client's rule, posted in one entry.
     */
    public function collect(
        CollectCustomerAdvanceRequest $request,
        CustomerAdvance $advance,
        CollectCustomerAdvanceAction $action,
    ): JsonResponse {
        $this->authorize('collect', CustomerAdvance::class);

        $paidAt = $request->validated('paid_at');

        $payment = $action->collect(
            $advance,
            Money::of((string) $request->validated('amount')),
            PaymentChannel::from((string) $request->validated('channel')),
            $this->actor($request),
            $paidAt === null ? null : CarbonImmutable::parse((string) $paidAt),
            $request->validated('note'),
        );

        return ApiResponse::data(
            new CustomerAdvancePaymentResource($payment),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/customer-advances/payments
     *
     * The Salary Advance Repayment screen, and the transaction history the
     * client asked to keep beside the dashboard summary.
     */
    public function payments(IndexCustomerAdvanceRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerAdvance::class);

        $filters = $request->validated();

        $query = CustomerAdvancePayment::query()
            ->with(['advance.customer', 'advance.branch'])
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(
                isset($filters['customer_id']),
                fn (Builder $q) => $q->whereHas(
                    'advance',
                    fn (Builder $a) => $a->where('customer_id', $filters['customer_id']),
                ),
            )
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('paid_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('paid_at', '<=', $filters['to']))
            ->latest('paid_at')
            ->latest('id');

        $payments = $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString();

        // Reversed rows stay listed — they are the history — but are money that went back.
        $sum = fn (callable $each): string => Money::sum(
            collect($payments->items())
                ->reject(fn (CustomerAdvancePayment $p): bool => $p->isReversed())
                ->map($each),
        )->toDecimalString();

        return ApiResponse::paginated(
            $payments,
            CustomerAdvancePaymentResource::class,
            [
                'totalPaid' => $sum(fn (CustomerAdvancePayment $p): Money => $p->amountMoney()),
                'totalPrincipal' => $sum(fn (CustomerAdvancePayment $p): Money => $p->principalMoney()),
                'totalProfit' => $sum(fn (CustomerAdvancePayment $p): Money => $p->profitMoney()),
            ],
        );
    }

    /**
     * GET /api/v1/dashboard/branch-summary?month=YYYY-MM
     *
     * The Branch List popup — one month, per branch, and nothing carried over.
     * Defaults to the month in progress, which is what the dashboard asks for.
     */
    public function branchSummary(Request $request, BranchMonthSummary $summary): JsonResponse
    {
        $this->authorize('viewAny', CustomerAdvance::class);

        $month = $request->query('month');

        /*
         * Parsed against an explicit format rather than left to Carbon's
         * guessing: "2026-09" and "09-2026" both parse, and the second would
         * silently report a different year.
         */
        $period = is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')
            : CarbonImmutable::now();

        return ApiResponse::data($summary->for($period));
    }

    /**
     * Free-text search across the customer behind the advance.
     *
     * @param Builder<CustomerAdvance> $query
     * @return Builder<CustomerAdvance>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('reference', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('customer_number', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like));
        });
    }

    /**
     * The footer figures the register prints.
     *
     * Over the page rather than the whole set, matching the staff register and
     * the totals row the table itself sums — a whole-set figure here would
     * disagree with the one beside it.
     *
     * @param \Illuminate\Support\Collection<int, CustomerAdvance> $advances
     * @return array<string, string>
     */
    private function totalsFor($advances): array
    {
        $sum = fn (callable $each): string => Money::sum($advances->map($each))->toDecimalString();

        return [
            'totalPrincipal' => $sum(fn (CustomerAdvance $a): Money => $a->amountMoney()),
            'totalInterest' => $sum(fn (CustomerAdvance $a): Money => $a->interestMoney()),
            'totalChargeFee' => $sum(fn (CustomerAdvance $a): Money => $a->chargeFeeMoney()),
            'totalRepayable' => $sum(fn (CustomerAdvance $a): Money => $this->calculator->totalRepayable($a)),
            'totalPaid' => $sum(fn (CustomerAdvance $a): Money => $a->repaidMoney()),
            'totalProfit' => $sum(fn (CustomerAdvance $a): Money => $a->profitRepaidMoney()),
            'totalRemaining' => $sum(fn (CustomerAdvance $a): Money => $this->calculator->outstanding($a)),
        ];
    }
}
