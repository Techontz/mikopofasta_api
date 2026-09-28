<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\BankDepositRequest;
use App\Http\Requests\Api\Payments\TellerDepositRequest;
use App\Http\Resources\Api\V1\Payments\PaymentResource;
use App\Http\Resources\Api\V1\Payments\TellerDepositResource;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Models\TellerDeposit;
use App\Services\AccessControl;
use App\Services\Customers\CustomerDebt;
use App\Services\Ledger;
use App\Services\LoanRecoveryService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Teller (live admin/teller_dashboard + admin/data_with_depost/{customer}).
 *
 * Documents: the teller handles the cash channel — cash is recorded as PENDING_VERIFICATION in the Teller Cash account with
 * a receipt, the teller banks it (deposit slip) and Finance verifies/confirms. Non-cash receipts a branch takes (mobile money /
 * bank) are recorded PENDING_APPROVAL through {@see BranchReceiptController} and posted only when Finance approves them.
 * Cash-out of approved loans belongs to the Loans module (Loan Withdrawal).
 */
class TellerController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly LoanService $loans,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Customer loan information, outstanding breakdown, branch cashbook and statement.
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorizeAny('payments.cash', 'payments.verify');
        $this->assertBranchAccessible((int) $customer->branch_id);

        $loan = $this->payments->repayableLoan($customer)
            ?? $customer->loans()->whereNotIn('status', LoanStatus::values(...LoanStatus::inPipeline()))->latest('id')->first();
        $awaitingCashOut = $customer->loans()->where('status', LoanStatus::AwaitingDisbursement->value)->exists();

        $isRepayable = $loan !== null && in_array($loan->status, LoanStatus::repayable(), true);
        $isCashedOut = $loan !== null && in_array($loan->status, LoanStatus::disbursed(), true);
        $paid = $loan ? (float) $loan->paid_amount : 0.0;
        $outstanding = $loan ? $this->loans->outstanding($loan) : null;
        $pending = $loan ? $this->payments->pendingCash($loan) : 0.0;
        $recovery = $loan !== null && $loan->status === LoanStatus::WrittenOff ? app(LoanRecoveryService::class)->position($loan) : null;
        $debt = app(CustomerDebt::class)->summary($customer);
        $acceptsRecovery = $recovery !== null && $recovery['components_status'] !== LoanRecoveryService::COMPONENTS_AMBIGUOUS && $recovery['unrecovered'] > 0.004;

        return response()->json(['data' => [
            'customer' => [
                'id' => $customer->id,
                'full_name' => strtoupper($customer->full_name),
                'customer_code' => $customer->customer_code,
                'phone' => $customer->phone,
                'photo_url' => $customer->photo_url,
                'branch' => $customer->branch?->name,
            ],
            'loan' => $loan ? [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'reference_number' => $loan->reference_number,
                'status' => $loan->status->value,
                'status_label' => $loan->status->label(),
                'withdrawn_at' => $loan->withdrawn_at?->toDateString(),
                'end_date' => $loan->end_date?->toDateString(),
                'loan_amount' => $isCashedOut ? (float) $loan->total_payable : 0.0,
                'insurance' => $isCashedOut ? (float) $loan->insurance : 0.0,
                'restoration' => $isCashedOut ? (float) $loan->restoration : 0.0,
                'total_loan' => (float) $loan->total_payable + (float) $loan->insurance,
                'amount_paid' => $paid + max(0.0, (float) $loan->opening_paid_principal),
                'remaining_debt' => $isCashedOut ? (float) ($outstanding['total'] ?? 0) : 0.0,
                'is_repayable' => $isRepayable,
                'is_legacy_opening' => $loan->is_legacy_opening,
                'opening_paid' => (float) $loan->opening_paid_principal,
            ] : null,
            'debt' => $debt,
            'outstanding' => $outstanding,
            'pending_cash' => $pending,
            'available_to_deposit' => match (true) {
                $isRepayable => max(0.0, round($outstanding['total'] - $pending, 2)),
                $acceptsRecovery => max(0.0, round($recovery['unrecovered'] - $pending, 2)),
                default => 0.0,
            },
            'accepts_recovery' => $acceptsRecovery,
            'salary_advance' => $debt['salary_advance'],
            'recovery_amount' => $recovery['unrecovered'] ?? 0.0,
            'recovery' => $recovery,
            'penalty' => round((float) Penalty::where('customer_id', $customer->id)->where('is_waived', false)->selectRaw('COALESCE(SUM(amount - paid_amount),0) v')->value('v'), 2),
            'awaiting_cash_out' => $awaitingCashOut,
            'cashbook' => $this->cashbook(),
            'statement' => $loan ? $this->loanStatement($loan) : [],
            'receipts' => PaymentResource::collection(
                Payment::where('customer_id', $customer->id)->where('source', Payment::SOURCE_TELLER)->with(['employee', 'loan'])->latest('id')->limit(20)->get()
            ),
        ]]);
    }

    /**
     * Teller cash deposit → PENDING_VERIFICATION (live success text "Deposit successfully"). Cash for a written-off loan is held the
     * same way and becomes a recovery only when Finance confirms the bank deposit.
     */
    public function deposit(TellerDepositRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('payments.cash');
        $this->assertBranchAccessible((int) $customer->branch_id);

        $loan = $this->payments->receivableLoan($customer);
        if ($loan === null) {
            throw ValidationException::withMessages(['depost' => 'This customer has no active loan.']);
        }

        $payment = $this->payments->recordCash($loan, (float) $request->input('depost'), $request->string('p_method')->toString(), $this->currentEmployee(), provider: $request->validated('provider'));

        return $this->message('Deposit successfully', 201, [
            'data' => new PaymentResource($payment->load(['customer', 'branch', 'employee', 'loan'])),
            'receipt' => $request->boolean('recept'),
        ]);
    }

    /**
     * Printable receipt for a teller cash payment.
     */
    public function receipt(Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.cash', 'payments.verify');
        if ($payment->branch_id !== null) {
            $this->assertBranchAccessible((int) $payment->branch_id);
        }

        $payment->load(['customer', 'branch', 'employee', 'loan']);
        $outstanding = $payment->loan ? $this->loans->outstanding($payment->loan) : null;

        return response()->json(['data' => (new PaymentResource($payment))->resolve() + [
            'company' => $this->currentCompany()->name,
            'outstanding' => $outstanding,
        ]]);
    }

    /**
     * Teller cash receipts in scope (the teller's cash position and the slips they belong to).
     */
    public function cash(Request $request): JsonResponse
    {
        $this->authorizeAny('payments.cash');

        $query = $this->scoped(Payment::query())
            ->where('source', Payment::SOURCE_TELLER)
            ->when($this->isBranchTeller(), fn ($query) => $query->where('employee_id', $this->currentEmployee()->id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()), fn ($query) => $query->whereIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::Deposited->value]))
            ->with(['customer', 'branch', 'employee', 'loan', 'tellerDeposit'])
            ->latest('id');
        $this->applyFilters($query, $request, 'paid_on');

        $employee = $this->currentEmployee();

        return response()->json([
            'data' => PaymentResource::collection($query->get()),
            'teller_cash' => $this->ledger->balance($employee->company_id, Account::TellerCash, $employee->branch_id, employee: $employee),
        ]);
    }

    public function bankDeposits(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('payments.cash');

        $query = $this->scoped(TellerDeposit::query())
            ->when($this->isBranchTeller(), fn ($query) => $query->where('employee_id', $this->currentEmployee()->id))
            ->with(['branch', 'employee', 'bankAccount', 'verifier', 'confirmer', 'payments.customer'])
            ->latest('id');
        $this->applyFilters($query, $request, 'deposit_date');

        return TellerDepositResource::collection($query->get());
    }

    /**
     * Teller submits a bank deposit slip for pending cash receipts.
     */
    public function storeBankDeposit(BankDepositRequest $request): JsonResponse
    {
        $this->authorizeAny('payments.cash');

        $branchIds = Payment::whereKey($request->input('payment_ids'))->where('company_id', $this->currentEmployee()->company_id)->distinct()->pluck('branch_id');
        if ($branchIds->count() !== 1) {
            throw ValidationException::withMessages(['payment_ids' => 'Select receipts of one branch.']);
        }
        $this->assertBranchAccessible((int) $branchIds->first());

        $deposit = $this->payments->submitBankDeposit($this->currentEmployee(), (int) $branchIds->first(), $request->validated(), array_map('intval', $request->input('payment_ids')));

        return $this->message('Bank deposit submitted successfully', 201, [
            'data' => new TellerDepositResource($deposit->load(['branch', 'employee', 'bankAccount', 'payments.customer'])),
        ]);
    }

    /**
     * Teller corrects a slip Finance marked MISMATCH; it returns to Finance as PENDING for a fresh verification.
     */
    public function updateBankDeposit(BankDepositRequest $request, TellerDeposit $tellerDeposit): JsonResponse
    {
        $this->authorizeAny('payments.cash');
        abort_unless((int) $tellerDeposit->company_id === (int) $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);

        $deposit = $this->payments->updateBankDeposit($this->currentEmployee(), $tellerDeposit, $request->validated(), array_map('intval', $request->input('payment_ids')));

        return $this->message('Bank deposit updated and sent back to Finance for verification', 200, [
            'data' => new TellerDepositResource($deposit->load(['branch', 'employee', 'bankAccount', 'payments.customer'])),
        ]);
    }

    /**
     * Company bank accounts as {value,label} for the deposit slip form.
     */
    public function bankAccounts(): JsonResponse
    {
        $this->authorizeAny('payments.cash', 'payments.verify', 'payments.suspense');

        return response()->json(['data' => BankAccount::where('company_id', $this->currentEmployee()->company_id)->orderBy('name')->get()
            ->map(fn (BankAccount $account): array => ['value' => (string) $account->id, 'label' => $account->name])]);
    }

    /**
     * Cashbook on the teller page: Opening / Deposit / Withdrawal / Closing of the PRINCIPAL A/C, all from ledger
     * movements (closing = opening + deposit − withdrawal = today's ledger balance). A posting reversed on the same day
     * is left out together with its reversal (they cancel). Teller cash that Finance has not confirmed yet is not in the
     * ledger principal and is returned apart as `pending_cash`.
     *
     * The lending cash itself is HQ's — a branch holds no principal — so a branch teller's cashbook is scoped by the
     * BRANCH OF THE ENTRY (the loans their branch disbursed and collected), not by the branch of the account.
     *
     * @return array{opening: float, deposit: float, withdrawal: float, closing: float, pending_cash: float}
     */
    private function cashbook(): array
    {
        $employee = $this->currentEmployee();
        $today = CarbonImmutable::today()->toDateString();
        $branchIds = app(AccessControl::class)->branchIds($employee);

        $principalLines = fn (): Builder => JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.company_id', $employee->company_id)
            ->where('accounts.key', Account::Principal->value)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('journal_entries.branch_id', $branchIds));

        $opening = (float) $principalLines()
            ->whereDate('journal_entries.entry_date', '<', $today)
            ->selectRaw('COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) AS net')
            ->value('net');

        $movements = $principalLines()
            ->whereDate('journal_entries.entry_date', $today)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as reversals')
                ->whereColumn('reversals.reversal_of_id', 'journal_entries.id')
                ->whereDate('reversals.entry_date', $today))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as originals')
                ->whereColumn('originals.id', 'journal_entries.reversal_of_id')
                ->whereDate('originals.entry_date', $today))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits, COALESCE(SUM(journal_lines.credit), 0) AS credits')
            ->first();

        $deposit = round((float) $movements->debits, 2);
        $withdrawal = round((float) $movements->credits, 2);
        $pendingCash = (float) $this->scoped(Payment::query())
            ->where('source', Payment::SOURCE_TELLER)
            ->whereIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::Deposited->value])
            ->sum('amount');

        return [
            'opening' => round($opening, 2),
            'deposit' => $deposit,
            'withdrawal' => $withdrawal,
            'closing' => round($opening + $deposit - $withdrawal, 2),
            'pending_cash' => round($pendingCash, 2),
        ];
    }

    /**
     * Running statement: Date / Description / Deposit / Withdrawal / Balance / Remaining Debt / Penalty.
     *
     * "remain" follows {@see LoanService::outstanding()}: principal + interest + insurance less the components paid by the
     * non-reversed deposits so far, plus the non-waived penalties dated on or before the row less penalty paid by then.
     * The last row is the loan's current remaining debt (all non-waived penalties to date), so it always equals the
     * outstanding total shown on the loan and teller pages.
     *
     * @return list<array<string, mixed>>
     */
    private function loanStatement(Loan $loan): array
    {
        $balance = 0.0;
        $paid = ['principal' => 0.0, 'interest' => 0.0, 'insurance' => 0.0];
        $currentPenalty = $this->loans->outstanding($loan)['penalty'];
        $penalties = Penalty::where('loan_id', $loan->id)->get(['id', 'amount', 'penalty_date', 'is_waived']);
        $chargeable = $penalties->where('is_waived', false);
        $penaltyPayments = PenaltyPayment::whereIn('penalty_id', $chargeable->modelKeys())->standing()->get(['penalty_id', 'amount', 'paid_on']);
        $transactions = $loan->transactions()->orderBy('transaction_date')->orderBy('id')->get();
        $lastId = $transactions->last()?->id;

        return $transactions
            ->map(function (LoanTransaction $transaction) use (&$balance, &$paid, $loan, $currentPenalty, $penalties, $chargeable, $penaltyPayments, $lastId): array {
                $isDeposit = $transaction->type === 'deposit';
                $isReversed = $transaction->reversed_at !== null;
                $date = $transaction->transaction_date;
                if (! $isReversed) {
                    $balance += $isDeposit ? (float) $transaction->amount : -(float) $transaction->amount;
                    if ($isDeposit) {
                        foreach (array_keys($paid) as $component) {
                            $paid[$component] += (float) $transaction->{$component};
                        }
                    }
                }

                $penaltyDue = $transaction->id === $lastId
                    ? $currentPenalty
                    : max(0.0, round(
                        (float) $chargeable->filter(fn (Penalty $penalty): bool => $penalty->penalty_date->lte($date))->sum('amount')
                        - (float) $penaltyPayments->filter(fn (PenaltyPayment $payment): bool => $payment->paid_on->lte($date))->sum('amount'),
                        2,
                    ));
                $remaining = max(0.0, round((float) $loan->amount_approved - (float) $loan->opening_paid_principal - $paid['principal'], 2))
                    + max(0.0, round((float) $loan->interest_amount - $paid['interest'], 2))
                    + max(0.0, round((float) $loan->insurance - $paid['insurance'], 2))
                    + $penaltyDue;

                return [
                    'id' => $transaction->id,
                    'date' => $date->toDateString(),
                    'description' => $transaction->description,
                    'deposit' => $isDeposit ? (float) $transaction->amount : 0.0,
                    'withdrawal' => $isDeposit ? 0.0 : (float) $transaction->amount,
                    'balance' => round($balance, 2),
                    'remain' => round($remaining, 2),
                    'penalty' => (float) $penalties->filter(fn (Penalty $penalty): bool => $penalty->penalty_date->lte($date))->sum('amount'),
                    'principal' => (float) $transaction->principal,
                    'interest' => (float) $transaction->interest,
                    'penalty_paid' => (float) $transaction->penalty,
                    'reversed' => $isReversed,
                    'reversal_reason' => $transaction->reversal_reason,
                ];
            })->all();
    }

    private function isBranchTeller(): bool
    {
        return ! $this->currentEmployee()->can('payments.verify') && app(AccessControl::class)->branchIds($this->currentEmployee()) !== null;
    }
}
