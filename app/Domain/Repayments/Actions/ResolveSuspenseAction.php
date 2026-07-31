<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Repayments\Exceptions\PaymentStateException;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\SuspenseItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Channel 3 — Finance identifying money that arrived without a usable
 * reference (`POST /payments/allocate`, §15.3).
 *
 * The cash debit already happened when the money landed, so this posts
 * Dr Suspense · Cr Loan as a SECOND entry (§5) rather than editing the first.
 * Suspense is drawn back down to zero for that receipt, and the original entry
 * remains exactly as it was — which is what makes the pair auditable.
 */
final class ResolveSuspenseAction
{
    public function __construct(
        private readonly RecordRepaymentAction $repayments,
        private readonly AuditLogger $audit,
    ) {}

    public function allocate(SuspenseItem $item, Loan $loan, User $actor): SuspenseItem
    {
        if ($item->isResolved()) {
            throw PaymentStateException::suspenseAlreadyResolved();
        }

        if (! $loan->status->isOpenBook()) {
            throw PaymentStateException::loanNotRepayable($loan->loan_number, $loan->status->value);
        }

        DB::transaction(function () use ($item, $loan, $actor): void {
            $this->repayments->applyToLoan($item->payment, $loan, viaSuspense: true, actor: $actor);

            $item->update([
                'status' => SuspenseStatus::Allocated,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::SuspenseResolved,
                $item,
                after: ['loan_id' => $loan->getKey(), 'loan_number' => $loan->loan_number],
                actor: $actor,
            );
        });

        return $item->fresh(['payment']);
    }

    /**
     * Parks an item as being looked into, without moving any money.
     */
    public function markInvestigating(SuspenseItem $item, User $actor): SuspenseItem
    {
        if ($item->isResolved()) {
            throw PaymentStateException::suspenseAlreadyResolved();
        }

        $item->update(['status' => SuspenseStatus::Investigating]);

        return $item->fresh();
    }
}
