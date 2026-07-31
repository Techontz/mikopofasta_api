<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Treasury\Enums\BankTransactionStatus;
use App\Domain\Treasury\Enums\BankTransactionType;
use App\Domain\Treasury\Exceptions\BankMovementInvalidException;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\BankTransaction;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a bank movement, and posts it if approved.
 *
 * What each type posts, and where the other side comes from:
 *
 *   Deposit     Dr bank · Cr branch teller cash
 *               Cash banked. It leaves the till and arrives at the bank; both
 *               sides are the company's own money, so nothing is earned.
 *
 *   Withdrawal  Dr branch teller cash · Cr bank
 *               The reverse: cash drawn to fund a branch.
 *
 *   Charge      Dr 6150 Bank Charges · Cr bank
 *               The bank's fee. A real operating cost, so it lands on the
 *               P&L — not on 4200 Write-Off, which would overstate credit
 *               losses by the price of running a bank account.
 *
 *   Transfer    Dr 7200 Offset · Cr bank
 *               Money leaving for a destination this record does not name. The
 *               Transfer Balance screens exist precisely to name it, and a
 *               transfer raised here rather than there has only one known side
 *               — so it parks against the Offset account rather than being
 *               guessed at. §5 lists Offset for exactly this kind of
 *               adjustment, and a balance sitting there is a visible prompt to
 *               resolve it rather than a silently wrong branch attribution.
 *
 * Rejection posts nothing.
 */
final class DecideBankTransactionAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $chart,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        BankTransaction $transaction,
        BankTransactionStatus $decision,
        ?string $note,
        User $actor,
    ): BankTransaction {
        if (! $transaction->status->isDecidable()) {
            throw BankMovementInvalidException::notPending();
        }

        return DB::transaction(function () use ($transaction, $decision, $note, $actor): BankTransaction {
            $transaction->loadMissing(['bankAccount.chartAccount.balances', 'branch']);

            $entryId = $decision === BankTransactionStatus::Approved
                ? $this->post($transaction, $actor)
                : null;

            $transaction->update([
                'status' => $decision,
                'note' => $note ?? $transaction->note,
                'decided_by' => $actor->getKey(),
                'decided_at' => Date::now(),
                'journal_entry_id' => $entryId,
            ]);

            $this->audit->log(
                $decision === BankTransactionStatus::Approved
                    ? AuditAction::BankTransactionApproved
                    : AuditAction::BankTransactionRejected,
                $transaction,
                before: ['status' => BankTransactionStatus::Pending->value],
                after: ['status' => $decision->value, 'journal_entry_id' => $entryId],
                actor: $actor,
            );

            return $transaction->load(BankTransaction::LIST_RELATIONS);
        });
    }

    private function post(BankTransaction $transaction, User $actor): int
    {
        $account = $transaction->bankAccount;
        $chartAccount = $account->chartAccount;

        if ($chartAccount === null || $chartAccount->status !== ActiveStatus::Active) {
            throw BankMovementInvalidException::inactiveAccount($account->account_name);
        }

        $amount = $transaction->amount();
        $bankAccountId = (int) $chartAccount->getKey();
        $branch = $transaction->branch;
        $branchId = $branch?->getKey();

        // A real bank account cannot go overdrawn just because the ledger would
        // permit a negative asset balance.
        if (! $transaction->type->increasesBalance()) {
            $available = $chartAccount->cachedBalance();

            if ($available->lessThan($amount)) {
                throw BankMovementInvalidException::insufficientFunds(
                    $account->account_name,
                    $available->toDecimalString(),
                    $amount->toDecimalString(),
                );
            }
        }

        $counterpart = $this->counterpartAccountId($transaction, $branch);

        $lines = $transaction->type->increasesBalance()
            ? [
                JournalLine::debit($bankAccountId, $amount, branchId: $branchId),
                JournalLine::credit($counterpart, $amount, branchId: $branchId),
            ]
            : [
                JournalLine::debit($counterpart, $amount, branchId: $branchId),
                JournalLine::credit($bankAccountId, $amount, branchId: $branchId),
            ];

        $entry = $this->ledger->post(
            sprintf('%s — %s %s', $transaction->type->label(), $account->bank_name, $account->account_number),
            JournalSourceType::Transfer,
            (int) $transaction->getKey(),
            $lines,
            $actor,
            $transaction->transacted_on,
        );

        return (int) $entry->getKey();
    }

    /**
     * The account on the other side of the entry.
     *
     * Deposits and withdrawals move cash between the bank and a branch till, so
     * they need a branch — without one there is no till to move it to, and the
     * movement is refused rather than posted against a guess.
     */
    private function counterpartAccountId(BankTransaction $transaction, ?Branch $branch): int
    {
        return match ($transaction->type) {
            BankTransactionType::Deposit, BankTransactionType::Withdrawal => $branch === null
                ? throw BankMovementInvalidException::wrongDestination(
                    'A deposit or withdrawal must name the branch whose till the cash moves to or from.',
                )
                : (int) $this->chart->tellerCash($branch)->getKey(),

            BankTransactionType::Charge => $this->chart->systemId(SystemAccountCode::BankCharges),

            BankTransactionType::Transfer => $this->chart->systemId(SystemAccountCode::Offset),
        };
    }
}
