<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Actions\ManageStaffAllowanceAction;
use App\Domain\Hr\Actions\StaffLoanAction;
use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\PerformanceRating;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use App\Domain\Hr\Services\EmployeeNumberGenerator;
use App\Domain\Hr\Services\PayrollCalculator;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Domain\Hr\Services\StaffAdvanceReferenceGenerator;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Models\SalaryAdvanceCategory;
use App\Models\StaffAdvance;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffPerformanceRecord;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * One staff profile per demo user — every system user is company staff.
 *
 * Salaries follow the frontend's bands so a payroll generated here produces
 * the same figures the frontend's screens were built against. The staff loan
 * and the advance are seeded through the real posting builder and
 * LedgerService, not by writing journal rows: a seeded advance that did not
 * balance would be a bug the seed itself hid.
 */
final class StaffSeeder extends Seeder
{
    /**
     * The frontend's salary bands (lib/mock-data/staff-profiles.ts).
     */
    private const string HQ_SALARY = '1800000.00';

    private const string BRANCH_MANAGER_SALARY = '1400000.00';

    private const string OVERSIGHT_SALARY = '1600000.00';

    private const string CREDIT_OFFICER_SALARY = '1000000.00';

    private const string FIELD_SALARY = '800000.00';

    /**
     * Who earns commission — a branch-performance reward, so the roles that
     * run a branch (§11).
     *
     * @var list<RoleName>
     */
    private const array COMMISSION_ELIGIBLE = [
        RoleName::BranchManager,
        RoleName::LoanOfficer,
        RoleName::CreditOfficer,
        RoleName::Teller,
        RoleName::ZoneManager,
        RoleName::RegionalManager,
    ];

    public function run(): void
    {
        $users = User::query()->with('role')->orderBy('id')->get();

        if ($users->isEmpty()) {
            return;
        }

        $numbers = app(EmployeeNumberGenerator::class);

        foreach ($users as $index => $user) {
            $role = $user->roleName();

            $profile = StaffProfile::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'employee_number' => $numbers->next(),
                    'branch_id' => $user->branch_id,
                    'zone_id' => $user->zone_id,
                    'base_salary' => $this->salaryFor($role),
                    'commission_eligible' => in_array($role, self::COMMISSION_ELIGIBLE, true),
                    'payment_method' => StaffPaymentMethod::Bank,
                    'employment_status' => EmploymentStatus::Active,

                    // Spread the hire dates so the staff table has a history
                    // rather than everyone starting on the same day.
                    'hired_at' => Date::now()->subDays(400 + $index * 30)->toDateString(),
                ],
            );

            $profile->bankDetail()->updateOrCreate([], [
                'bank_name' => $index % 2 === 0 ? 'NMB Bank' : 'CRDB Bank',
                'account_number' => (string) (30_000_000 + $index * 111),
            ]);

            $this->enrolAllowances($profile, $role, $user);
        }

        $this->seedBonus();
        $this->seedPenalty();

        $this->seedStaffLoan();
        $this->seedStaffAdvance();
        $this->seedPerformanceRecords();
    }

    /**
     * The standard allowances, as rows rather than as constants in the
     * calculator.
     *
     * Transport for branch-based staff, airtime for everyone — the same split
     * PayrollCalculator used to apply from two class constants. What changes is
     * that the decision is now recorded once per employee and HR can alter it
     * for one person; before Module 7 every branch employee necessarily drew
     * the identical figure and no bonus could exist at all.
     */
    private function enrolAllowances(StaffProfile $profile, ?RoleName $role, User $user): void
    {
        if ($profile->allowanceEntitlements()->exists()) {
            return;
        }

        $payroll = app(PayrollCalculator::class);
        $hr = User::query()->where('phone', '0754000007')->first() ?? $user;

        app(ManageStaffAllowanceAction::class)->enrol(
            $profile,
            $payroll->defaultEntitlements($payroll->isBranchBased($role, $profile->branch_id)),
            $hr,
        );
    }

    /**
     * One bonus, so the Bonus type is visible on a screen rather than only in
     * an enum.
     *
     * Stamped with the current period because a bonus is always one-off — a
     * recurring one is a salary increase, and belongs on the profile.
     */
    private function seedBonus(): void
    {
        // Frank Urio, Credit Officer. Deliberately NOT one of the staff the
        // payroll tests pin exact figures against — a demo bonus that changed
        // somebody's net pay would look like a broken assertion rather than a
        // seeded reward.
        $staff = $this->staffFor('0754000006');
        $hr = User::query()->where('phone', '0754000007')->first();

        if ($staff === null || $hr === null) {
            return;
        }

        StaffAllowance::query()->firstOrCreate(
            [
                'staff_profile_id' => $staff->getKey(),
                'type' => AllowanceType::Bonus,
                'period' => Date::now()->format('Y-m'),
            ],
            [
                'amount' => '100000.00',
                'reason' => 'Best collection rate, quarter to date',
                'active' => true,
                'created_by' => $hr->getKey(),
            ],
        );
    }

    /**
     * One penalty, for the same reason: DeductionType::Penalty was mapped to an
     * account and rendered by the frontend, and nothing could ever create one.
     */
    private function seedPenalty(): void
    {
        // Daniel Kessy, Branch Manager — chosen for the same reason as the
        // bonus above.
        $staff = $this->staffFor('0754000004');
        $hr = User::query()->where('phone', '0754000007')->first();

        if ($staff === null || $hr === null) {
            return;
        }

        StaffDeduction::query()->firstOrCreate(
            [
                'staff_profile_id' => $staff->getKey(),
                'period' => Date::now()->format('Y-m'),
                'type' => DeductionType::Penalty,
            ],
            [
                'amount' => '25000.00',
                'reason' => 'Till shortage carried from the previous count',
                'created_by' => $hr->getKey(),
            ],
        );
    }

    /**
     * An outstanding staff loan, so a payroll run has a recovery deduction to
     * demonstrate and the Staff Loan Receivable account has a balance.
     *
     * Walked through the real workflow now — requested, approved by HR,
     * disbursed by Finance — rather than written directly as an active row.
     * The seeder used to be the only thing in the system that could create a
     * staff loan, and a seeded row that skipped the lifecycle was also a row
     * that proved the lifecycle worked for nobody.
     */
    private function seedStaffLoan(): void
    {
        $staff = $this->staffFor('0754000005');
        $hr = User::query()->where('phone', '0754000007')->first();
        $finance = User::query()->where('phone', '0754000003')->first();

        if ($staff === null || $hr === null || $finance === null || $staff->loans()->exists()) {
            return;
        }

        $action = app(StaffLoanAction::class);

        // Ten periods against 500,000 — the term the retired flat 50,000
        // recovery happened to imply, kept so the seeded books read the same.
        $loan = $action->request($staff, Money::of('500000.00'), 10, $hr);
        $action->approve($loan, $hr);
        $action->disburse($loan, $finance);

        // Backdated so the demo book shows a loan already part-way through
        // recovery rather than one disbursed today.
        $loan->update(['disbursed_at' => Date::now()->subDays(60)->toDateString()]);
    }

    /**
     * Two disbursed advances, walked through the real workflow states.
     *
     * Two sizes on purpose, so the Salary Advance screens are not all one
     * shape. The first falls in the Small Advance band and is recovered over a
     * single period, so PayrollSeeder's run clears it and the Paid List has a
     * row. The second is a Large Advance over three periods, so one run leaves
     * it part-recovered and the Active screen has a row with a real remaining
     * balance rather than an empty table.
     */
    private function seedStaffAdvance(): void
    {
        $this->advanceFor('0754000010', Money::of('150000.00'));
        $this->advanceFor('0754000011', Money::of('600000.00'));
    }

    private function advanceFor(string $phone, Money $amount): void
    {
        $staff = $this->staffFor($phone);
        $hr = User::query()->where('phone', '0754000007')->first();
        $finance = User::query()->where('phone', '0754000003')->first();

        if ($staff === null || $hr === null || $finance === null || $staff->advances()->exists()) {
            return;
        }

        $entry = app(LedgerService::class)->post(
            description: sprintf('Staff salary advance — %s', $staff->displayName()),
            sourceType: JournalSourceType::StaffAdvance,
            sourceId: null,
            lines: app(PayrollPostingBuilder::class)->buildAdvanceDisbursement(
                $amount,
                (int) $staff->getKey(),
                $staff->branch_id,
            ),
            postedBy: $finance,
            entryDate: Date::now()->subDays(20)->toImmutable(),
        );

        /*
         * Priced by the band the amount falls into, and the terms snapshotted
         * onto the advance exactly as RequestStaffAdvanceAction does it — so a
         * seeded advance recovers through the same arithmetic a real one does
         * rather than being a shape payroll cannot process.
         */
        $category = SalaryAdvanceCategory::covering($amount);
        $calculator = app(SalaryAdvanceCalculator::class);
        $periods = $category === null ? 1 : $category->recovery_periods;

        StaffAdvance::query()->create([
            'reference' => app(StaffAdvanceReferenceGenerator::class)->next(),
            'staff_profile_id' => $staff->getKey(),
            'salary_advance_category_id' => $category?->getKey(),
            'amount' => $amount->toDecimalString(),
            'interest_amount' => $category === null
                ? '0.00'
                : $calculator->interestOn($amount, $category)->toDecimalString(),
            'charge_fee' => $category === null ? '0.00' : $category->charge_fee,
            'recovery_periods' => $periods,
            'status' => StaffAdvanceStatus::Disbursed,
            'requested_at' => Date::now()->subDays(25),
            'approved_by' => $hr->getKey(),
            'approved_at' => Date::now()->subDays(22),
            'disbursed_at' => Date::now()->subDays(20),
            'due_date' => Date::now()->subDays(20)->addMonths($periods)->toDateString(),
            'journal_entry_id' => $entry->getKey(),
        ]);
    }

    /**
     * Reviews for field staff — the roles a Branch Manager actually reviews.
     */
    private function seedPerformanceRecords(): void
    {
        $recorder = User::query()->where('phone', '0754000004')->first();

        if ($recorder === null) {
            return;
        }

        $fieldRoles = [RoleName::LoanOfficer, RoleName::CreditOfficer, RoleName::BranchManager, RoleName::Teller];
        $period = Date::now()->subMonth()->format('Y-m');

        $staff = StaffProfile::query()
            ->with('user.role')
            ->get()
            ->filter(fn (StaffProfile $s): bool => in_array($s->user->roleName(), $fieldRoles, true))
            ->values();

        foreach ($staff as $index => $member) {
            $targets = ['loans_disbursed' => 12, 'collection_rate_pct' => 95, 'new_customers' => 8];

            $achieved = [
                'loans_disbursed' => 8 + (($index * 3) % 7),
                'collection_rate_pct' => 82 + (($index * 5) % 16),
                'new_customers' => 5 + (($index * 2) % 6),
            ];

            StaffPerformanceRecord::query()->updateOrCreate(
                ['staff_profile_id' => $member->getKey(), 'period' => $period],
                [
                    'targets_json' => $targets,
                    'achieved_json' => $achieved,
                    'rating' => $this->ratingFor($targets, $achieved),
                    'recorded_by' => $recorder->getKey(),
                ],
            );
        }
    }

    /**
     * The frontend's rating bands: the mean of the three hit rates.
     *
     * @param array<string, int> $targets
     * @param array<string, int> $achieved
     */
    private function ratingFor(array $targets, array $achieved): PerformanceRating
    {
        $hitRate = 0.0;

        foreach ($targets as $metric => $target) {
            $hitRate += $achieved[$metric] / $target;
        }

        $hitRate /= count($targets);

        return match (true) {
            $hitRate >= 0.95 => PerformanceRating::A,
            $hitRate >= 0.85 => PerformanceRating::B,
            $hitRate >= 0.70 => PerformanceRating::C,
            default => PerformanceRating::D,
        };
    }

    private function salaryFor(RoleName $role): string
    {
        return match ($role) {
            RoleName::SuperAdmin, RoleName::Admin, RoleName::Finance, RoleName::Hr, RoleName::Auditor => self::HQ_SALARY,
            RoleName::BranchManager => self::BRANCH_MANAGER_SALARY,
            RoleName::ZoneManager, RoleName::RegionalManager => self::OVERSIGHT_SALARY,
            RoleName::CreditOfficer => self::CREDIT_OFFICER_SALARY,
            default => self::FIELD_SALARY,
        };
    }

    private function staffFor(string $phone): ?StaffProfile
    {
        return StaffProfile::query()
            ->with('user')
            ->whereHas('user', fn ($q) => $q->where('phone', $phone))
            ->first();
    }
}
