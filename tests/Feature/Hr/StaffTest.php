<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\StaffAdvance;
use App\Models\StaffProfile;

beforeEach(function (): void {
    seedStaffBook();
});

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function staffPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Neema Shirima',
        'phone' => '0755400100',
        'email' => 'neema.shirima@mikopofasta.co.tz',
        'password' => 'password',
        'role' => RoleName::LoanOfficer->value,
        'branchId' => App\Models\Branch::query()->where('name', 'Kakonko')->value('id'),
        'baseSalary' => '850000.00',
        'commissionEligible' => true,
        'paymentMethod' => 'bank',
        'hiredAt' => now()->subDays(30)->toDateString(),
        'bankName' => 'NMB Bank',
        'bankAccountNumber' => '20110099887',
    ], $overrides);
}

describe('registration', function (): void {
    it('creates the user and the staff profile together', function (): void {
        actingAsHr();

        $response = $this->postJson('/api/v1/staff', staffPayload())->assertCreated();

        $profile = StaffProfile::query()->latest('id')->firstOrFail();

        // §11: "users + staff_profiles created together". A profile without a
        // login cannot work the system; a login without a profile cannot be
        // paid.
        expect($profile->user)->not->toBeNull()
            ->and($profile->user->name)->toBe('Neema Shirima')
            ->and($response->json('data.employeeNumber'))->toStartWith('EMP-')
            ->and($response->json('data.baseSalary'))->toBe('850000.00');
    });

    it('mirrors the branch and zone from the user', function (): void {
        actingAsHr();

        $branchId = App\Models\Branch::query()->where('name', 'Missenyi')->value('id');
        $this->postJson('/api/v1/staff', staffPayload(['branchId' => $branchId]))->assertCreated();

        $profile = StaffProfile::query()->latest('id')->firstOrFail();

        expect($profile->branch_id)->toBe($branchId)
            ->and($profile->branch_id)->toBe($profile->user->branch_id);
    });

    it('stores bank details when given', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff', staffPayload())->assertCreated();

        $profile = StaffProfile::query()->with('bankDetail')->latest('id')->firstOrFail();

        expect($profile->bankDetail?->account_number)->toBe('20110099887');
    });

    it('numbers employees sequentially without gaps', function (): void {
        actingAsHr();

        $before = StaffProfile::query()->count();

        $this->postJson('/api/v1/staff', staffPayload())->assertCreated();
        $this->postJson('/api/v1/staff', staffPayload([
            'phone' => '0755400101', 'email' => 'second@mikopofasta.co.tz',
        ]))->assertCreated();

        $numbers = StaffProfile::query()->latest('id')->take(2)->pluck('employee_number')->all();

        expect($numbers[0])->toBe(sprintf('EMP-%04d', $before + 2))
            ->and($numbers[1])->toBe(sprintf('EMP-%04d', $before + 1));
    });

    it('rejects a duplicate phone number', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff', staffPayload(['phone' => '0754000001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    });

    it('rejects a hire date in the future', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff', staffPayload(['hiredAt' => now()->addMonth()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hiredAt');
    });

    it('records registration in the audit trail', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff', staffPayload())->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::StaffRegistered->value)->exists())->toBeTrue()
            // Provisioning the account is CreateUserAction's, so its own audit
            // row is written too rather than duplicated here.
            ->and(AuditLog::query()->where('action', AuditAction::UserCreated->value)->exists())->toBeTrue();
    });

    it('is denied to a role without hr.manage', function (): void {
        actingAsFinance();

        $this->postJson('/api/v1/staff', staffPayload())->assertForbidden();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson('/api/v1/staff', staffPayload())->assertForbidden();
    });
});

describe('the staff book', function (): void {
    it('lists staff with their user and branch', function (): void {
        actingAsHr();

        $response = $this->getJson('/api/v1/staff')->assertOk();

        expect($response->json('data'))->not->toBeEmpty()
            ->and($response->json('data.0.name'))->not->toBeNull()
            ->and($response->json('meta.pagination.total'))->toBe(StaffProfile::query()->count());
    });

    it('filters by search, status and branch', function (): void {
        actingAsHr();

        expect($this->getJson('/api/v1/staff?search=Amina')->assertOk()->json('data'))->toHaveCount(1);

        $branchId = App\Models\Branch::query()->where('name', 'Kakonko')->value('id');
        $atBranch = $this->getJson("/api/v1/staff?branch_id={$branchId}")->assertOk()->json('data');

        expect($atBranch)->not->toBeEmpty()
            ->and(collect($atBranch)->every(fn (array $r): bool => $r['branchId'] === (string) $branchId))->toBeTrue();

        staffFor('0754000010')->update(['employment_status' => EmploymentStatus::Terminated]);

        expect($this->getJson('/api/v1/staff?employment_status=terminated')->assertOk()->json('data'))
            ->toHaveCount(1);
    });

    it('shows one staff member with their loans, advances and reviews', function (): void {
        actingAsHr();

        $staff = staffFor('0754000010');

        $response = $this->getJson("/api/v1/staff/{$staff->id}")->assertOk();

        expect($response->json('data.employeeNumber'))->toBe($staff->employee_number)
            ->and($response->json('meta.advances'))->not->toBeEmpty();
    });

    it('updates employment terms', function (): void {
        actingAsHr();

        $staff = staffFor('0754000005');

        $this->putJson("/api/v1/staff/{$staff->id}", [
            'baseSalary' => '950000.00',
            'commissionEligible' => false,
        ])->assertOk()->assertJsonPath('data.baseSalary', '950000.00');

        expect($staff->fresh()->commission_eligible)->toBeFalse();
    });

    it('denies the staff book to a branch role', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/staff')->assertForbidden();
    });
});

describe('staff advances', function (): void {
    it('walks request, approval and disbursement', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');

        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])
            ->assertOk()
            /*
             * `active`, not `disbursed`. These endpoints now return the same
             * record the six Salary Advance screens read, and that resource
             * speaks the frontend's vocabulary — `active` and `repaid` where
             * §11 and the enum say `disbursed` and `recovered`. The stored
             * status is unchanged and asserted below.
             */
            ->assertJsonPath('data.status', 'active');

        $advance = StaffAdvance::query()->findOrFail($advanceId);

        expect($advance->status)->toBe(StaffAdvanceStatus::Disbursed)
            ->and($advance->journal_entry_id)->not->toBeNull()
            ->and($advance->disbursed_at)->not->toBeNull();
    });

    it('posts nothing until disbursement', function (): void {
        $staff = staffFor('0754000005');
        $before = JournalEntry::query()->count();

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        // An advance that has been asked for, and even approved, is not money
        // that has moved.
        expect(JournalEntry::query()->count())->toBe($before);

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        expect(JournalEntry::query()->count())->toBe($before + 1);
    });

    it('debits the advance receivable and credits the staff fund', function (): void {
        $staff = staffFor('0754000005');
        $accounts = app(AccountResolver::class);

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        $entry = JournalEntry::query()->with('lines')->where('source_type', 'staff_advance')
            ->latest('id')->firstOrFail();

        expect($entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::StaffAdvanceReceivable))?->debit_amount)
            ->toBe('200000.00')
            ->and($entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::StaffFund))?->credit_amount)
            ->toBe('200000.00')
            ->and($entry->isBalanced())->toBeTrue()
            ->and(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
    });

    it('tags the entry with the staff dimension so the sub-ledger works', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        // §11: Staff Loan / Advance / Deductions are views over
        // journal_entry_lines.staff_profile_id, not tables.
        $response = $this->getJson("/api/v1/ledger/staff/{$staff->id}")->assertOk();

        expect($response->json('data'))->not->toBeEmpty()
            ->and($response->json('meta.dimension'))->toBe('staff');
    });

    it('recovers a disbursed advance through the next payroll', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        $run = finalizedPayrollRun();
        $line = $run->lines->firstWhere('staff_profile_id', $staff->getKey());

        $recovery = $line->deductions()->where('type', 'advance')->sole();

        /*
         * Derived from the advance's own terms, not a constant.
         *
         * 200,000 falls in the Small Advance band (10,000–200,000, bounds
         * inclusive): 5% interest = 10,000, a 2,000 charge fee, recovered over
         * one period. So the whole 212,000 comes off this payslip.
         */
        $advance = StaffAdvance::query()->findOrFail($advanceId);

        expect($recovery->amount)->toBe('212000.00')
            ->and($recovery->reference_id)->toBe((int) $advanceId);

        /*
         * And the advance is finished. Nothing used to set this: a disbursed
         * advance stayed outstanding for ever and payroll kept deducting
         * against it every month.
         */
        expect($advance->status)->toBe(StaffAdvanceStatus::Recovered)
            ->and($advance->amount_recovered)->toBe('212000.00')
            ->and($advance->recovered_at)->not->toBeNull();
    });

    it('refuses a second advance while one is in progress', function (): void {
        // Joseph Mrema already carries the seeded disbursed advance.
        $staff = staffFor('0754000010');

        actingAsHr();
        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '100000.00',
        ])->assertStatus(409)->assertJsonPath('error_code', 'ADVANCE_IN_PROGRESS');
    });

    it('allows a new advance once the previous one was rejected', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $first = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/advance/reject', ['advanceId' => $first])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Rejected is finished business, not something in flight.
        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated();
    });

    it('refuses to disburse an advance that was never approved', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated()->json('data.id');

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_ADVANCE_STATE');
    });

    it('refuses to decide an advance twice', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertStatus(409);
    });

    it('separates HR approval from Finance disbursement', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        // §11: "disbursement (Finance only, never HR)".
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertForbidden();

        // And the converse: Finance does not decide advances.
        actingAsFinance();
        $second = StaffAdvance::query()->create([
            'staff_profile_id' => staffFor('0754000006')->getKey(),
            'amount' => '100000.00',
            'status' => StaffAdvanceStatus::Requested,
            'requested_at' => now(),
        ]);

        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $second->getKey()])->assertForbidden();

        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();
    });

    it('records every step in the audit trail', function (): void {
        $staff = staffFor('0754000005');

        actingAsHr();
        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => '100000.00',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();

        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        foreach ([
            AuditAction::StaffAdvanceRequested,
            AuditAction::StaffAdvanceApproved,
            AuditAction::StaffAdvanceDisbursed,
        ] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->exists())->toBeTrue();
        }
    });
});

describe('performance', function (): void {
    it('records a review', function (): void {
        actingAsHr();

        $staff = staffFor('0754000005');

        $this->postJson('/api/v1/staff/performance', [
            'staffProfileId' => $staff->getKey(),
            'period' => currentPeriod(),
            'targets' => ['loans_disbursed' => 12, 'collection_rate_pct' => 95],
            'achieved' => ['loans_disbursed' => 14, 'collection_rate_pct' => 97],
            'rating' => 'A',
        ])->assertCreated()
            ->assertJsonPath('data.rating', 'A')
            ->assertJsonPath('data.targets.loans_disbursed', 12);
    });

    it('revises rather than duplicating a review for the same period', function (): void {
        actingAsHr();
        $staff = staffFor('0754000005');

        $payload = [
            'staffProfileId' => $staff->getKey(),
            'period' => currentPeriod(),
            'targets' => ['loans_disbursed' => 12],
            'achieved' => ['loans_disbursed' => 6],
            'rating' => 'D',
        ];

        $this->postJson('/api/v1/staff/performance', $payload)->assertCreated();
        $this->postJson('/api/v1/staff/performance', ['rating' => 'C'] + $payload)->assertCreated();

        $records = App\Models\StaffPerformanceRecord::query()
            ->where('staff_profile_id', $staff->getKey())
            ->where('period', currentPeriod())
            ->get();

        // One review per person per period — two contradictory versions on
        // file would be worse than none.
        expect($records)->toHaveCount(1)
            ->and($records->first()->rating->value)->toBe('C');
    });

    it('lets a Branch Manager review their own staff', function (): void {
        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson('/api/v1/staff/performance', [
            'staffProfileId' => staffFor('0754000005')->getKey(),
            'period' => currentPeriod(),
            'targets' => ['loans_disbursed' => 12],
            'achieved' => ['loans_disbursed' => 10],
            'rating' => 'B',
        ])->assertCreated();
    });

    it('has no bearing on pay', function (): void {
        actingAsHr();
        $staff = staffFor('0754000005');

        $this->postJson('/api/v1/staff/performance', [
            'staffProfileId' => $staff->getKey(),
            'period' => currentPeriod(),
            'targets' => ['loans_disbursed' => 12],
            'achieved' => ['loans_disbursed' => 1],
            'rating' => 'D',
        ])->assertCreated();

        $run = finalizedPayrollRun();
        $line = $run->lines->firstWhere('staff_profile_id', $staff->getKey());

        /*
         * A "D" rating changes nothing. §11 computes commission from branch
         * profit and payroll from base salary, and neither reads a rating.
         * Wiring one into pay would be inventing an incentive scheme.
         */
        expect($line->base_salary)->toBe($staff->base_salary);
    });

    it('requires a well-formed period and non-empty metrics', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff/performance', [
            'staffProfileId' => staffFor('0754000005')->getKey(),
            'period' => 'last month',
            'targets' => [],
            'achieved' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['period', 'targets', 'achieved']);
    });

    it('records the review in the audit trail', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/staff/performance', [
            'staffProfileId' => staffFor('0754000005')->getKey(),
            'period' => currentPeriod(),
            'targets' => ['loans_disbursed' => 12],
            'achieved' => ['loans_disbursed' => 10],
            'rating' => 'B',
        ])->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::PerformanceRecorded->value)->exists())->toBeTrue();
    });
});
