<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Repayments\Enums\CashDepositStatus;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Exceptions\ReconciliationException;
use App\Enums\AuditAction;
use App\Models\BankAccount;
use App\Models\CashDeposit;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Finance confirms that banked cash actually arrived — §15.3's
 * `POST /finance/bank-reconciliation`, and the single largest hole this phase
 * closes.
 *
 * Until now nothing could move a cash payment out of `pending_verification`, so
 * **no payment in the system had ever reached `confirmed`**. That is what forced
 * OSC-7: the collections reports could not filter on `confirmed` without
 * reporting zero, so they anchored on the ledger instead. With this action
 * shipped, `confirmed` becomes reachable and OSC-7 becomes a real choice rather
 * than a workaround.
 *
 * The posting is §7's, and it is the second half of a journey that began at the
 * counter:
 *
 *     Dr  bank account       (the bank now holds it)
 *       Cr  branch teller cash  (the till no longer does)
 *
 * No income is recognised here and no schedule moves. That already happened
 * when the teller took the money — this entry only relocates it. Treating
 * reconciliation as the point of recognition would delay every branch's revenue
 * by however long its deposits take to clear.
 *
 * ## What is verified before anything posts
 *
 * The teller declares which payments a deposit covers. Finance verifies that
 * declaration rather than accepting it: the named payments must exist, must
 * still be awaiting verification, and must sum exactly to the amount banked.
 * §7's "amount mismatch → investigation" is refused here rather than reconciled
 * optimistically and queried afterwards, because a reconciliation that tolerates
 * a difference is not a reconciliation.
 */
final class ReconcileCashDepositAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CashDeposit $deposit, User $financeUser): CashDeposit
    {
        if ($deposit->status === CashDepositStatus::Confirmed) {
            throw ReconciliationException::alreadyReconciled((int) $deposit->getKey());
        }

        $paymentIds = $deposit->matched_payment_ids ?? [];

        if ($paymentIds === []) {
            throw ReconciliationException::noPayments((int) $deposit->getKey());
        }

        $payments = Payment::query()->whereIn('id', $paymentIds)->get();

        foreach ($payments as $payment) {
            if ($payment->status !== PaymentStatus::PendingVerification) {
                throw ReconciliationException::notPending($payment->payment_reference);
            }
        }

        $declared = Money::sum($payments->map(fn (Payment $p): Money => Money::of($p->amount)));
        $banked = $deposit->amountMoney();

        if (! $declared->equals($banked)) {
            throw ReconciliationException::amountMismatch($banked, $declared);
        }

        return DB::transaction(function () use ($deposit, $payments, $banked, $financeUser): CashDeposit {
            $bankAccount = BankAccount::query()->findOrFail($deposit->bank_account_id);
            $deposit->loadMissing('branch');

            $entry = $this->ledger->post(
                sprintf('Cash deposit reconciliation — %s', $deposit->branch->name),
                JournalSourceType::Transfer,
                (int) $deposit->getKey(),
                [
                    JournalLine::debit(
                        (int) $bankAccount->chart_account_id,
                        $banked,
                        $deposit->branch_id,
                    ),
                    JournalLine::credit(
                        (int) $this->accounts->tellerCash($deposit->branch)->getKey(),
                        $banked,
                        $deposit->branch_id,
                    ),
                ],
                $financeUser,
            );

            $now = Date::now();

            foreach ($payments as $payment) {
                $payment->update([
                    'status' => PaymentStatus::Confirmed,
                    'confirmed_at' => $now,
                ]);

                $this->audit->log(
                    AuditAction::PaymentConfirmed,
                    $payment,
                    before: ['status' => PaymentStatus::PendingVerification->value],
                    after: [
                        'status' => PaymentStatus::Confirmed->value,
                        'cash_deposit_id' => $deposit->getKey(),
                        'journal_entry_id' => $entry->getKey(),
                    ],
                    actor: $financeUser,
                );
            }

            $deposit->update([
                'status' => CashDepositStatus::Confirmed,
                'reconciled_by' => $financeUser->getKey(),
                'reconciled_at' => $now,
                'journal_entry_id' => $entry->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CashDepositReconciled,
                $deposit,
                before: ['status' => CashDepositStatus::Pending->value],
                after: [
                    'status' => CashDepositStatus::Confirmed->value,
                    'amount' => $deposit->amount,
                    'payments_confirmed' => $payments->count(),
                    'journal_entry_id' => $entry->getKey(),
                ],
                actor: $financeUser,
            );

            return $deposit->load(['branch', 'bankAccount', 'teller']);
        });
    }
}
