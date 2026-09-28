<?php

namespace App\Http\Controllers\Api\V1\Hq;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Hq\HqTransactionRequest;
use App\Http\Resources\Api\V1\Hq\HqTransactionResource;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\HqTransaction;
use App\Models\JournalEntry;
use App\Services\Approvals\ReserveProtection;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\CompanyFunds;
use App\Services\DashboardStatistics;
use App\Services\Ledger;
use App\Services\Reports\Financial\CashAccounts;
use App\Services\ShareholderAccounts;
use App\Services\TransferReversal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Headquarters Transaction → Hq Account balance, Requested Transaction and Approved Transaction.
 */
class HqTransactionController extends ApiController
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly CashAccounts $cash,
        private readonly ShareholderAccounts $shareholders,
    ) {}

    /**
     * "Hq Account balance": exactly the rows Finance sees behind the green card on the dashboard — the HQ Account List
     * ({@see DashboardStatistics::hqFunds()}), so the two screens can never disagree. UNMATCHED, SAVINGS, PROFIT and
     * DIVIDENDS are listed but not in the total ({@see DashboardStatistics::HQ_CLAIM_ROWS}): unmatched money is not HQ's
     * until it is allocated, savings belong to the customers, and profit and dividends still sit in OPERATION INCOME.
     * Real-time only, exactly like the card — these rows are balances now, not an as-at ledger listing.
     */
    public function balances(DashboardStatistics $statistics): JsonResponse
    {
        $this->authorizeAny('hq.manage');

        $company = $this->currentCompany();
        $funds = $statistics->hqFunds($company);

        $rows = collect($funds)->map(fn (float $balance, string $name): array => [
            'account' => Str::slug($name, '_'),
            'name' => $name,
            'balance' => $balance,
            'in_total' => ! in_array($name, DashboardStatistics::HQ_CLAIM_ROWS, true),
        ])->values();

        return response()->json(['data' => $rows, 'total' => $statistics->hqFundsTotal($company, $funds)]);
    }

    /**
     * status=pending (default) or approved (with from/to filter on the approval date).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('hq.manage');

        $approved = $request->input('status') === 'approved';
        $query = $this->transactions()->whereIn('status', $approved ? ['approved', TransferReversal::STATUS_REVERSED] : ['pending']);

        if ($approved) {
            $this->applyFilters($query, $request->merge(['branch_id' => null]), 'approved_at');
        }

        $transactions = $query->get();
        $posted = $transactions->where('status', '!=', TransferReversal::STATUS_REVERSED);

        return response()->json([
            'data' => HqTransactionResource::collection($transactions),
            'total' => round((float) $posted->sum('amount'), 2),
            'total_charge' => round((float) $posted->sum('charge'), 2),
            'total_reversed' => round((float) $transactions->where('status', TransferReversal::STATUS_REVERSED)->sum('amount'), 2),
        ]);
    }

    public function store(HqTransactionRequest $request): JsonResponse
    {
        $this->authorizeAny('hq.manage');

        $transaction = HqTransaction::create([
            'company_id' => $this->currentEmployee()->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'from_account' => $request->string('from_account')->toString(),
            ...$this->shareholders->columns($request->string('to_account')->toString()),
            'amount' => $request->float('amount'),
            'charge' => $request->float('charge'),
            'status' => 'pending',
        ]);

        return $this->message('Transaction Requested successfully', 201, ['data' => new HqTransactionResource($transaction->load(['employee', 'bankAccount']))]);
    }

    /**
     * Sends the amount from the HQ fund row to the shareholders' account; the charge is taken from the same row into BANK
     * CHARGES. A row that is a pool of accounts (OPERATION INCOME, and RESERVE for rows raised before rule 3) is drawn from
     * each account in proportion to what it holds, exactly as the reserve transfer does.
     * Inferred: the live approve logic could not be observed. Rule 6: the requester cannot approve their own transaction.
     * Rule 3 survives the RESERVE row: it may go only to the Investment RESERVE A/C ({@see HqFund::onlyDestination()}) and
     * only Super Admin, Admin or a Shareholder may approve it, exactly as Bank → Send Reserve To Investment; a row raised
     * before this screen moved to the fund rows, which names a reserve ledger account, still cannot be approved at all.
     */
    public function approve(HqTransaction $hqTransaction, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('hq.manage');
        abort_unless((int) $hqTransaction->company_id === (int) $this->currentEmployee()->company_id, 404);

        DB::transaction(function () use ($hqTransaction, $duties): void {
            Company::whereKey($hqTransaction->company_id)->lockForUpdate()->firstOrFail();
            $transaction = HqTransaction::whereKey($hqTransaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== 'pending') {
                throw ValidationException::withMessages(['amount' => 'Transaction already approved']);
            }
            $duties->assertCanApprove($transaction->employee_id, $this->currentEmployee(), 'HQ transaction', workflow: ApprovalPolicy::HQ_TRANSACTIONS);

            $fund = HqFund::tryFrom($transaction->from_account);
            if ($fund === null) {
                ReserveProtection::assertNotReserveSource($transaction->from_account, 'amount');
            }
            if ($fund === HqFund::Reserve && ! CompanyFunds::canDecideReserve($this->currentEmployee())) {
                throw new AccessDeniedHttpException(CompanyFunds::RESERVE_APPROVER_MESSAGE);
            }

            $entry = $this->post($transaction);

            $transaction->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->currentEmployee()->id, 'journal_entry_id' => $entry->id]);
        });

        return $this->message('Transaction Approved successfully');
    }

    public function destroy(HqTransaction $hqTransaction): JsonResponse
    {
        $this->authorizeAny('hq.manage');
        abort_unless((int) $hqTransaction->company_id === (int) $this->currentEmployee()->company_id, 404);

        if ($hqTransaction->status !== 'pending') {
            return $this->message('Approved transaction cannot be deleted', 422);
        }

        $hqTransaction->delete();

        return $this->message('Transaction Deleted successfully');
    }

    /**
     * Reverse an approved HQ transaction: the journal (amount and charge) is mirrored exactly and the row is kept with
     * status "reversed". Blocked when the receiving HQ account no longer holds the amount.
     */
    public function reverse(Request $request, HqTransaction $hqTransaction, TransferReversal $reversals): JsonResponse
    {
        $this->authorizeAny('hq.manage');
        $this->authorizeAny('accounting.reverse');
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $transaction = $reversals->reverse($hqTransaction, $validated['reason'], $this->currentEmployee());

        return $this->message('Transaction Reversed successfully', 200, ['data' => new HqTransactionResource($transaction->load(['employee', 'bankAccount', 'journalEntry', 'reversedBy', 'reversalJournalEntry']))]);
    }

    /**
     * The Request Transaction dropdowns. direction=to lists the shareholders' (Investment) accounts money can be sent to,
     * by name only — HQ never sees the Investment, so no balance is published. Anything else lists Finance's own money:
     * the HQ Account List rows behind the green card, each with its balance, and the shareholders' account of the same
     * name in pairs_with, which the form fills the TO box with. DIVIDEND shows what is owed rather than the pool it is
     * held in ({@see HqFund::claim()}); a row with locked=true may be sent to its pair and nowhere else, so the form
     * fixes the TO box. with_company=1 is the HQ expense payment source (COMPANY ACCOUNT plus the HQ accounts), untouched.
     */
    public function accountOptions(Request $request): JsonResponse
    {
        $this->authorizeAny('hq.manage', 'expenses.approve_hq');

        $companyId = (int) $this->currentEmployee()->company_id;

        if ($request->input('direction') === 'to') {
            return response()->json(['data' => array_map(
                fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                $this->shareholders->options($companyId),
            )]);
        }

        if ($request->boolean('with_company')) {
            $accounts = ReserveProtection::withoutReserve([Account::Company, ...Account::hqAccounts()]);

            return response()->json(['data' => array_map(fn (Account $account): array => [
                'value' => $account->value,
                'label' => $account->label().' - '.number_format($this->ledger->balance($companyId, $account)),
            ], $accounts)]);
        }

        $pairs = collect($this->shareholders->options($companyId))->keyBy('fund');

        return response()->json(['data' => array_map(fn (HqFund $fund): array => [
            'value' => $fund->value,
            'label' => $fund->label().' - '.number_format($this->fundBalance($companyId, $fund)),
            'pairs_with' => $pairs->get($fund->value)['value'] ?? null,
            'pairs_with_label' => $pairs->get($fund->value)['label'] ?? null,
            'locked' => $fund->onlyDestination() !== null,
        ], HqFund::sources())]);
    }

    /**
     * Dr the shareholders' account (and BANK CHARGES for the charge) / Cr the accounts the HQ row is held in, each in
     * proportion to its balance. Must run inside {@see approve()}'s transaction with the company row locked.
     * DIVIDEND is held in the OPERATION INCOME accounts and its destination is DIVIDEND A/C, so paying it is the one
     * entry: Dr DIVIDEND PAYABLE / Cr the income pool — the money leaves HQ and the dividend owed falls with it, never
     * by more than was declared.
     */
    private function post(HqTransaction $transaction): JournalEntry
    {
        $companyId = (int) $transaction->company_id;
        $amount = (float) $transaction->amount;
        $charge = (float) $transaction->charge;
        $fund = HqFund::tryFrom($transaction->from_account);

        // Rows raised before this screen moved to the HQ fund rows still name a single HQ ledger account.
        $holdings = $fund !== null
            ? $this->cash->fundHoldings($companyId, $fund)
            : [['account' => $from = Account::from($transaction->from_account), 'branch' => null, 'balance' => $this->ledger->balance($companyId, $from)]];

        $held = round(array_sum(array_column($holdings, 'balance')), 2);
        if ($held < round($amount + $charge, 2)) {
            throw ValidationException::withMessages(['amount' => 'Insufficient balance in '.($fund?->label() ?? Account::from($transaction->from_account)->label())]);
        }

        if (($claim = $fund?->claim()) !== null) {
            $owed = $this->ledger->balance($companyId, $claim, allBranches: true);
            if (round($owed, 2) + 0.001 < $amount) {
                throw ValidationException::withMessages(['amount' => $fund->label().' declared and not yet paid is only '.number_format($owed, 2)]);
            }
        }

        $destination = $this->shareholders->destination($transaction->to_account, $transaction->to_bank_account_id === null ? null : (int) $transaction->to_bank_account_id);
        $lines = [$destination + ['debit' => $amount]];
        if ($charge > 0) {
            $lines[] = ['account' => Account::BankCharges, 'debit' => $charge];
        }
        foreach ($this->split(round($amount + $charge, 2), $holdings) as $index => $credit) {
            $lines[] = ['account' => $holdings[$index]['account'], 'branch' => $holdings[$index]['branch'], 'credit' => $credit];
        }

        return $this->ledger->journal($companyId, 'Headquarter transaction', $lines, $transaction, employee: $this->currentEmployee());
    }

    /**
     * Split an amount across the accounts a fund row is held in, in proportion to each balance, giving the rounding
     * remainder to the accounts that can still cover it ({@see CompanyFunds} splits the reserve the same way).
     *
     * @param  list<array{account: Account, branch: int|null, balance: float}>  $holdings
     * @return list<float>
     */
    private function split(float $amount, array $holdings): array
    {
        $total = round(array_sum(array_column($holdings, 'balance')), 2);
        $credits = array_map(fn (array $holding): float => min($holding['balance'], floor($amount * $holding['balance'] / $total * 100) / 100), $holdings);
        $remainder = round($amount - array_sum($credits), 2);

        foreach ($holdings as $index => $holding) {
            $extra = min($remainder, round($holding['balance'] - $credits[$index], 2));
            $credits[$index] = round($credits[$index] + $extra, 2);
            $remainder = round($remainder - $extra, 2);
        }

        return $credits;
    }

    private function fundBalance(int $companyId, HqFund $fund): float
    {
        if (($claim = $fund->claim()) !== null) {
            return round($this->ledger->balance($companyId, $claim, allBranches: true), 2);
        }

        return round(array_sum(array_map(
            fn (Account $account): float => $this->ledger->balance($companyId, $account, allBranches: true),
            $fund->accounts(),
        )), 2);
    }

    /**
     * @return Builder<HqTransaction>
     */
    private function transactions(): Builder
    {
        return HqTransaction::where('company_id', $this->currentEmployee()->company_id)->with(['employee', 'bankAccount', 'journalEntry', 'reversedBy', 'reversalJournalEntry'])->latest('id');
    }
}
