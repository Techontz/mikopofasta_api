<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\PerformanceRating;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use App\Domain\Hr\Services\EmployeeNumberGenerator;
use App\Domain\Hr\Services\PayrollPostingBuilder;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Models\StaffAdvance;
use App\Models\StaffLoan;
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
        }

        $this->seedStaffLoan();
        $this->seedStaffAdvance();
        $this->seedPerformanceRecords();
    }

    /**
     * An outstanding staff loan, so a payroll run has a recovery deduction to
     * demonstrate and the Staff Loan Receivable account has a balance.
     */
    private function seedStaffLoan(): void
    {
        $staff = $this->staffFor('0754000005');
        $finance = User::query()->where('phone', '0754000003')->first();

        if ($staff === null || $finance === null || $staff->loans()->exists()) {
            return;
        }

        $amount = Money::of('500000.00');

        $entry = app(LedgerService::class)->post(
            description: sprintf('Staff loan disbursement — %s', $staff->displayName()),
            sourceType: JournalSourceType::StaffLoan,
            sourceId: null,
            lines: app(PayrollPostingBuilder::class)->buildLoanDisbursement(
                $amount,
                (int) $staff->getKey(),
                $staff->branch_id,
            ),
            postedBy: $finance,
            entryDate: Date::now()->subDays(60)->toImmutable(),
        );

        StaffLoan::query()->create([
            'staff_profile_id' => $staff->getKey(),
            'amount' => $amount->toDecimalString(),
            'status' => StaffLoanStatus::Active,
            'disbursed_at' => Date::now()->subDays(60)->toDateString(),
            'journal_entry_id' => $entry->getKey(),
        ]);
    }

    /**
     * A disbursed advance, walked through the real workflow states.
     */
    private function seedStaffAdvance(): void
    {
        $staff = $this->staffFor('0754000010');
        $hr = User::query()->where('phone', '0754000007')->first();
        $finance = User::query()->where('phone', '0754000003')->first();

        if ($staff === null || $hr === null || $finance === null || $staff->advances()->exists()) {
            return;
        }

        $amount = Money::of('150000.00');

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

        StaffAdvance::query()->create([
            'staff_profile_id' => $staff->getKey(),
            'amount' => $amount->toDecimalString(),
            'status' => StaffAdvanceStatus::Disbursed,
            'requested_at' => Date::now()->subDays(25),
            'approved_by' => $hr->getKey(),
            'approved_at' => Date::now()->subDays(22),
            'disbursed_at' => Date::now()->subDays(20),
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
