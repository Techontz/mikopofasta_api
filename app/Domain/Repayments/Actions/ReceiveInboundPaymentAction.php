<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Exceptions\DuplicateTransactionException;
use App\Domain\Repayments\Services\PaymentReferenceGenerator;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Channel 1 — the provider callback (`POST /webhooks/payments`, §15.3).
 *
 * §7's reference matching: the payload's `reference` is looked up against
 * `loans.loan_number`; a miss becomes an unmatched payment plus a suspense
 * item rather than being dropped.
 *
 * §15.3 documents the response shapes precisely, and they are worth honouring
 * exactly: an unmatched payment still returns **200**, because the payment was
 * successfully received and ledgered — it is unmatched, not failed. Only a
 * duplicate transaction is an error (409).
 */
final class ReceiveInboundPaymentAction
{
    public function __construct(
        private readonly RecordRepaymentAction $repayments,
        private readonly PaymentReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{payment: Payment, matched: bool}
     */
    public function handle(
        string $reference,
        Money $amount,
        PaymentChannel $channel,
        string $transactionId,
        ?string $phone,
        User $actor,
    ): array {
        /*
         * §7's duplicate detection, belt and braces: this check plus the
         * UNIQUE index on transaction_id, plus the Idempotency-Key on the
         * webhook route. Providers retry callbacks routinely.
         */
        Log::channel('operations')->info('Payment webhook received', [
            'reference' => $reference,
            'transaction_id' => $transactionId,
            'channel' => $channel->value,
            'amount' => $amount->toDecimalString(),
        ]);

        if (Payment::query()->where('transaction_id', $transactionId)->exists()) {
            Log::channel('operations')->warning('Payment webhook rejected: duplicate transaction', [
                'transaction_id' => $transactionId,
            ]);

            throw new DuplicateTransactionException($transactionId);
        }

        $loan = Loan::query()
            ->with(['schedules', 'branch'])
            ->where('loan_number', $reference)
            ->first();

        if ($loan === null || ! $loan->status->isOpenBook()) {
            $payment = $this->repayments->recordUnmatched(
                amount: $amount,
                channel: $channel,
                transactionId: $transactionId,
                reason: $loan === null
                    ? sprintf('No loan matches reference "%s".', $reference)
                    : sprintf('Loan %s is %s and cannot take a repayment.', $reference, $loan->status->value),
                actor: $actor,
            );

            Log::channel('operations')->warning('Payment could not be matched to a loan', [
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'payment_reference' => $payment->payment_reference,
                'amount' => $amount->toDecimalString(),
            ]);

            return ['payment' => $payment, 'matched' => false];
        }

        $payment = DB::transaction(function () use ($amount, $channel, $transactionId, $loan, $actor): Payment {
            $payment = Payment::query()->create([
                'payment_reference' => $this->references->next(),
                'loan_id' => $loan->getKey(),
                'customer_id' => $loan->customer_id,
                'amount' => $amount->toDecimalString(),
                'channel' => $channel,
                'transaction_id' => $transactionId,
                'status' => PaymentStatus::Received,
                'branch_id' => $loan->branch_id,
                'received_at' => Date::now(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(AuditAction::PaymentReceived, $payment, after: [
                'loan_number' => $loan->loan_number,
                'amount' => $amount->toDecimalString(),
            ], actor: $actor);

            return $payment;
        });

        $this->repayments->applyToLoan($payment, $loan, viaSuspense: false, actor: $actor);

        Log::channel('operations')->info('Payment matched and allocated', [
            'loan_number' => $loan->loan_number,
            'payment_reference' => $payment->payment_reference,
            'transaction_id' => $transactionId,
            'amount' => $amount->toDecimalString(),
        ]);

        return ['payment' => $payment->fresh(['allocations']), 'matched' => true];
    }
}
