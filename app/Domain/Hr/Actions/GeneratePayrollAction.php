<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Exceptions\PayrollStateException;
use App\Domain\Hr\Services\PayrollCalculator;
use App\Enums\AuditAction;
use App\Models\CommissionDistribution;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StaffAdvance;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffLoan;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\ZoneCommissionDistribution;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST /payroll/generate` — §15.5, HR's step.
 *
 * Produces a **draft** run: payroll lines, allowances and deductions, and
 * nothing else. Not a single journal entry is written here, and that is the
 * whole design of §11 and §14 — HR computes what everyone is owed, Finance
 * decides that it is right and posts it. A draft can be examined, questioned
 * and regenerated; a posted entry cannot.
 *
 * The arithmetic is PayrollCalculator's, which the seeder and the tests also
 * call. There is no second implementation of a payslip anywhere.
 */
final class GeneratePayrollAction
{
    public function __construct(
        private readonly PayrollCalculator $payroll,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(string $period, User $actor): PayrollRun
    {
        /*
         * Checked here as well as by the UNIQUE index on `period`, so the
         * caller gets §11's own wording rather than an integrity-constraint
         * violation. The index is what actually guarantees it.
         */
        if (PayrollRun::query()->where('period', $period)->exists()) {
            throw PayrollStateException::periodAlreadyRun($period);
        }

        $staff = $this->payableStaff();

        if ($staff->isEmpty()) {
            throw PayrollStateException::noActiveStaff();
        }

        $commissionByStaff = $this->commissionForPeriod($period);

        /*
         * Resolved once for the whole run rather than per employee. Allowances
         * and penalties are now rows rather than constants, and a hundred
         * employees would otherwise be two hundred extra queries.
         */
        $entitlements = $this->entitlementsForPeriod($period);
        $penalties = $this->penaltiesForPeriod($period);

        return DB::transaction(function () use (
            $period,
            $actor,
            $staff,
            $commissionByStaff,
            $entitlements,
            $penalties,
        ): PayrollRun {
            $run = PayrollRun::query()->create([
                'period' => $period,
                'status' => PayrollRunStatus::Draft,
                'generated_by' => $actor->getKey(),
            ]);

            foreach ($staff as $member) {
                $key = (int) $member->getKey();

                $this->buildLine(
                    $run,
                    $member,
                    $commissionByStaff[$key] ?? Money::zero(),
                    $entitlements->get($key) ?? collect(),
                    $penalties->get($key) ?? collect(),
                );
            }

            $this->audit->log(
                AuditAction::PayrollGenerated,
                $run,
                after: [
                    'period' => $period,
                    'staff_count' => $staff->count(),
                    // Stated explicitly: a draft run has moved no money, and
                    // the audit trail should say so rather than leave a reader
                    // to infer it from the absence of an entry number.
                    'ledger_posting' => 'none (a draft run posts nothing — Finance finalizes)',
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Payroll draft generated', [
                'period' => $period,
                'run_id' => $run->getKey(),
                'staff_count' => $staff->count(),
                'ledger_posting' => 'none (a draft posts nothing)',
            ]);

            return $run->fresh(['lines.staffProfile', 'lines.allowances', 'lines.deductions']);
        });
    }

    /**
     * Everyone who gets paid this period.
     *
     * Loans and advances are eager-loaded because the calculator asks each
     * profile whether it has any; without this a hundred employees would mean
     * two hundred extra queries.
     *
     * @return Collection<int, StaffProfile>
     */
    private function payableStaff(): Collection
    {
        return StaffProfile::query()
            ->with(['user', 'branch', 'loans', 'advances'])
            ->where('employment_status', EmploymentStatus::Active)
            ->orderBy('id')
            ->get();
    }

    /**
     * Each staff member's commission for the period: their branch pool share
     * plus, for a zone manager, their zone override.
     *
     * Reads what the commission engine already computed rather than computing
     * it again. If no pools exist for the period every entitlement is zero,
     * which is the correct answer — §11 makes commission conditional on a
     * closed, profitable month, and payroll must still run without one.
     *
     * @return array<int, Money>
     */
    private function commissionForPeriod(string $period): array
    {
        $shares = CommissionDistribution::query()
            ->whereHas('pool', fn ($q) => $q->where('period', $period))
            ->get()
            ->groupBy('staff_profile_id')
            ->map(fn (Collection $rows): Money => Money::sum(
                $rows->map(fn (CommissionDistribution $d): Money => $d->shareAmount()),
            ));

        $commission = $shares->all();

        // The zone override belongs to whoever manages the zone, and is added
        // to whatever branch share they may already have.
        foreach (ZoneCommissionDistribution::query()->where('period', $period)->get() as $override) {
            $manager = StaffProfile::query()
                ->whereHas('user', fn ($q) => $q
                    ->where('zone_id', $override->zone_id)
                    ->whereHas('role', fn ($r) => $r->where('name', RoleName::ZoneManager->value)))
                ->first();

            if ($manager === null) {
                continue;
            }

            $key = (int) $manager->getKey();
            $commission[$key] = ($commission[$key] ?? Money::zero())->add($override->overrideAmount());
        }

        return $commission;
    }

    /**
     * One employee's line, its allowances and its deductions.
     *
     * A deduction that recovers a loan or advance carries `reference_id`, so
     * the recovery can be traced back to the specific debt it is paying down
     * rather than appearing as an unexplained subtraction on a payslip.
     *
     * @param Collection<int, StaffAllowance> $entitlements
     * @param Collection<int, StaffDeduction> $penalties
     */
    private function buildLine(
        PayrollRun $run,
        StaffProfile $staff,
        Money $commission,
        Collection $entitlements,
        Collection $penalties,
    ): void {
        $eligibleCommission = $staff->commission_eligible ? $commission : Money::zero();

        /*
         * The debts themselves, not booleans. Each instalment comes from the
         * record's own terms — see StaffLoanCalculator and
         * SalaryAdvanceCalculator — so the calculator needs the record. Passing
         * a flag was exactly what forced the flat figures both replaced.
         */
        $outstandingLoan = $this->outstandingLoanFor($staff);
        $outstandingAdvance = $this->outstandingAdvanceFor($staff);

        $computation = $this->payroll->compute(
            staff: $staff,
            commissionAmount: $eligibleCommission,
            entitlements: $entitlements,
            penalties: $penalties,
            outstandingLoan: $outstandingLoan,
            outstandingAdvance: $outstandingAdvance,
        );

        /** @var PayrollLine $line */
        $line = $run->lines()->create([
            'staff_profile_id' => $staff->getKey(),
        ] + $computation->toLineRow());

        foreach ($computation->allowances as $allowance) {
            $line->allowances()->create([
                'type' => $allowance['type'],
                'amount' => $allowance['amount']->toDecimalString(),
            ]);
        }

        foreach ($computation->deductions as $deduction) {
            $line->deductions()->create([
                'type' => $deduction['type'],
                'amount' => $deduction['amount']->toDecimalString(),
                'reference_id' => $this->referenceFor($staff, $deduction['type']),
            ]);
        }
    }

    /**
     * The loan payroll should recover against this period, if any.
     *
     * The oldest active one, so a member of staff with two runs them down in
     * the order they were taken rather than in whichever order the relation
     * happened to load.
     */
    private function outstandingLoanFor(StaffProfile $staff): ?StaffLoan
    {
        return $staff->loans
            ->filter(fn (StaffLoan $l): bool => $l->status === StaffLoanStatus::Active)
            ->sortBy('id')
            ->first();
    }

    /**
     * Every employee's allowance entitlements for the period, keyed by staff.
     *
     * @return Collection<int|string, Collection<int, StaffAllowance>>
     */
    private function entitlementsForPeriod(string $period): Collection
    {
        return collect(
            StaffAllowance::query()->forPeriod($period)->get()->groupBy('staff_profile_id')->all(),
        )->map(fn ($rows): Collection => collect($rows->all()));
    }

    /**
     * Every penalty recorded against the period, keyed by staff.
     *
     * @return Collection<int|string, Collection<int, StaffDeduction>>
     */
    private function penaltiesForPeriod(string $period): Collection
    {
        return collect(
            StaffDeduction::query()->forPeriod($period)->get()->groupBy('staff_profile_id')->all(),
        )->map(fn ($rows): Collection => collect($rows->all()));
    }

    /**
     * The advance payroll should recover against this period, if any.
     *
     * The oldest disbursed one, so a member of staff with two runs them down in
     * the order they were taken rather than in whichever order the relation
     * happened to load.
     */
    private function outstandingAdvanceFor(StaffProfile $staff): ?StaffAdvance
    {
        return $staff->advances
            ->filter(fn (StaffAdvance $a): bool => $a->status === StaffAdvanceStatus::Disbursed)
            ->sortBy('id')
            ->first();
    }

    /**
     * The debt a recovery deduction is paying down, if any.
     */
    private function referenceFor(StaffProfile $staff, DeductionType $type): ?int
    {
        return match ($type) {
            // The same loan the deduction was computed from, so the reference
            // cannot point at a different one than was recovered.
            DeductionType::Loan => $this->outstandingLoanFor($staff)?->getKey(),

            // The same advance the deduction was computed from, so the
            // reference cannot point at a different one than was recovered.
            DeductionType::Advance => $this->outstandingAdvanceFor($staff)?->getKey(),

            default => null,
        };
    }
}
