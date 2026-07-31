<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Enums\ChargeValueType;
use App\Models\Loan;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Support\Money;
use App\Support\Percentage;

/**
 * Works out what a borrower is charged on top of interest — Settings → Loan Fee.
 *
 * The single implementation, in the same spirit as PenaltyCalculator: the
 * application (which snapshots the terms) and the disbursement (which charges
 * them) both come here rather than each deriving the arithmetic.
 *
 * On the dual meaning of `fee_amount`: it is a percentage of the principal when
 * `fee_type` is `percentage_value`, and a flat TZS amount when it is
 * `money_value` — the same two-readings-of-one-column shape `penalty_rate` has,
 * and separated here for the same reason, so no caller has to remember which
 * it is holding.
 *
 * Insurance is always a flat premium and has no type of its own.
 */
final class LoanFeeCalculator
{
    /**
     * The fee configuration to snapshot onto a new loan, or null when the
     * product has none.
     *
     * @return array{
     *     fee_type_snapshot: string,
     *     fee_amount_snapshot: string,
     *     insurance_amount_snapshot: string
     * }|null
     */
    public function snapshotFor(LoanProduct $product): ?array
    {
        $fee = $product->relationLoaded('fee')
            ? $product->fee
            : LoanFee::query()->where('loan_product_id', $product->getKey())->first();

        if ($fee === null) {
            return null;
        }

        return [
            'fee_type_snapshot' => $fee->fee_type->value,
            'fee_amount_snapshot' => $fee->fee_amount,
            'insurance_amount_snapshot' => $fee->insurance_amount,
        ];
    }

    /**
     * The arrangement fee in shillings, from a loan's own snapshot.
     *
     * Read from the loan, never from the product: a loan agreed at 5% is
     * charged 5% even if Settings has since said otherwise. That is the whole
     * point of snapshotting.
     */
    public function arrangementFee(Loan $loan): Money
    {
        if ($loan->fee_type_snapshot === null || $loan->fee_amount_snapshot === null) {
            return Money::zero();
        }

        return $loan->fee_type_snapshot === ChargeValueType::MoneyValue
            ? Money::of((string) $loan->fee_amount_snapshot)
            : $loan->principal()->percentage(Percentage::of((string) $loan->fee_amount_snapshot));
    }

    public function insurancePremium(Loan $loan): Money
    {
        return $loan->insurance_amount_snapshot === null
            ? Money::zero()
            : Money::of((string) $loan->insurance_amount_snapshot);
    }

    /**
     * Everything withheld from the disbursement — what the Deducted Income
     * screen calls the income amount.
     *
     * Fee and premium together, because that is what the borrower actually has
     * kept back. They are stored separately on the loan so the split survives;
     * see docs/modules/penalties-and-fees.md on why both currently credit the
     * same account.
     */
    public function totalDeducted(Loan $loan): Money
    {
        return $this->arrangementFee($loan)->add($this->insurancePremium($loan));
    }

    /**
     * What actually reaches the borrower.
     *
     * The borrower owes the full principal either way — the fee is deducted
     * from the payout, not from the debt — so this figure never appears in the
     * loan's balance. It is what the disbursement instruction is for.
     */
    public function netDisbursement(Loan $loan): Money
    {
        return $loan->principal()->subtract($this->totalDeducted($loan));
    }
}
