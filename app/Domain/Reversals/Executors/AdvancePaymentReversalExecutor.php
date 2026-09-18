<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Enums\AuditAction;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvancePayment;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Undoes a collection against a customer salary advance.
 *
 * CollectCustomerAdvanceAction does three things, and this undoes all three:
 *
 *   1. the journal entry        mirrored — the cash leaves the account it
 *                               landed in, 1250 is owed again, and the
 *                               interest and fee income come back off
 *   2. the advance's totals     each repaid column drops by exactly the portion
 *                               this payment recorded, so the next collection's
 *                               cumulative split resumes from the right place
 *   3. the advance's status     a payment that settled it reopens it
 *
 * ## Why the recorded portions, not a re-split
 *
 * The three portions on the payment row are the three credits its entry
 * posted. Subtracting those — rather than re-running the calculator — is what
 * makes the reversal cancel the collection to the cent: the mirrored entry and
 * the advance's columns move by the same figures, so the register and the
 * ledger cannot drift apart.
 *
 * The row itself is kept and stamped `reversed_at`. It is the transaction
 * history the client asked for; every total skips it.
 */
final class AdvancePaymentReversalExecutor implements ReversalExecutor
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function type(): ReversalType
    {
        return ReversalType::AdvancePayment;
    }

    public function guard(ReversalRequest $request): void
    {
        $payment = $this->payment($request);

        if ($payment->isReversed()) {
            throw ReversalRefusedException::advancePaymentAlreadyReversed($payment->reference);
        }

        $advance = $payment->advance;

        if (! in_array($advance->status, [CustomerAdvanceStatus::Disbursed, CustomerAdvanceStatus::Settled], true)) {
            throw ReversalRefusedException::advanceNotReversible($advance->reference, $advance->status->value);
        }
    }

    public function amount(ReversalRequest $request): Money
    {
        return $this->payment($request)->amountMoney();
    }

    public function describe(ReversalRequest $request): string
    {
        $payment = $this->payment($request);

        return sprintf('Salary advance payment %s (%s)', $payment->reference, $payment->advance->reference);
    }

    public function execute(ReversalRequest $request, User $approver): ?JournalEntry
    {
        // Lock both before the write: a teller collecting against the same
        // advance while this runs would otherwise split against stale totals.
        $payment = CustomerAdvancePayment::query()->lockForUpdate()->findOrFail($request->customer_advance_payment_id);
        $advance = CustomerAdvance::query()->lockForUpdate()->findOrFail($payment->customer_advance_id);

        $payment->setRelation('advance', $advance);
        $request->setRelation('advancePayment', $payment);

        $this->guard($request);

        $entry = $payment->journal_entry_id === null
            ? null
            : $this->ledger->reverse(
                JournalEntry::query()->findOrFail($payment->journal_entry_id),
                $request->reason,
                $approver,
            );

        $wasSettled = $advance->status === CustomerAdvanceStatus::Settled;

        $advance->update([
            'amount_repaid' => $advance->repaidMoney()->subtract($payment->amountMoney())->toDecimalString(),
            'principal_repaid' => $advance->principalRepaidMoney()
                ->subtract(Money::of($payment->principal_portion))->toDecimalString(),
            'interest_repaid' => $advance->interestRepaidMoney()
                ->subtract(Money::of($payment->interest_portion))->toDecimalString(),
            'fee_repaid' => $advance->feeRepaidMoney()
                ->subtract(Money::of($payment->fee_portion))->toDecimalString(),
            /*
             * Money came back off it, so a settled advance owes again. Reopened
             * as `disbursed`, the only state a collection is accepted in.
             */
            'status' => CustomerAdvanceStatus::Disbursed,
            'settled_at' => null,
        ]);

        $payment->update([
            'reversed_at' => Date::now(),
            'reversal_entry_id' => $entry?->getKey(),
        ]);

        $this->audit->log(
            AuditAction::CustomerAdvancePaymentReversed,
            $advance,
            after: [
                'reversal_request' => $request->getKey(),
                'payment_reference' => $payment->reference,
                'amount' => $payment->amount,
                'principal' => $payment->principal_portion,
                'interest' => $payment->interest_portion,
                'fee' => $payment->fee_portion,
                'reason' => $request->reason,
                'journal_entry' => $entry?->entry_number,
                'reopened' => $wasSettled,
            ],
            actor: $approver,
        );

        Log::channel('operations')->warning('Salary advance payment reversed', [
            'advance' => $advance->reference,
            'payment' => $payment->reference,
            'amount' => $payment->amount,
            'approved_by' => $approver->getKey(),
            'journal_entry' => $entry?->entry_number,
        ]);

        return $entry;
    }

    private function payment(ReversalRequest $request): CustomerAdvancePayment
    {
        $payment = $request->advancePayment;

        if ($payment === null) {
            throw ReversalRefusedException::subjectMissing('salary advance payment');
        }

        return $payment->loadMissing('advance');
    }
}
