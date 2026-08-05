<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Services\BranchProfitCalculator;
use App\Domain\Hr\Services\CommissionCalculator;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\CommissionPool;
use App\Models\PeriodBranchResult;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneCommissionDistribution;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes every branch's commission pool for a period, and who shares it.
 *
 * §11's monthly cycle: month-end close → commission pools per branch → per
 * staff distributions → zone overrides → payroll. This action is the middle
 * three steps, and payroll then reads what it produced.
 *
 * **Nothing is posted to the ledger here, deliberately.** A pool is an
 * entitlement, not a transaction: no money moves when a branch earns the right
 * to share out its profit. The money is recognised once, as Commission Expense
 * on the recipient's payroll entry (§5). Posting a pool-level entry as well
 * would expense the same shillings twice. The frontend reached the same
 * conclusion independently while it still modelled commission itself; it no
 * longer does, and now reads the pools this action writes (features/hr, via
 * CommissionReport).
 *
 * Re-running for a period replaces that period's pools rather than adding to
 * them, so a correction after a late expense is entered is a re-run — but only
 * while the period's payroll is still a draft, because once payroll has
 * recognised a commission the figure is in the books.
 */
final class GenerateCommissionPoolsAction
{
    public function __construct(
        private readonly CommissionCalculator $commission,
        private readonly BranchProfitCalculator $profits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return EloquentCollection<int, CommissionPool>
     */
    public function handle(string $period, User $actor): EloquentCollection
    {
        $branches = Branch::query()->orderBy('id')->get();

        return DB::transaction(function () use ($period, $branches, $actor): EloquentCollection {
            $pools = new EloquentCollection;

            foreach ($branches as $branch) {
                $pools->push($this->poolFor($branch, $period));
            }

            $this->distributeZoneOverrides($period, $pools);

            $blocked = $pools->reject(fn (CommissionPool $p): bool => $p->isDistributable());

            $this->audit->log(
                AuditAction::CommissionPoolsGenerated,
                $branches->first() ?? $actor,
                after: [
                    'period' => $period,
                    'branches' => $pools->count(),
                    'blocked_by_loss' => $blocked->count(),
                    'total_pool' => Money::sum($pools->map(fn (CommissionPool $p): Money => $p->poolAmount()))
                        ->toDecimalString(),
                    'ledger_posting' => 'none (commission is expensed on the payroll entry — §5)',
                ],
                actor: $actor,
            );

            /*
             * Re-read rather than returning what was just built: the caller
             * renders these with their branch and their distributions, and
             * loading them here means the resource never lazy-loads a relation
             * per row.
             */
            return CommissionPool::query()
                ->with(['branch', 'distributions.staffProfile.user'])
                ->where('period', $period)
                ->orderBy('branch_id')
                ->get();
        });
    }

    /**
     * One branch's pool, and its distributions when it has earned any.
     */
    private function poolFor(Branch $branch, string $period): CommissionPool
    {
        $computation = $this->commission->computePool(
            branchProfit: $this->profits->forPeriod($branch, $period),
            lossCarryForward: $this->lossCarriedInto($branch, $period),

            /*
             * Decision Register D1. Read from the close rather than recomputed,
             * so a pool always reconciles to the period it was derived from —
             * and returns zero for a period nobody has closed, which is the
             * honest answer: no reserve has been appropriated yet.
             */
            reserveAppropriation: PeriodBranchResult::reserveFor((int) $branch->getKey(), $period),
        );

        /** @var CommissionPool $pool */
        $pool = CommissionPool::query()->updateOrCreate(
            ['branch_id' => $branch->getKey(), 'period' => $period],
            $computation->toPoolRow(),
        );

        // Re-running must not leave last run's shares behind alongside this
        // run's.
        $pool->distributions()->delete();

        if (! $computation->distributable) {
            // §11's hard rule. A loss-making branch produces a pool row — the
            // loss itself is information, and the next period needs it — but
            // not one shilling of distribution.
            return $pool->fresh(['distributions']);
        }

        foreach ($this->commission->distributePool($computation->poolAmount, $this->eligibleStaff($branch)) as $share) {
            $pool->distributions()->create([
                'staff_profile_id' => $share['staffProfileId'],
                'share_amount' => $share['shareAmount']->toDecimalString(),
            ]);
        }

        return $pool->fresh(['distributions']);
    }

    /**
     * The unrecovered loss a branch brings into this period.
     *
     * OSC-5: §2.9 stores `loss_carry_forward` and §11 requires that a loss be
     * "offset first", but neither says where the figure comes from. The
     * reading implemented is the literal one — a period whose distributable
     * profit went negative carries that shortfall into the next period, and
     * carries nothing forward once it has been cleared.
     *
     * @see README.md → "Open Specification Conflicts"
     */
    private function lossCarriedInto(Branch $branch, string $period): Money
    {
        $previous = CommissionPool::query()
            ->where('branch_id', $branch->getKey())
            ->where('period', $this->profits->previousPeriod($period))
            ->first();

        return $previous?->shortfall() ?? Money::zero();
    }

    /**
     * Who shares a branch's pool: its active, commission-eligible staff.
     *
     * `commission_eligible` is a per-employee flag (§2.9), which is how §11
     * keeps HQ roles out of branch pools without hardcoding a role list.
     *
     * @return Collection<int, StaffProfile>
     */
    private function eligibleStaff(Branch $branch): Collection
    {
        return StaffProfile::query()
            ->where('branch_id', $branch->getKey())
            ->where('commission_eligible', true)
            ->where('employment_status', EmploymentStatus::Active)
            ->orderBy('id')
            ->get();
    }

    /**
     * A zone manager's override on the branches they oversee (§11).
     *
     * The base is the combined pools of the zone's branches, so a zone whose
     * branches earned nothing produces no override — the manager's reward
     * tracks the branches' performance, which is the point.
     *
     * @param EloquentCollection<int, CommissionPool> $pools
     */
    private function distributeZoneOverrides(string $period, EloquentCollection $pools): void
    {
        $poolsByBranch = $pools->keyBy('branch_id');

        foreach (Zone::query()->with('branches')->get() as $zone) {
            $base = Money::sum(
                $zone->branches
                    ->map(fn (Branch $b): ?CommissionPool => $poolsByBranch->get($b->getKey()))
                    ->filter()
                    ->map(fn (CommissionPool $p): Money => $p->poolAmount()),
            );

            $override = $this->commission->zoneOverride($base);

            if (! $override->isPositive() || ! $this->zoneHasManager($zone)) {
                // Nothing earned, or nobody to earn it. Either way there is no
                // override to record.
                ZoneCommissionDistribution::query()
                    ->where('zone_id', $zone->getKey())
                    ->where('period', $period)
                    ->whereNull('journal_entry_id')
                    ->delete();

                continue;
            }

            ZoneCommissionDistribution::query()->updateOrCreate(
                ['zone_id' => $zone->getKey(), 'period' => $period],
                [
                    'total_pool_base' => $base->toDecimalString(),
                    'override_percentage' => $this->commission->zoneOverridePercentage()->toDecimalString(),
                    'override_amount' => $override->toDecimalString(),
                ],
            );
        }
    }

    private function zoneHasManager(Zone $zone): bool
    {
        return User::query()
            ->where('zone_id', $zone->getKey())
            ->whereHas('role', fn ($q) => $q->where('name', RoleName::ZoneManager->value))
            ->exists();
    }
}
