<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Enums\AuditAction;
use App\Models\LoanFee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Clears a loan product's fee configuration (DELETE /loan-fees/{product}).
 * Soft delete: what was charged historically stays readable.
 */
final class DeleteLoanFeeAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LoanFee $fee, User $actor): void
    {
        DB::transaction(function () use ($fee, $actor): void {
            $this->audit->log(
                AuditAction::LoanFeeCleared,
                $fee,
                before: [
                    'fee_type' => $fee->fee_type->value,
                    'fee_amount' => $fee->fee_amount,
                    'insurance_amount' => $fee->insurance_amount,
                ],
                actor: $actor,
            );

            $fee->delete();
        });
    }
}
