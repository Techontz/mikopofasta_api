<?php

namespace App\Services;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Services\Approvals\ReserveProtection;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expense approval and payment (Documents: ACCOUNT OVERVIEW + handwritten "Finance – Expenses" note).
 *
 *  - Branch petty expenses (specification §34, ruling 2026-09-17) are approved by Finance (expenses.approve_branch)
 *    up to the company's expense_approval_limit; anything above it, and all HQ expenses, need Admin
 *    (expenses.approve_hq). Finance can never get under the Admin threshold by approving a smaller amount than requested.
 *  - Branch expenses are paid from the branch PETTY CASH A/C; HQ expenses from HQ (company) accounts and
 *    never from branch interest; bank expenses from the chosen bank account.
 *  - Every expense is posted Dr EXPENSES (tagged with branch + expense type) / Cr source account.
 */
class ExpenseApproval
{
    public const SCOPES = ['branch', 'hq', 'bank'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly TransferReversal $reversals,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * Permissions of which any one allows approving this request for the given amount. The threshold is tested against
     * the larger of the requested and the approved amount, so cutting a 2,000,000 request to 900,000 still needs Admin.
     *
     * Inferred: Admin (approve_hq) may also approve small branch expenses; bank-account expenses are
     * HQ money and follow the HQ rule.
     *
     * @return list<string>
     */
    public function requiredPermissions(ExpenseRequest $expenseRequest, float $amount): array
    {
        $amount = max($amount, (float) $expenseRequest->amount);
        if ($expenseRequest->scope === 'branch' && $amount <= $this->limit($expenseRequest->company_id)) {
            return ['expenses.approve_branch', 'expenses.approve_hq'];
        }

        return ['expenses.approve_hq'];
    }

    /**
     * Per-request memo of company approval limits.
     *
     * @var array<int, float>
     */
    private static array $limits = [];

    public function limit(int $companyId): float
    {
        return self::$limits[$companyId] ??= (float) DB::table('companies')->where('id', $companyId)->value('expense_approval_limit');
    }

    public function setLimit(int $companyId, float $limit): void
    {
        DB::table('companies')->where('id', $companyId)->update(['expense_approval_limit' => $limit, 'updated_at' => now()]);
        unset(self::$limits[$companyId]);
    }

    /**
     * Accounts an HQ expense may be paid from. Never the HQ RESERVE account (rule 3).
     *
     * @return list<Account>
     */
    public static function hqSourceAccounts(): array
    {
        return ReserveProtection::withoutReserve([Account::Company, ...Account::hqAccounts()]);
    }

    /**
     * Resolve the ledger source of the payment.
     *
     * @return array{account: Account, branch?: int|null, bank?: int|null}
     */
    public function source(ExpenseRequest $expenseRequest, ?Account $hqAccount = null): array
    {
        return match ($expenseRequest->scope) {
            'branch' => ['account' => Account::PettyCash, 'branch' => $expenseRequest->branch_id],
            'bank' => ['account' => Account::Bank, 'bank' => $expenseRequest->bank_account_id],
            default => ['account' => $hqAccount ?? Account::Company],
        };
    }

    /**
     * Approve and pay a pending request. Rule 6: the employee who requested the expense cannot accept it (unless
     * self-approval is explicitly granted); rule 3: never paid from a reserve account.
     *
     * @throws ValidationException when already processed or the source account lacks funds
     */
    public function accept(ExpenseRequest $expenseRequest, Employee $approver, float $amount, ?string $comment, ?Account $hqAccount = null): ExpenseRequest
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['req_amount' => 'Amount must be greater than zero']);
        }

        return DB::transaction(function () use ($expenseRequest, $approver, $amount, $comment, $hqAccount): ExpenseRequest {
            Company::whereKey($expenseRequest->company_id)->lockForUpdate()->firstOrFail();
            $expenseRequest = ExpenseRequest::whereKey($expenseRequest->id)->lockForUpdate()->firstOrFail();

            if ($expenseRequest->status !== 'pending') {
                throw ValidationException::withMessages(['req_amount' => 'Expenses already accepted']);
            }
            $this->duties->assertCanApprove($expenseRequest->employee_id, $approver, 'expense', workflow: ApprovalPolicy::EXPENSES);

            $source = $this->source($expenseRequest, $hqAccount);
            ReserveProtection::assertNotReserveSource($source['account'], 'from_account');
            $available = $this->ledger->balance($expenseRequest->company_id, $source['account'], $source['branch'] ?? null, $source['bank'] ?? null);

            if ($available < $amount) {
                throw ValidationException::withMessages(['req_amount' => 'Insufficient balance in '.$source['account']->label()]);
            }

            $entry = $this->ledger->transfer(
                $expenseRequest->company_id,
                $source,
                ['account' => Account::OperatingExpense, 'branch' => $expenseRequest->branch_id, 'expense_type' => $expenseRequest->expense_type_id],
                $amount,
                'Expenses: '.$expenseRequest->expenseType?->name,
                $expenseRequest,
            );

            $expenseRequest->update([
                'amount' => $amount,
                'comment' => $comment ?? $expenseRequest->comment,
                'status' => 'accepted',
                'paid_from_account' => $source['account']->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            return $expenseRequest;
        });
    }

    /**
     * Why an expense cannot be reversed now, or null when it can (read-only).
     */
    public function reverseBlockedReason(ExpenseRequest $expenseRequest): ?string
    {
        if ($expenseRequest->status === TransferReversal::STATUS_REVERSED) {
            return 'This expense has already been reversed.';
        }
        if ($expenseRequest->status !== 'accepted') {
            return 'Only accepted expenses can be reversed.';
        }
        if ($expenseRequest->journal_entry_id === null) {
            return 'This expense has no journal entry to reverse.';
        }

        return $this->reversals->entryBlockedReason($this->reversals->postedEntry($expenseRequest), 'expense');
    }

    /**
     * Reverse an accepted expense (Fund Flow Specification §24): Dr the source account (branch INTEREST A/C, HQ account
     * or bank) / Cr EXPENSES (same branch and expense type), mirroring the original entry, posted today. The request is
     * kept with status "reversed" and the reversal trace.
     *
     * Period policy: an expense of a CLOSED period whose profit has been distributed (dividend declaration or commission
     * allocation) is blocked; a closed period without distributions is allowed and recorded as an adjustment in the
     * current period (returned notice).
     *
     * @return array{expense: ExpenseRequest, notice: string|null}
     *
     * @throws ValidationException
     */
    public function reverse(ExpenseRequest $expenseRequest, string $reason, Employee $employee): array
    {
        return DB::transaction(function () use ($expenseRequest, $reason, $employee): array {
            Company::whereKey($expenseRequest->company_id)->lockForUpdate()->firstOrFail();
            $locked = ExpenseRequest::whereKey($expenseRequest->id)->lockForUpdate()->firstOrFail();

            $blocked = $this->reverseBlockedReason($locked);
            if ($blocked !== null) {
                throw ValidationException::withMessages(['reason' => $blocked]);
            }

            $entry = $this->reversals->postedEntry($locked);
            $this->duties->assertCanReverse($entry, $employee);
            $notice = $this->reversals->closedPeriodNotice($entry);
            $reversal = $this->ledger->reverse($entry, $reason);
            $before = ['status' => $locked->status];

            $locked->update([
                'status' => TransferReversal::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            $this->reversals->audit($locked, $employee, $before, $entry, $reversal, $reason);

            return ['expense' => $locked, 'notice' => $notice];
        });
    }
}
