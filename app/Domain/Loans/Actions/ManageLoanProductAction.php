<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Exceptions\LoanProductInUseException;
use App\Enums\AuditAction;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Loan product configuration (§2.3, §6).
 *
 * §6: "A Super Admin/Admin changing a product's terms takes effect
 * immediately for new applications, with zero code deploy." Existing loans are
 * untouched, because their terms were snapshotted at application time — which
 * is exactly why the snapshot columns exist.
 *
 * §15's standard CRUD pattern blocks an update that would change
 * `interest_formula_id` or `requires_mandate` while the product has active
 * loans: those two decide the SHAPE of a schedule and the workflow route, and
 * changing them under a live book invites a mismatch between what a loan's
 * schedule looks like and what its product now claims.
 */
final class ManageLoanProductAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): LoanProduct
    {
        return DB::transaction(function () use ($data, $actor): LoanProduct {
            $product = LoanProduct::query()->create($this->attributes($data) + [
                'created_by' => $actor->getKey(),
            ]);

            $product->repaymentSchedules()->sync($data['repaymentScheduleIds'] ?? []);

            /*
             * Refreshed before snapshotting: `status` is optional on input, so
             * the database default supplies it, and the in-memory model would
             * otherwise carry a null the snapshot cannot read.
             */
            $product->refresh();

            $this->audit->log(
                AuditAction::LoanProductCreated,
                $product,
                after: $this->snapshot($product),
                actor: $actor,
            );

            return $product->fresh(['interestFormula', 'repaymentSchedules']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(LoanProduct $product, array $data, User $actor): LoanProduct
    {
        $activeLoans = $product->loans()->whereNotIn('status', ['closed', 'rejected', 'cancelled', 'written_off'])->count();

        if ($activeLoans > 0) {
            $changesFormula = isset($data['interestFormulaId'])
                && (int) $data['interestFormulaId'] !== $product->interest_formula_id;

            $changesMandate = isset($data['requiresMandate'])
                && (bool) $data['requiresMandate'] !== $product->requires_mandate;

            if ($changesFormula || $changesMandate) {
                throw LoanProductInUseException::structuralChangeBlocked($activeLoans);
            }
        }

        return DB::transaction(function () use ($product, $data, $actor): LoanProduct {
            $before = $this->snapshot($product);

            $product->update($this->attributes($data));

            if (array_key_exists('repaymentScheduleIds', $data)) {
                $product->repaymentSchedules()->sync($data['repaymentScheduleIds']);
            }

            $this->audit->log(
                AuditAction::LoanProductUpdated,
                $product,
                before: $before,
                after: $this->snapshot($product->refresh()),
                actor: $actor,
            );

            return $product->fresh(['interestFormula', 'repaymentSchedules']);
        });
    }

    public function delete(LoanProduct $product, User $actor): void
    {
        // §15: blocked if referenced by any non-closed loan.
        $openLoans = $product->loans()
            ->whereNotIn('status', ['closed', 'rejected', 'cancelled', 'written_off'])
            ->count();

        if ($openLoans > 0) {
            throw LoanProductInUseException::hasOpenLoans($openLoans);
        }

        DB::transaction(function () use ($product, $actor): void {
            $this->audit->log(
                AuditAction::LoanProductDeleted,
                $product,
                before: $this->snapshot($product),
                actor: $actor,
            );

            $product->delete();
        });
    }

    /**
     * Maps the camelCase request payload onto real column names.
     *
     * The return type names model properties rather than plain strings: every
     * value in $map is a genuine column, so the array really is keyed by model
     * property, and saying so lets Larastan verify the create/update calls
     * instead of taking them on trust.
     *
     * @param array<string, mixed> $data
     * @return array<model-property<LoanProduct>, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        $map = [
            'name' => 'name',
            'code' => 'code',
            'interestFormulaId' => 'interest_formula_id',
            'interestRate' => 'interest_rate',
            'interestRateBasisId' => 'interest_rate_basis_id',
            'minAmount' => 'min_amount',
            'maxAmount' => 'max_amount',
            'minTenureDays' => 'min_tenure_days',
            'maxTenureDays' => 'max_tenure_days',
            'penaltyType' => 'penalty_type',
            'penaltyRate' => 'penalty_rate',
            'penaltyGraceDays' => 'penalty_grace_days',
            'penaltyCapAmount' => 'penalty_cap_amount',
            'requiresMandate' => 'requires_mandate',
            'status' => 'status',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(LoanProduct $product): array
    {
        return [
            'name' => $product->name,
            'code' => $product->code,
            'interest_formula_id' => $product->interest_formula_id,
            'interest_rate' => $product->interest_rate,
            'min_amount' => $product->min_amount,
            'max_amount' => $product->max_amount,
            'min_tenure_days' => $product->min_tenure_days,
            'max_tenure_days' => $product->max_tenure_days,
            'penalty_type' => $product->penalty_type->value,
            'penalty_rate' => $product->penalty_rate,
            'requires_mandate' => $product->requires_mandate,
            'status' => $product->status->value,
        ];
    }
}
