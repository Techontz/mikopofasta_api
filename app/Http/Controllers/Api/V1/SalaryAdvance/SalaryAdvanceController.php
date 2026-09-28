<?php

namespace App\Http\Controllers\Api\V1\SalaryAdvance;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Loans\LoanWorkflowController;
use App\Http\Requests\Api\SalaryAdvance\SalaryAdvanceRequest;
use App\Http\Resources\Api\V1\SalaryAdvance\SalaryAdvancePaymentResource;
use App\Http\Resources\Api\V1\SalaryAdvance\SalaryAdvanceResource;
use App\Models\ApprovalPolicy;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\SalaryAdvancePayment;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\ReversalRequests;
use App\Services\SalaryAdvanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer salary advance workflow (live admin/perifelar_debit_*, get_perfelar_loan_done, deposit_history_per_all).
 */
class SalaryAdvanceController extends ApiController
{
    public function __construct(private readonly SalaryAdvanceService $service) {}

    /**
     * "Salary Advance Requested" — pending requests (filter: branch).
     */
    public function requested(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('salary_advance.manage');

        return SalaryAdvanceResource::collection($this->query($request)->where('status', 'pending')->get());
    }

    public function store(SalaryAdvanceRequest $request): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $this->assertBranchAccessible($request->integer('blanch_id'));

        $advance = $this->service->request(
            Customer::findOrFail($request->integer('customer_id')),
            SalaryAdvanceCategory::findOrFail($request->integer('per_id')),
            $request->float('loan_amount'),
            $this->currentEmployee(),
        );

        return $this->message('Salary Advance Requested successfully', 201, ['data' => new SalaryAdvanceResource($advance->load(['customer', 'branch']))]);
    }

    /**
     * Rule 6: the employee who requested the advance cannot approve (disburse) it.
     */
    public function approve(SalaryAdvance $salaryAdvance, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $this->assertBranchAccessible($salaryAdvance->branch_id);
        $duties->assertCanApprove($salaryAdvance->employee_id, $this->currentEmployee(), 'salary advance', workflow: ApprovalPolicy::SALARY_ADVANCES);

        $this->service->approve($salaryAdvance);

        return $this->message('Salary Advance Approved successfully');
    }

    /**
     * Record the actual collection of an approved advance's fee (C2): posts Dr LOAN FEE A/C / Cr FEE INCOME once.
     */
    public function collectFee(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $this->assertBranchAccessible($salaryAdvance->branch_id);
        $validated = $request->validate([
            'method' => ['nullable', 'in:CASH,BANK,MOBILE'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $advance = $this->service->collectFee($salaryAdvance, $this->currentEmployee(), $validated['method'] ?? 'CASH', $validated['reference'] ?? null);

        return $this->message('Salary Advance Fee Collected successfully', 200, ['data' => new SalaryAdvanceResource($advance->load(['customer', 'branch', 'feeCollector']))]);
    }

    /**
     * Delete a pending request, or reverse an approved advance (reason required; Documents: reversal only).
     */
    public function destroy(Request $request, SalaryAdvance $salaryAdvance, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $this->assertBranchAccessible($salaryAdvance->branch_id);

        $isPending = $salaryAdvance->status === 'pending';
        if (! $isPending) {
            $this->authorizeAny('accounting.reverse');
            $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Please enter the reason for reversal']);
            $duties->assertCanReverse(JournalEntry::query()->where('source_type', $salaryAdvance->getMorphClass())->where('source_id', $salaryAdvance->id)->whereNull('reversal_of_id')->orderBy('id')->first(), $this->currentEmployee());
        }

        $this->service->remove($salaryAdvance, $request->string('reason')->toString() ?: null, $this->currentEmployee());

        return $this->message($isPending ? 'Salary Advance Deleted successfully' : 'Salary Advance Reversed successfully');
    }

    /**
     * "Salary Advance Approved Today" (filter: branch).
     */
    public function approved(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('salary_advance.manage');

        return SalaryAdvanceResource::collection(
            $this->query($request)->whereIn('status', ['active', 'done'])->whereDate('approved_at', today())->get()
        );
    }

    /**
     * "Salary advance Loan" — active advances (filter: branch, optional from/to on request date).
     */
    public function active(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('salary_advance.manage');

        return SalaryAdvanceResource::collection(
            $this->applyFilters($this->query($request), $request, 'salary_advances.created_at')->where('status', 'active')->get()
        );
    }

    public function pay(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $this->assertBranchAccessible($salaryAdvance->branch_id);

        $request->validate(['amount' => ['required', 'numeric', 'min:1']], ['amount.required' => 'Please enter amount']);

        $this->service->pay($salaryAdvance, $request->float('amount'), employee: $this->currentEmployee());

        return $this->message('Deposit successfully');
    }

    /**
     * REQUEST the reversal of one deposit (maker/checker): nothing changes until another user approves it under Reversal
     * Requests.
     */
    public function reversePayment(Request $request, SalaryAdvancePayment $salaryAdvancePayment, ReversalRequests $reversals): JsonResponse
    {
        $this->authorizeAny('salary_advance.manage');
        $advance = $salaryAdvancePayment->salaryAdvance;
        abort_if($advance === null || $advance->company_id !== $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible($advance->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Please enter the reason for reversal']);

        $reversal = $reversals->requestSalaryAdvancePayment($salaryAdvancePayment, $validated['reason'], $this->currentEmployee());

        return $this->message(LoanWorkflowController::REVERSAL_REQUESTED, 201, ['reversal_request' => ['id' => $reversal->id, 'status' => $reversal->status]]);
    }

    /**
     * "Salary Advance Loan Repayment" — active and fully repaid advances.
     */
    public function repayments(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('salary_advance.manage');

        return SalaryAdvanceResource::collection($this->query($request)->whereIn('status', ['active', 'done'])->get());
    }

    /**
     * "Salary Advance Paid List" — repayments of today, or of the filtered branch and dates.
     */
    public function paid(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('salary_advance.manage');

        $payments = SalaryAdvancePayment::query()
            ->whereNull('reversed_at')
            ->whereHas('salaryAdvance', function (Builder $query) use ($request): void {
                $this->scoped($query)->where('status', '!=', 'reversed');
                $this->applyFilters($query, $request);
            })
            ->when(
                $request->filled('from') || $request->filled('to'),
                fn (Builder $query) => $query
                    ->when($request->filled('from'), fn (Builder $inner) => $inner->whereDate('paid_on', '>=', $request->date('from')->toDateString()))
                    ->when($request->filled('to'), fn (Builder $inner) => $inner->whereDate('paid_on', '<=', $request->date('to')->toDateString())),
                fn (Builder $query) => $query->whereDate('paid_on', today()),
            )
            ->with(['salaryAdvance.customer', 'salaryAdvance.branch'])
            ->latest('id')
            ->get();

        return SalaryAdvancePaymentResource::collection($payments);
    }

    /**
     * @return Builder<SalaryAdvance>
     */
    private function query(Request $request): Builder
    {
        return $this->applyFilters($this->scoped(SalaryAdvance::query()), $request)
            ->with(['customer', 'branch', 'category', 'allPayments' => fn ($query) => $query->orderBy('id')->with(['reverser', 'journalEntry', 'reversalRequests' => fn ($inner) => $inner->where('status', ReversalRequest::PENDING)->with('requester')])])
            ->withSum('payments', 'amount')
            ->latest('id');
    }
}
