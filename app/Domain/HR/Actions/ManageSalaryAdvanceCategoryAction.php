<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\DTOs\SalaryAdvanceCategoryData;
use App\Domain\Hr\Exceptions\SalaryAdvanceCategoryException;
use App\Enums\AuditAction;
use App\Models\SalaryAdvanceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The salary advance bands — Salary Advance → Salary Advance Category.
 *
 * Create, rename, re-price and retire. One class for all four because they are
 * one record's life, the same shape ManageLoanProductAction takes.
 *
 * ## The overlap rule
 *
 * A band decides what an advance costs, and an advance finds its band by
 * amount. So two bands covering the same amount would price the same request
 * two different ways depending on which was matched — and the choice would come
 * down to row order, which no one intends as a pricing policy.
 *
 * Overlaps are therefore refused at creation and on edit. That is stricter than
 * the frontend's own validation, which only checks that a band's ceiling is
 * above its floor; a band can be individually valid and still collide with its
 * neighbour, and only the server can see the neighbours.
 */
final class ManageSalaryAdvanceCategoryAction
{
    public function __construct(private readonly \App\Services\AuditLogger $audit) {}

    public function create(SalaryAdvanceCategoryData $data, User $actor): SalaryAdvanceCategory
    {
        $this->guardName($data->name, null);
        $this->guardNoOverlap($data, null);

        return DB::transaction(function () use ($data, $actor): SalaryAdvanceCategory {
            $category = SalaryAdvanceCategory::query()->create([
                'name' => $data->name,
                'interest_rate' => $data->interestRate,
                'from_amount' => $data->fromAmount,
                'to_amount' => $data->toAmount,
                'charge_fee' => $data->chargeFee,
                'recovery_periods' => $data->recoveryPeriods,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::SalaryAdvanceCategoryCreated,
                $category,
                after: $this->snapshot($category),
                actor: $actor,
            );

            return $category;
        });
    }

    public function update(
        SalaryAdvanceCategory $category,
        SalaryAdvanceCategoryData $data,
        User $actor,
    ): SalaryAdvanceCategory {
        $this->guardName($data->name, $category);
        $this->guardNoOverlap($data, $category);

        return DB::transaction(function () use ($category, $data, $actor): SalaryAdvanceCategory {
            $before = $this->snapshot($category);

            $category->update([
                'name' => $data->name,
                'interest_rate' => $data->interestRate,
                'from_amount' => $data->fromAmount,
                'to_amount' => $data->toAmount,
                'charge_fee' => $data->chargeFee,
                'recovery_periods' => $data->recoveryPeriods,
            ]);

            /*
             * No advance is re-priced. Every advance snapshots its terms at
             * request, so this changes what future requests cost and nothing
             * that has already been agreed.
             */
            $this->audit->log(
                AuditAction::SalaryAdvanceCategoryUpdated,
                $category,
                before: $before,
                after: $this->snapshot($category),
                actor: $actor,
            );

            return $category->fresh();
        });
    }

    /**
     * Retires a band.
     *
     * Soft-deleted, because advances already priced by it keep pointing at it
     * and the Active screen has to be able to name the category an advance was
     * agreed under. Refused while an advance under it is still in flight: that
     * advance's terms are settled, but a band vanishing mid-recovery makes the
     * screens unable to explain where its interest came from.
     */
    public function delete(SalaryAdvanceCategory $category, User $actor): void
    {
        $inFlight = $category->advances()
            ->whereIn('status', ['requested', 'approved', 'disbursed'])
            ->exists();

        if ($inFlight) {
            throw SalaryAdvanceCategoryException::inUse($category->name);
        }

        DB::transaction(function () use ($category, $actor): void {
            $this->audit->log(
                AuditAction::SalaryAdvanceCategoryDeleted,
                $category,
                before: $this->snapshot($category),
                actor: $actor,
            );

            $category->delete();
        });
    }

    private function guardName(string $name, ?SalaryAdvanceCategory $except): void
    {
        $exists = SalaryAdvanceCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw SalaryAdvanceCategoryException::duplicateName($name);
        }
    }

    /**
     * Refuses a band that overlaps another.
     *
     * Two bands overlap when each starts before the other ends — the standard
     * interval test, written out rather than as four comparisons so the
     * boundary cases (a band ending exactly where the next begins) are visibly
     * *not* an overlap.
     */
    private function guardNoOverlap(SalaryAdvanceCategoryData $data, ?SalaryAdvanceCategory $except): void
    {
        $clash = SalaryAdvanceCategory::query()
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->where('from_amount', '<=', $data->toAmount)
            ->where('to_amount', '>=', $data->fromAmount)
            ->first();

        if ($clash !== null) {
            throw SalaryAdvanceCategoryException::overlaps(
                $clash->name,
                $clash->from_amount,
                $clash->to_amount,
            );
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SalaryAdvanceCategory $category): array
    {
        return $category->only([
            'name', 'interest_rate', 'from_amount', 'to_amount', 'charge_fee', 'recovery_periods',
        ]);
    }
}
