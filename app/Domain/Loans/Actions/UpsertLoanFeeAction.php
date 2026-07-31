<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\DTOs\LoanFeeData;
use App\Enums\AuditAction;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Sets a loan product's fee configuration (PUT /loan-fees/{product}).
 *
 * Upsert rather than create/update: the legacy screen presents one editable row
 * per loan category whether or not a fee has ever been set, so the caller
 * should not have to know which it is.
 */
final class UpsertLoanFeeAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LoanProduct $product, LoanFeeData $data, User $actor): LoanFee
    {
        return DB::transaction(function () use ($product, $data, $actor): LoanFee {
            $fee = LoanFee::query()->withTrashed()->firstOrNew(['loan_product_id' => $product->id]);
            $before = $fee->exists
                ? ['fee_type' => $fee->fee_type->value, 'fee_amount' => $fee->fee_amount, 'insurance_amount' => $fee->insurance_amount]
                : null;

            $fee->fill([
                'loan_product_id' => $product->id,
                'fee_type' => $data->feeType,
                'fee_amount' => $data->feeAmount,
                'insurance_amount' => $data->insuranceAmount,
                'created_by' => $fee->created_by ?? $actor->id,
            ]);

            /*
             * Clearing the column revives a previously removed configuration.
             * SoftDeletes::restore() cannot be used here: it sets exists=true
             * before the row has a key, so on a brand-new record the save
             * becomes an UPDATE with nothing to match on. Setting the column
             * directly covers both the new and the revived case.
             *
             * Reviving rather than inserting matters because loan_product_id is
             * unique — a second row would be rejected — and it keeps the whole
             * pricing history of a product on one record.
             */
            $fee->deleted_at = null;
            $fee->save();

            $this->audit->log(
                AuditAction::LoanFeeConfigured,
                $fee,
                before: $before,
                after: [
                    'fee_type' => $fee->fee_type->value,
                    'fee_amount' => $fee->fee_amount,
                    'insurance_amount' => $fee->insurance_amount,
                ],
                actor: $actor,
            );

            return $fee->load('product');
        });
    }
}
