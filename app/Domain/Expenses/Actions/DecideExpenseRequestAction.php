<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\Exceptions\BankMovementInvalidException;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects an expense request.
 *
 * Approval is the only moment an expense touches the books, and it posts what
 * the frontend's types/expense.ts states and ACCOUNT OVERVIEW §4D describes:
 *
 *     Dr  the category's own 6xxx expense account
 *     Cr  whatever paid for it
 *
 * The credit side is the branch's teller cash for an ordinary expense, and the
 * named bank account for one raised on Bank → Register Bank Expenses. Same
 * record, same approval, same debit — only where the money left from differs,
 * which is why that screen is one nullable column here rather than a second
 * expense system.
 *
 * A note on the source document, because it reads oddly. ACCOUNT OVERVIEW's
 * money-flow section writes the expense entry as "Dr Expense / Cr Income".
 * That cannot be right as double entry — crediting income to record a cost
 * would inflate revenue by exactly the amount spent, and the same document's
 * own month-end rule ("Profit = Interest - Expenses") depends on expenses
 * reducing the result rather than raising both sides. Read together with §G,
 * where every expense category owns a ledger, the intent is plainly Dr Expense
 * / Cr wherever the money left from. That is what is implemented, and it is
 * what the frontend independently specified.
 *
 * The debit line carries the branch, which is what makes the reporting spec's
 * Branch Expense Report and Branch P&L filtered queries rather than joins.
 *
 * Rejection posts nothing. It closes the request and says why in the comment.
 */
final class DecideExpenseRequestAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        ExpenseRequest $request,
        ExpenseRequestStatus $decision,
        ?string $comment,
        User $actor,
    ): ExpenseRequest {
        if (! $request->status->isDecidable()) {
            throw ExpenseException::notPending();
        }

        return DB::transaction(function () use ($request, $decision, $comment, $actor): ExpenseRequest {
            $request->loadMissing(['category.chartAccount', 'branch', 'bankAccount.chartAccount']);

            $entryId = $decision === ExpenseRequestStatus::Approved
                ? $this->post($request, $actor)
                : null;

            $request->update([
                'status' => $decision,
                // The requester's own note stands if the approver adds none.
                'comment' => $comment ?? $request->comment,
                'decided_by' => $actor->getKey(),
                'decided_at' => Date::now(),
                'journal_entry_id' => $entryId,
            ]);

            $this->audit->log(
                $decision === ExpenseRequestStatus::Approved
                    ? AuditAction::ExpenseApproved
                    : AuditAction::ExpenseRejected,
                $request,
                before: ['status' => ExpenseRequestStatus::Pending->value],
                after: [
                    'status' => $decision->value,
                    'comment' => $request->comment,
                    'journal_entry_id' => $entryId,
                ],
                actor: $actor,
            );

            return $request->load(ExpenseRequest::LIST_RELATIONS);
        });
    }

    /** Dr the category's expense account, Cr whatever paid for it. */
    private function post(ExpenseRequest $request, User $actor): int
    {
        $amount = Money::of($request->amount);
        $branch = $request->branch;
        $branchId = (int) $branch->getKey();

        $expenseAccountId = (int) $request->category->chart_account_id;
        $cashAccount = $this->creditAccount($request, $branch);

        $entry = $this->ledger->post(
            sprintf('%s — %s', $request->category->name, $request->description),
            JournalSourceType::Expense,
            (int) $request->getKey(),
            [
                JournalLine::debit($expenseAccountId, $amount, branchId: $branchId),
                JournalLine::credit((int) $cashAccount->getKey(), $amount, branchId: $branchId),
            ],
            $actor,
            $request->requested_on,
        );

        return (int) $entry->getKey();
    }

    /**
     * Where the money left from.
     *
     * A named bank account when the request has one — Bank → Register Bank
     * Expenses — and the branch till otherwise, which is what a branch spending
     * its own float does.
     *
     * The bank account must be postable. An expense filed against an account
     * that has since been closed is refused here rather than deeper inside
     * LedgerService, so the message names the account rather than an id.
     */
    private function creditAccount(ExpenseRequest $request, Branch $branch): ChartOfAccount
    {
        $bankAccount = $request->bankAccount;

        if ($bankAccount === null) {
            return $this->accounts->cashAccountFor(isCashChannel: true, branch: $branch);
        }

        $chart = $bankAccount->chartAccount;

        if ($chart === null || $chart->status !== ActiveStatus::Active) {
            throw BankMovementInvalidException::inactiveAccount($bankAccount->account_name);
        }

        return $chart;
    }
}
