<?php

namespace App\Http\Controllers\Api\V1\Capital;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Capital\CapitalRequest;
use App\Models\ApprovalPolicy;
use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\LoanTransaction;
use App\Models\ShareHolder;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\CapitalContributions;
use App\Services\Ledger;
use App\Services\Reports\Financial\CashAccounts;
use App\Services\Reports\Financial\ClosingEntries;
use App\Services\Reports\Financial\ProfitLossReport;
use App\Services\ShareholderOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Capital → Add Capitals (live admin/capital). Documents (ACCOUNT OVERVIEW "Capital Account", handwritten note):
 * capital is added by the super admin; only capital.view users see it. Every contribution is its own row posted
 * Dr the receiving company account (COMPANY ACCOUNT for CASH, the selected bank account for BANK) / Cr CAPITAL ACCOUNT.
 * Contributions are financial transactions; ownership comes from the share register ({@see ShareholderOwnership}) and
 * company balances are reported separately.
 *
 * Rule 6: a new contribution is recorded PENDING (no journal, excluded from every total) and posted when a different
 * authorised user approves it (POST capitals/{capital}/approve) or rejected (POST capitals/{capital}/reject).
 */
class CapitalController extends ApiController
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['bankAccount', 'recorder', 'approver', 'rejecter', 'journalEntry', 'shareTransactions', 'asset', 'reverser', 'reversalJournalEntry'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly CapitalContributions $contributions,
        private readonly ShareholderOwnership $ownership,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        $companyId = $this->currentEmployee()->company_id;
        $capitals = Capital::where('company_id', $companyId)
            ->with(['bankAccount', 'recorder', 'approver', 'rejecter', 'journalEntry.lines.account.branch', 'journalEntry.lines.account.bankAccount', 'shareTransactions', 'asset', 'reverser', 'reversalJournalEntry'])
            ->orderBy('id')
            ->get()
            ->groupBy('share_holder_id');
        $banks = $this->bankBalances($companyId);
        $companyCash = $this->ledger->balance($companyId, Account::Company) + 0.0;

        return response()->json(['data' => [
            'share_holders' => $this->ownership->summary($companyId)->map(fn (array $row): array => [
                'id' => $row['share_holder']->id,
                'name' => $row['share_holder']->full_name,
                'total' => $row['total_contributed'],
                'total_contributed' => $row['total_contributed'],
                'cash_contributed' => $row['cash_contributed'],
                'bank_contributed' => $row['bank_contributed'],
                'asset_contributed' => $row['asset_contributed'],
                'shares' => $row['shares'],
                'ownership_percent' => $row['ownership_percent'],
                'holding_value' => $row['holding_value'],
                'capitals' => ($capitals[$row['share_holder']->id] ?? collect())->map(fn (Capital $capital): array => $this->presentContribution($capital))->values(),
            ])->values(),
            'share_holder_capital' => $this->ownership->totalContributed($companyId),
            'contribution_breakdown' => $this->ownership->companyBreakdown($companyId),
            'company_capital' => $companyCash,
            'company_cash_balance' => $companyCash,
            'bank_balances' => $banks,
            'bank_balance_total' => round($banks->sum('balance'), 2),
            'capital_account' => $this->ledger->balance($companyId, Account::Capital, allBranches: true) + 0.0,
        ]]);
    }

    public function store(CapitalRequest $request): JsonResponse
    {
        $this->authorizeAny('capital.manage');

        $employee = $this->currentEmployee();
        $result = $this->contributions->requestContribution(
            ShareHolder::where('company_id', $employee->company_id)->findOrFail($request->integer('share_id')),
            $request->float('amount'),
            $request->string('pay_method')->toString(),
            $request->filled('bank_account_id') ? $request->integer('bank_account_id') : null,
            $employee,
            $request->input('recept'),
            $request->input('chaque_no'),
            $request->filled('contributed_at') ? CarbonImmutable::parse($request->string('contributed_at')->toString()) : null,
            $request->input('idempotency_key'),
            fn (Capital $capital) => $this->storeReceipt($capital, $request->file('receipt_file')),
        );

        return $this->message(
            $result['created'] ? 'Capital Recorded successfully — awaiting approval by another authorised user' : 'Capital was already recorded',
            $result['created'] ? 201 : 200,
            ['data' => $this->presentContribution($result['capital']->refresh()->load(self::RELATIONS))],
        );
    }

    /**
     * Approve a pending contribution: posts Dr COMPANY ACCOUNT / bank, Cr CAPITAL ACCOUNT. The employee who recorded it
     * cannot approve it (rule 6).
     */
    public function approve(Capital $capital): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        abort_unless((int) $capital->company_id === (int) $this->currentEmployee()->company_id, 404);

        $approved = $this->contributions->approve($capital, $this->currentEmployee());

        return $this->message('Capital Contribution Approved successfully', 200, ['data' => $this->presentContribution($approved->load(self::RELATIONS))]);
    }

    /**
     * Reject a pending contribution (nothing was posted).
     */
    public function reject(Request $request, Capital $capital): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        abort_unless((int) $capital->company_id === (int) $this->currentEmployee()->company_id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $rejected = $this->contributions->reject($capital, $validated['reason'], $this->currentEmployee());

        return $this->message('Capital Contribution Rejected successfully', 200, ['data' => $this->presentContribution($rejected->load(self::RELATIONS))]);
    }

    /**
     * Contribution history of one shareholder with their contribution total and share-register ownership.
     */
    public function history(ShareHolder $shareHolder): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        $ownership = $this->ownership->forShareHolder($shareHolder);

        return response()->json(['data' => [
            'share_holder' => ['id' => $shareHolder->id, 'first_name' => $shareHolder->first_name, 'middle_name' => $shareHolder->middle_name, 'last_name' => $shareHolder->last_name, 'name' => $shareHolder->full_name],
            'total_contributed' => $ownership['total_contributed'],
            'cash_contributed' => $ownership['cash_contributed'],
            'bank_contributed' => $ownership['bank_contributed'],
            'asset_contributed' => $ownership['asset_contributed'],
            'shares' => $ownership['shares'],
            'total_shares' => $ownership['total_shares'],
            'ownership_percent' => $ownership['ownership_percent'],
            'holding_value' => $ownership['holding_value'],
            'company_total_contributed' => $this->ownership->totalContributed((int) $shareHolder->company_id),
            'contributions' => $shareHolder->capitals()->with(['bankAccount', 'recorder', 'approver', 'rejecter', 'journalEntry.lines.account.branch', 'journalEntry.lines.account.bankAccount', 'shareTransactions', 'asset', 'reverser', 'reversalJournalEntry'])->orderBy('id')->get()
                ->map(fn (Capital $capital): array => $this->presentContribution($capital))->values(),
        ]]);
    }

    /**
     * Company capital position: historical shareholder contributions (financial records, not ownership) kept apart from what the
     * company holds and earns now — money balances by group ({@see CashAccounts}), income, expenses and loans.
     *
     * Income and expenses are the ledger movements of the income / expense accounts in the range (all time without dates),
     * month-end closing entries excluded ({@see ClosingEntries}) so a closed month still shows what it earned and spent.
     * "income" is gross ledger income (interest before the reserve cut, fees, penalties, insurance, recoveries); the reserve set
     * aside from interest is listed in the breakdown. The profit definition of the month-end close is not changed here.
     */
    public function position(Request $request, ProfitLossReport $profitLoss, CashAccounts $cash): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $companyId = $this->currentEmployee()->company_id;
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : null;
        $raw = $profitLoss->companyFigures($companyId, $from ?? CarbonImmutable::create(1970), $to ?? CarbonImmutable::create(2999, 12, 31));
        $lines = fn (string $type): array => array_values(array_map(
            fn (Account $account): array => ['key' => $account->value, 'label' => $account->label(), 'amount' => (float) ($raw[$account->value] ?? 0)],
            array_filter(Account::cases(), fn (Account $account): bool => $account->type() === $type),
        ));

        $incomeLines = $lines('income');
        $expenseLines = $lines('expense');
        $income = round(array_sum(array_column($incomeLines, 'amount')), 2) + 0.0;
        $expenses = round(array_sum(array_column($expenseLines, 'amount')), 2) + 0.0;

        $banks = $this->bankBalances($companyId);
        $breakdown = $cash->breakdown($companyId);
        $companyCash = $this->ledger->balance($companyId, Account::Company) + 0.0;
        $lendingCash = $this->ledger->balance($companyId, Account::Principal, allBranches: true) + 0.0;
        $withdrawals = LoanTransaction::where('company_id', $companyId)->where('type', 'withdrawal')->whereNull('reversed_at')
            ->when($from, fn ($query) => $query->whereDate('transaction_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->whereDate('transaction_date', '<=', $to->toDateString()));

        return response()->json(['data' => [
            'shareholder_contributions' => [
                'total' => $this->ownership->totalContributed($companyId),
                'share_holders' => $this->ownership->summary($companyId)->map(fn (array $row): array => [
                    'id' => $row['share_holder']->id,
                    'name' => $row['share_holder']->full_name,
                    'total_contributed' => $row['total_contributed'],
                    'contributions_count' => $row['contributions_count'],
                    'shares' => $row['shares'],
                    'ownership_percent' => $row['ownership_percent'],
                ])->values(),
            ],
            'balances' => [
                'company_cash' => $companyCash,
                'banks' => $banks,
                'bank_total' => round($banks->sum('balance'), 2),
                'lending_cash' => $lendingCash,
                'total_cash_and_bank' => round($companyCash + $banks->sum('balance') + $lendingCash, 2),
                'total_cash_and_bank_label' => 'Company A/C + bank accounts + PRINCIPAL A/C',
                'money_groups' => $breakdown,
                'total_money_assets' => CashAccounts::total($breakdown),
            ],
            'income' => $income,
            'income_breakdown' => $incomeLines,
            'reserve_from_interest' => (float) ($raw['reserve'] ?? 0),
            'expenses' => $expenses,
            'expense_breakdown' => $expenseLines,
            'net_income' => round($income - $expenses, 2),
            'loans' => [
                'disbursed_count' => (clone $withdrawals)->count(),
                'disbursed_total' => round((float) $withdrawals->sum('amount'), 2),
                'outstanding_principal' => $this->ledger->balance($companyId, Account::LoanReceivable, allBranches: true) + 0.0,
            ],
            'capital_account_ledger' => $this->ledger->balance($companyId, Account::Capital, allBranches: true) + 0.0,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]]);
    }

    /**
     * The uploaded receipt (PDF or image), streamed from private storage to users who may see capital.
     */
    public function receipt(Capital $capital): StreamedResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        abort_unless($capital->receipt_file && Storage::disk(Capital::DISK)->exists($capital->receipt_file), 404);

        return Storage::disk(Capital::DISK)->response($capital->receipt_file, $capital->receipt_file_name, ['Cache-Control' => 'private, max-age=300'], 'inline');
    }

    /**
     * Attach or replace the receipt file. The capital amount and its ledger entry are not editable (reversal only);
     * only the supporting document can be replaced. Audit-logged through the Auditable trait.
     */
    public function replaceReceipt(Request $request, Capital $capital): JsonResponse
    {
        $this->authorizeAny('capital.manage');

        $request->validate(
            ['receipt_file' => ['required', 'file', 'mimes:'.implode(',', CapitalRequest::RECEIPT_MIMES), 'max:'.CapitalRequest::RECEIPT_MAX_KB]],
            [],
            ['receipt_file' => 'import receipt'],
        );

        $this->storeReceipt($capital, $request->file('receipt_file'));

        return $this->message('Receipt Updated successfully');
    }

    /**
     * Reverse a wrongly recorded CASH or BANK contribution: Dr CAPITAL ACCOUNT / Cr the receiving account, posted today
     * ({@see CapitalContributions::reverse()}). Blocked while shares issued against it are active, for asset contributions
     * (asset register) and when the receiving account no longer holds the money.
     */
    public function reverse(Request $request, Capital $capital): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->authorizeAny('accounting.reverse');
        abort_unless((int) $capital->company_id === (int) $this->currentEmployee()->company_id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $reversed = $this->contributions->reverse($capital, $validated['reason'], $this->currentEmployee());

        return $this->message('Capital Contribution Reversed successfully', 200, [
            'data' => $this->presentContribution($reversed->load(self::RELATIONS)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentContribution(Capital $capital): array
    {
        return [
            'id' => $capital->id,
            'share_holder_id' => $capital->share_holder_id,
            'amount' => (float) $capital->amount,
            'pay_method' => $capital->pay_method,
            'receiving_account' => $capital->receiving_account,
            'receiving_account_label' => $capital->receivingAccountLabel(),
            'bank_account_id' => $capital->bank_account_id,
            'bank_account' => $capital->bankAccount?->name,
            'receipt_number' => $capital->receipt_number,
            'cheque_number' => $capital->cheque_number,
            'receipt_file_name' => $capital->receipt_file_name,
            'receipt_endpoint' => $capital->receipt_file ? "capital/capitals/{$capital->id}/receipt?v=".$capital->updated_at?->timestamp : null,
            'recorded_by' => $capital->recorder?->full_name,
            'contributed_at' => ($capital->contributed_at ?? $capital->created_at)?->format('Y-m-d H:i:s'),
            'journal_entry_id' => $capital->journal_entry_id,
            'journal_reference' => $capital->journalEntry?->reference,
            'share_transaction_reference' => $capital->shareTransactions->firstWhere('status', 'completed')?->reference,
            'asset_id' => $capital->asset?->id,
            'asset_code' => $capital->asset?->asset_code,
            'asset_name' => $capital->asset?->name,
            'reversed' => $capital->isReversed(),
            'status' => $capital->isReversed() ? 'reversed' : ($capital->status ?? Capital::STATUS_POSTED),
            'approved_by' => $capital->approver?->full_name,
            'approved_at' => $capital->approved_at?->toDateTimeString(),
            'rejected_by' => $capital->rejecter?->full_name,
            'rejected_at' => $capital->rejected_at?->toDateTimeString(),
            'rejection_reason' => $capital->rejection_reason,
            'source' => $capital->source,
            'source_label' => $capital->isFromShareholderPortal() ? 'Submitted by shareholder' : 'Recorded by staff',
            'cancelled_at' => $capital->cancelled_at?->toDateTimeString(),
            ...app(SegregationOfDuties::class)->flags($capital->isPending() ? $this->contributions->initiatorIds($capital) : $capital->recorded_by, $this->currentEmployee(), $capital->isPending() && $capital->pay_method !== 'ASSET', Gate::allows('capital.manage'), workflow: ApprovalPolicy::CAPITAL_CONTRIBUTIONS),
            'can_reject' => $capital->isPending() && $capital->pay_method !== 'ASSET' && Gate::allows('capital.manage'),
            'reversed_at' => $capital->reversed_at?->toDateTimeString(),
            'reversed_by' => $capital->reverser?->full_name,
            'reversal_reason' => $capital->reversal_reason,
            'reversal_reference' => $capital->reversalJournalEntry?->reference,
            ...$this->contributions->reverseFlags($capital, Gate::allows('capital.manage') && Gate::allows('accounting.reverse')),
            'created_at' => $capital->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, balance: float}>
     */
    private function bankBalances(int $companyId): Collection
    {
        return BankAccount::where('company_id', $companyId)->orderBy('id')->get()
            ->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance() + 0.0])
            ->values();
    }

    private function storeReceipt(Capital $capital, ?UploadedFile $file): void
    {
        if ($file !== null) {
            $capital->attachReceipt($file);
        }
    }
}
