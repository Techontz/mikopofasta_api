<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Services\LoanService;
use App\Services\PaymentService;
use App\Services\Reports\DailyReport;
use App\Services\Reports\OperationalReports;
use App\Services\ReversalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live system's "Report" tab: Cash Transaction, Branch Wise Report, File, Loan Pending, Loan Repayment, Default Loan,
 * Write-off Loan, Loan Collection, Customer statement, Today Receivable, Today Received, Daily Report, Customer Development.
 * Every endpoint requires reports.view and is limited to the employee's branch scope.
 */
class LiveReportController extends ReportApiController
{
    public function __construct(private readonly OperationalReports $reports) {}

    public function cash(Request $request, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request, defaultToToday: true);

        $report = $this->reports->cash($scope);
        $report['rows'] = $this->withReversal($report['rows'], $loans);

        return $this->report($report + ['filter' => $this->filterEcho($request, $scope)]);
    }

    /**
     * Cash Transaction is where Finance raises a reversal REQUEST (maker/checker, see ReversalRequests): a deposit is a loan
     * repayment, a withdrawal is the loan's disbursement, and a top-up settlement deposit is undone by reversing its top-up
     * loan's disbursement (target_loan_id). Eligibility — including "a reversal is already pending" and segregation of
     * duties — comes from the same LoanService checks as the loan page, and is shown on the row, not only on hover.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withReversal(array $rows, LoanService $loans): array
    {
        $viewer = $this->currentEmployee();
        $mayRepayment = $viewer->can('loans.reverse_repayment');
        $mayDisbursement = $viewer->can('loans.reverse_disbursement');
        $transactions = $mayRepayment || $mayDisbursement
            ? LoanTransaction::query()->with('loan')->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id')
            : collect();
        $requests = app(ReversalRequests::class);

        return array_map(function (array $row) use ($transactions, $loans, $viewer, $mayRepayment, $mayDisbursement, $requests): array {
            $transaction = $transactions->get($row['id']);
            $settlement = $transaction !== null && $transaction->type === 'deposit' && strtoupper((string) $transaction->method) === 'TOPUP';
            $target = match (true) {
                $transaction === null => null,
                $settlement => $loans->topupLoanOf($transaction),
                $transaction->type === 'withdrawal' => $transaction->loan,
                default => null,
            };
            $type = $transaction?->type === 'deposit' && ! $settlement ? 'loan_repayment' : 'loan_disbursement';
            $allowed = $transaction !== null && ($type === 'loan_repayment' ? $mayRepayment : $mayDisbursement && $target !== null);
            $subject = $type === 'loan_repayment' ? $transaction : $target;
            $pending = $allowed ? $requests->pendingFor($subject) : null;
            $blocker = match (true) {
                ! $allowed => null,
                $type === 'loan_repayment' => $loans->repaymentReverseBlockedReason($transaction, $viewer),
                in_array($target->status, LoanStatus::disbursed(), true) => $loans->disbursementReverseBlockedReason($target, $viewer),
                default => "The loan {$target->loan_number} is {$target->status->label()}; only an active or overdue loan can have its disbursement reversed.",
            };

            return $row + [
                'method' => $transaction?->method,
                'loan_number' => $transaction?->loan?->loan_number,
                'reversal_type' => $type,
                'target_loan_id' => $type === 'loan_disbursement' ? $target?->id : $row['loan_id'],
                'target_loan_number' => $type === 'loan_disbursement' ? $target?->loan_number : $transaction?->loan?->loan_number,
                'target_amount' => $type === 'loan_disbursement' ? (float) ($target?->amount_approved ?? 0) : (float) ($row['deposit'] ?? 0),
                'is_topup_settlement' => $settlement,
                'is_topup' => $type === 'loan_disbursement' && $target?->topup_of_loan_id !== null,
                'may_reverse' => $allowed,
                'can_reverse' => $allowed && $blocker === null,
                'reverse_blocked_reason' => $blocker,
                'reversal_request_id' => $pending?->id,
            ];
        }, $rows);
    }

    public function branchwise(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return $this->report($this->reports->branchwise($scope) + ['filter' => $this->filterEcho($request, $scope)]);
    }

    public function file(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['loan_status' => ['nullable', 'in:ALL,ACTIVE,CLOSED,DEFAULT'], 'year' => ['nullable', 'integer', 'between:2000,2100']]);
        $scope = $this->reportScope($request);
        $year = $request->integer('year') ?: (int) now()->format('Y');

        return $this->report($this->reports->file($scope, $year, $request->string('loan_status')->toString() ?: null) + ['year' => $year, 'years' => $this->years($this->reports->historicalYears($scope))]);
    }

    /**
     * File → Historical Payments: the month-by-month amounts of imported historical File reports (records only).
     */
    public function historicalPayments(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $scope = $this->reportScope($request);
        $historicalYears = $this->reports->historicalYears($scope);
        $year = $request->integer('year') ?: ($historicalYears[0] ?? (int) now()->format('Y'));

        return $this->report($this->reports->historicalPayments($scope, $year) + ['year' => $year, 'years' => $historicalYears]);
    }

    /**
     * Penalty (the old system's "PENARTY REPORT"): penalties charged, live ones alongside the rows of imported
     * historical Penalty reports (records only — no penalty, payment or ledger entry stands behind those).
     */
    public function penalties(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return $this->report($this->reports->penalties($scope) + ['filter' => $this->filterEcho($request, $scope)]);
    }

    public function newLoans(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = $request->integer('year') ?: (int) now()->format('Y');

        return $this->report($this->reports->newLoans($this->reportScope($request), $year) + ['year' => $year, 'years' => $this->years()]);
    }

    public function pending(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');

        return $this->report($this->reports->pending($this->reportScope($request), CarbonImmutable::today()));
    }

    public function repayment(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');

        return $this->report($this->reports->repayment($this->reportScope($request)));
    }

    public function default(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');

        return $this->report($this->reports->default($this->reportScope($request), CarbonImmutable::today()));
    }

    /**
     * Write-off Loan (`done=0`) and Bad Debt Done (`done=1`).
     */
    public function writeOff(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');

        return $this->report($this->reports->writeOff($this->reportScope($request), $request->boolean('done')));
    }

    public function collection(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['loan_status' => ['nullable', 'in:PENDING,APROVED,DISBURSED,ACTIVE,DONE,DEFALT']]);

        return $this->report($this->reports->collection($this->reportScope($request), $request->string('loan_status')->toString() ?: null));
    }

    /**
     * Customer statement: the Payments module statement (Principal / Penalty / Interest / Insurance split per repayment,
     * running remaining debit, receipt) for one customer, optionally one loan, with the loan header of the live page.
     */
    public function statement(Request $request, PaymentService $payments, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        if (! $request->filled('customer_id')) {
            return $this->report(['customer' => null, 'loans' => [], 'loan' => null, 'rows' => [], 'totals' => (object) []]);
        }

        $customer = Customer::where('company_id', $this->currentEmployee()->company_id)->findOrFail($request->integer('customer_id'));
        $this->assertBranchAccessible((int) $customer->branch_id);

        $customerLoans = $customer->loans()->with(['category:id,name', 'branch:id,name'])->latest('id')->get();
        $loan = $request->filled('loan_id') ? $customerLoans->firstWhere('id', $request->integer('loan_id')) : null;
        abort_if($request->filled('loan_id') && $loan === null, 404);

        $rows = $payments->statement($customer, $request->input('from'), $request->input('to'))
            ->when($loan, fn ($collection) => $collection->where('loan_id', $loan->id))
            ->values();

        $paid = 0.0;
        $rows = $rows->map(function (array $row) use (&$paid): array {
            $paid += $row['deposit'];

            return $row + ['balance' => round($paid, 2)];
        });

        return $this->report([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'customer_code' => $customer->customer_code,
                'phone' => $customer->phone,
                'branch' => $customer->branch?->name,
            ],
            'loans' => $customerLoans->map(fn (Loan $option): array => [
                'value' => (string) $option->id,
                'label' => trim(($option->category?->name ?? 'LOAN').' / '.money($option->amount_approved > 0 ? $option->amount_approved : $option->amount_applied).' / '.$option->status->label()),
            ])->values()->all(),
            'loan' => $loan ? [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'reference_number' => $loan->reference_number,
                'product' => $loan->category?->name,
                'branch' => $loan->branch?->name,
                'amount' => (float) ($loan->amount_approved > 0 ? $loan->amount_approved : $loan->amount_applied),
                'total_payable' => (float) $loan->total_payable,
                'duration' => $loan->duration->label(),
                'sessions' => $loan->sessions,
                'restoration' => (float) $loan->restoration,
                'withdrawal_date' => $loan->withdrawn_at?->toDateString(),
                'end_date' => $loan->end_date?->toDateString(),
                'status' => $loan->status->label(),
                'outstanding' => $loans->outstanding($loan),
            ] : null,
            'rows' => $rows->all(),
            'totals' => collect(['deposit', 'withdrawal', 'principal', 'penalty', 'interest', 'insurance'])
                ->mapWithKeys(fn (string $column): array => [$column => round((float) $rows->sum($column), 2)])
                ->all(),
        ]);
    }

    public function receivable(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['paid_status' => ['nullable', 'in:paid,not paid,all']]);
        $scope = $this->reportScope($request, defaultToToday: true);
        $status = $request->string('paid_status')->toString();

        return $this->report($this->reports->receivable($scope, in_array($status, ['paid', 'not paid'], true) ? $status : null) + ['filter' => $this->filterEcho($request, $scope)]);
    }

    /**
     * Today by default; a chosen branch shows its whole history (unless dates are given too).
     */
    public function received(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request, defaultToToday: ! is_numeric($request->input('branch_id')));

        return $this->report($this->reports->received($scope) + ['filter' => $this->filterEcho($request, $scope)]);
    }

    public function daily(Request $request, DailyReport $daily): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request, defaultToToday: true);
        $from = $scope->from ?? $scope->to ?? CarbonImmutable::today();
        $to = $scope->to ?? $from;

        return $this->report($daily->forScope($scope, $from, $to) + [
            'filter' => $this->filterEcho($request, $scope),
            'heading' => $from->equalTo($to) ? $from->format('F, d, Y') : $from->format('F, d, Y').' - '.$to->format('F, d, Y'),
        ]);
    }

    public function development(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');

        return $this->report($this->reports->markedCustomers($this->reportScope($request)));
    }

    public function developmentShow(Customer $customer): JsonResponse
    {
        $this->authorizeAny('reports.view');
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);

        return $this->report($this->reports->development($customer));
    }

    /**
     * Years offered by the File filter: the current year back to the first loan cash-out (live: 2026 … 2023), or further
     * back to the earliest imported historical File report.
     *
     * @param  list<int>  $historicalYears
     * @return list<int>
     */
    private function years(array $historicalYears = []): array
    {
        $first = Loan::where('company_id', $this->currentEmployee()->company_id)->whereNotNull('withdrawn_at')->min('withdrawn_at');
        $current = (int) now()->format('Y');
        $start = min($current, $first ? (int) substr((string) $first, 0, 4) : $current, ...$historicalYears);

        return range($current, $start);
    }

    /**
     * @param  array<string|int, mixed>  $data
     */
    private function report(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
