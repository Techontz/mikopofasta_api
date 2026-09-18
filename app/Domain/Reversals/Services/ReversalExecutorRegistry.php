<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Services;

use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Executors\AdvancePaymentReversalExecutor;
use App\Domain\Reversals\Executors\DisbursementReversalExecutor;
use App\Domain\Reversals\Executors\LedgerReversalExecutor;
use App\Domain\Reversals\Executors\PaymentReversalExecutor;
use App\Domain\Reversals\Executors\PenaltyReversalExecutor;
use App\Domain\Reversals\Executors\ReversalExecutor;

/**
 * Type → executor, in one place.
 *
 * The constructor lists every executor rather than resolving them by convention, so
 * that adding a fifth reversible transaction is a compile-time decision: a new
 * enum case with no executor is a `match` that will not compile, not a request
 * that sits in the queue and fails when somebody approves it.
 */
final class ReversalExecutorRegistry
{
    public function __construct(
        private readonly PaymentReversalExecutor $payments,
        private readonly DisbursementReversalExecutor $disbursements,
        private readonly PenaltyReversalExecutor $penalties,
        private readonly AdvancePaymentReversalExecutor $advancePayments,
        private readonly LedgerReversalExecutor $ledger,
    ) {}

    public function for(ReversalType $type): ReversalExecutor
    {
        return match ($type) {
            ReversalType::Payment => $this->payments,
            ReversalType::Disbursement => $this->disbursements,
            ReversalType::Penalty => $this->penalties,
            ReversalType::AdvancePayment => $this->advancePayments,
            ReversalType::Ledger => $this->ledger,
        };
    }
}
