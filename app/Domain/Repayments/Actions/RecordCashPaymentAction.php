<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Channel 2 — a teller taking cash over the counter (`POST /payments/cash`,
 * §15.3).
 *
 * The money is allocated and ledgered immediately (Dr Teller Cash), but the
 * payment lands on `pending_verification` rather than `allocated`: §7 is
 * explicit that teller cash-in-hand and bank-confirmed cash are two different
 * trust states, and the second only arrives when a deposit slip is reconciled.
 */
final class RecordCashPaymentAction
{
    public function __construct(
        private readonly RecordRepaymentAction $repayments,
        private readonly PaymentReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Loan $loan, Money $amount, User $teller): Payment
    {
        $payment = DB::transaction(function () use ($loan, $amount, $teller): Payment {
            $payment = Payment::query()->create([
                'payment_reference' => $this->references->next(),
                'loan_id' => $loan->getKey(),
                'customer_id' => $loan->customer_id,
                'amount' => $amount->toDecimalString(),
                'channel' => PaymentChannel::Cash,
                'status' => PaymentStatus::Received,
                'branch_id' => $loan->branch_id,
                'teller_id' => $teller->getKey(),
                'received_at' => Date::now(),
                'created_by' => $teller->getKey(),
            ]);

            $this->audit->log(AuditAction::PaymentReceived, $payment, after: [
                'channel' => 'cash',
                'loan_number' => $loan->loan_number,
                'amount' => $amount->toDecimalString(),
            ], actor: $teller);

            return $payment;
        });

        $this->repayments->applyToLoan($payment, $loan, viaSuspense: false, actor: $teller);

        return $payment->fresh(['allocations']);
    }
}
