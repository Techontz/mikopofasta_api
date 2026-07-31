<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\SalaryAdvanceCategory;
use App\Models\StaffAdvance;
use App\Support\Money;

/**
 * The six Salary Advance screens, and the pricing and recovery behind them.
 */
beforeEach(function (): void {
    seedStaffBook();
});

/** @param array<string, mixed> $overrides */
function categoryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Bridging Advance',
        'interestRate' => '8.000',
        // Above every seeded band, so it never collides by accident.
        'fromAmount' => '6000000.00',
        'toAmount' => '8000000.00',
        'chargeFee' => '15000.00',
        'recoveryPeriods' => 4,
    ], $overrides);
}

function advanceCalculator(): SalaryAdvanceCalculator
{
    return app(SalaryAdvanceCalculator::class);
}

function fundBalance(): float
{
    return (float) app(AccountResolver::class)->system(SystemAccountCode::StaffFund)
        ->load('balances')->cachedBalance()->toDecimalString();
}

describe('categories', function (): void {
    it('lists the seeded bands in amount order', function (): void {
        actingAsHr();

        $rows = $this->getJson('/api/v1/salary-advance-categories')->assertOk()->json('data');

        // Read as a ladder: ordering by band is how anyone checks for gaps.
        $floors = array_map(static fn (array $r): float => (float) $r['fromAmount'], $rows);
        expect($floors)->toBe(collect($floors)->sort()->values()->all());
    });

    it('creates a band', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/salary-advance-categories', categoryPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bridging Advance')
            ->assertJsonPath('data.recoveryPeriods', 4);
    });

    it('refuses a band that overlaps another', function (): void {
        actingAsHr();

        // Small Advance is 10,000–200,000.
        $this->postJson('/api/v1/salary-advance-categories', categoryPayload([
            'fromAmount' => '150000.00',
            'toAmount' => '300000.00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('allows a band that starts exactly where another ends', function (): void {
        actingAsHr();

        // Executive Advance ends at 5,000,000. Touching is not overlapping —
        // and refusing it would force a gap no request could be priced in.
        $this->postJson('/api/v1/salary-advance-categories', categoryPayload([
            'fromAmount' => '5000000.01',
            'toAmount' => '6000000.00',
        ]))->assertCreated();
    });

    it('refuses a ceiling below its floor', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/salary-advance-categories', categoryPayload([
            'fromAmount' => '900000.00',
            'toAmount' => '100000.00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.toAmount.0', 'The upper bound must be greater than the lower bound.');
    });

    it('refuses a duplicate name', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/salary-advance-categories', categoryPayload(['name' => 'Small Advance']))
            ->assertStatus(422);
    });

    it('refuses zero recovery periods', function (): void {
        actingAsHr();

        // An advance recovered over no payslips is never recovered.
        $this->postJson('/api/v1/salary-advance-categories', categoryPayload(['recoveryPeriods' => 0]))
            ->assertStatus(422);
    });

    it('re-prices future requests but never an agreed advance', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '100000.00',
        ])->assertCreated()->json('data.id');

        $agreedInterest = StaffAdvance::query()->findOrFail($advanceId)->interest_amount;

        // Settings doubles the rate on the band this advance was priced by.
        $band = SalaryAdvanceCategory::query()->where('name', 'Small Advance')->sole();
        $this->putJson("/api/v1/salary-advance-categories/{$band->id}", categoryPayload([
            'name' => 'Small Advance',
            'interestRate' => '10.000',
            'fromAmount' => '10000.00',
            'toAmount' => '200000.00',
            'chargeFee' => '2000.00',
            'recoveryPeriods' => 1,
        ]))->assertOk();

        // The employee agreed 5%, and is still on 5%.
        expect(StaffAdvance::query()->findOrFail($advanceId)->interest_amount)->toBe($agreedInterest);
    });

    it('refuses to retire a band with an advance in flight', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '100000.00',
        ])->assertCreated();

        $band = SalaryAdvanceCategory::query()->where('name', 'Small Advance')->sole();

        $this->deleteJson("/api/v1/salary-advance-categories/{$band->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('retires a band nothing is using, keeping it for history', function (): void {
        actingAsHr();
        $id = $this->postJson('/api/v1/salary-advance-categories', categoryPayload())
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/salary-advance-categories/{$id}")->assertOk();

        expect(SalaryAdvanceCategory::query()->find($id))->toBeNull()
            ->and(SalaryAdvanceCategory::query()->withTrashed()->find($id))->not->toBeNull();
    });
});

describe('pricing a request', function (): void {
    it('prices by the band the amount falls into', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '300000.00',
        ])->assertCreated();

        // 300,000 is Standard Advance: 7.5% = 22,500, 5,000 fee, 2 periods.
        $advance = StaffAdvance::query()->latest('id')->firstOrFail();

        expect($advance->interest_amount)->toBe('22500.00')
            ->and($advance->charge_fee)->toBe('5000.00')
            ->and($advance->recovery_periods)->toBe(2);
    });

    it('treats band bounds as inclusive', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        // Exactly the Small Advance ceiling.
        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '200000.00',
        ])->assertCreated();

        expect(StaffAdvance::query()->latest('id')->firstOrFail()->category->name)->toBe('Small Advance');
    });

    it('refuses an amount no band covers', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        // Below the lowest floor of 10,000.
        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '500.00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    });

    it('gives every advance a reference', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '100000.00',
        ])->assertCreated();

        expect(StaffAdvance::query()->latest('id')->firstOrFail()->reference)->toStartWith('ADV-');
    });
});

describe('recovery', function (): void {
    it('takes the advance own instalment, not a flat figure', function (): void {
        actingAsHr();
        $staff = staffFor('0754000009');

        $advanceId = $this->postJson('/api/v1/staff/advance/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => '600000.00',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/advance/approve', ['advanceId' => $advanceId])->assertOk();
        actingAsFinance();
        $this->postJson('/api/v1/staff/advance/disburse', ['advanceId' => $advanceId])->assertOk();

        $advance = StaffAdvance::query()->findOrFail($advanceId);

        /*
         * Large Advance: 600,000 + 10% interest + 10,000 fee = 670,000 over
         * three periods. The old behaviour deducted a flat 50,000 from
         * everyone regardless of what they had borrowed.
         */
        expect(advanceCalculator()->totalRepayable($advance)->toDecimalString())->toBe('670000.00')
            ->and(advanceCalculator()->recoveryFor($advance)->toDecimalString())->toBe('223333.34');
    });

    it('never recovers more than is still owed', function (): void {
        $advance = StaffAdvance::query()->where('status', StaffAdvanceStatus::Disbursed)->firstOrFail();

        // Push it to one shilling outstanding.
        $advance->update([
            'amount_recovered' => advanceCalculator()->totalRepayable($advance)
                ->subtract(Money::of('1.00'))->toDecimalString(),
        ]);

        expect(advanceCalculator()->recoveryFor($advance->fresh())->toDecimalString())->toBe('1.00');
    });

    it('closes the advance once it clears', function (): void {
        $advance = StaffAdvance::query()->where('status', StaffAdvanceStatus::Disbursed)->firstOrFail();

        actingAsHr();
        app(App\Domain\Hr\Actions\RecoverStaffAdvanceAction::class)->recover(
            $advance,
            advanceCalculator()->outstanding($advance),
            auth()->user(),
        );

        /*
         * Nothing used to set this. A disbursed advance stayed outstanding for
         * ever, and payroll deducted against it every month indefinitely.
         */
        expect($advance->fresh()->status)->toBe(StaffAdvanceStatus::Recovered)
            ->and($advance->fresh()->recovered_at)->not->toBeNull();
    });

    it('records each instalment in the audit trail', function (): void {
        $advance = StaffAdvance::query()->where('status', StaffAdvanceStatus::Disbursed)->firstOrFail();

        actingAsHr();
        app(App\Domain\Hr\Actions\RecoverStaffAdvanceAction::class)
            ->recover($advance, Money::of('1000.00'), auth()->user());

        expect(AuditLog::query()->where('action', AuditAction::StaffAdvanceRepaid->value)->exists())->toBeTrue();
    });

    it('leaves a settled advance alone', function (): void {
        /*
         * Settled here rather than looked for. seedStaffBook deliberately stops
         * short of payroll, so no advance has been recovered yet — and a test
         * that assumed one had would be asserting against whatever an unrelated
         * seeder happened to leave behind.
         */
        $advance = StaffAdvance::query()->where('status', StaffAdvanceStatus::Disbursed)->firstOrFail();

        actingAsHr();
        $recover = app(App\Domain\Hr\Actions\RecoverStaffAdvanceAction::class);

        $recover->recover($advance, advanceCalculator()->outstanding($advance), auth()->user());

        $advance = $advance->fresh();
        expect($advance->status)->toBe(StaffAdvanceStatus::Recovered);

        $before = $advance->amount_recovered;

        $recover->recover($advance, Money::of('5000.00'), auth()->user());

        // An overpayment is not a debt, and a closed advance is not reopened.
        expect($advance->fresh()->amount_recovered)->toBe($before);
    });

    it('posts nothing of its own — the payroll entry already moved the money', function (): void {
        $advance = StaffAdvance::query()->where('status', StaffAdvanceStatus::Disbursed)->firstOrFail();
        $entries = App\Models\JournalEntry::query()->count();

        actingAsHr();
        app(App\Domain\Hr\Actions\RecoverStaffAdvanceAction::class)
            ->recover($advance, Money::of('1000.00'), auth()->user());

        // Posting here would credit 7020 twice and the advance would appear to
        // repay itself at double rate.
        expect(App\Models\JournalEntry::query()->count())->toBe($entries);
    });
});

describe('the registers', function (): void {
    it('serves the shape the six screens read', function (): void {
        actingAsHr();

        $this->getJson('/api/v1/salary-advances')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'reference', 'customerName', 'phone', 'branch',
                    'categoryId', 'categoryName', 'loanAmount', 'interest',
                    'paidAmount', 'chargeFee', 'status', 'date', 'overdueDays',
                    'totalRepayable', 'remaining',
                ]],
            ]);
    });

    it('speaks the frontend status vocabulary', function (): void {
        actingAsHr();

        $statuses = collect($this->getJson('/api/v1/salary-advances')->assertOk()->json('data'))
            ->pluck('status')->unique();

        // `active` and `repaid`, never `disbursed` or `recovered`.
        expect($statuses->contains('disbursed'))->toBeFalse()
            ->and($statuses->contains('recovered'))->toBeFalse();
    });

    it('filters by that vocabulary too', function (): void {
        actingAsHr();

        $active = $this->getJson('/api/v1/salary-advances?status=active')->assertOk()->json('data');
        expect($active)->not->toBeEmpty();

        foreach ($active as $row) {
            expect($row['status'])->toBe('active');
        }
    });

    it('accepts the backend vocabulary as well', function (): void {
        actingAsHr();

        // An API caller using §11's words should not be turned away.
        $this->getJson('/api/v1/salary-advances?status=disbursed')->assertOk();
    });

    it('reports remaining as total repayable less what has been recovered', function (): void {
        actingAsHr();

        $row = collect($this->getJson('/api/v1/salary-advances?status=active')->assertOk()->json('data'))->first();

        expect((float) $row['remaining'])
            ->toBe((float) $row['totalRepayable'] - (float) $row['paidAmount']);
    });

    it('paginates', function (): void {
        actingAsHr();

        $this->getJson('/api/v1/salary-advances?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.perPage', 1);
    });

    it('searches by employee name', function (): void {
        actingAsHr();
        $advance = StaffAdvance::query()->with('staffProfile.user')->firstOrFail();
        $name = $advance->staffProfile->user->name;

        $rows = $this->getJson('/api/v1/salary-advances?search='.urlencode($name))->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();
    });

    it('filters by branch', function (): void {
        actingAsHr();
        $advance = StaffAdvance::query()->with('staffProfile')->firstOrFail();
        $branchId = $advance->staffProfile->branch_id;

        $rows = $this->getJson('/api/v1/salary-advances?branch_id='.$branchId)->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();
    });

    it('refuses a reversed date range', function (): void {
        actingAsHr();

        $this->getJson('/api/v1/salary-advances?from=2026-09-01&to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'The end of the range cannot fall before its start.');
    });
});

describe('the repayment register', function (): void {
    /*
     * These need a finalised run: an advance is repaid by being deducted from a
     * payslip, so until payroll has run there is nothing to list. seedStaffBook
     * deliberately stops short of payroll.
     */
    beforeEach(function (): void {
        finalizedPayrollRun();
        forgetAuthGuards();
    });

    it('lists each instalment taken from a payslip', function (): void {
        actingAsHr();

        $this->getJson('/api/v1/salary-advances/repayments')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'branch', 'customerName', 'amount', 'date']]]);
    });

    it('totals what was recovered', function (): void {
        actingAsHr();

        $response = $this->getJson('/api/v1/salary-advances/repayments')->assertOk();

        /*
         * Summed as Money, not as floats. Adding 159,500.00 and 223,333.34 in
         * binary floating point gives 382,833.33999999997 — the exact drift
         * Money exists to prevent, and comparing the endpoint's decimal string
         * against it would fail for a reason that has nothing to do with the
         * endpoint.
         */
        $sum = Money::sum(
            collect($response->json('data'))->map(fn (array $r): Money => Money::of((string) $r['amount'])),
        );

        expect($response->json('meta.totalRepaid'))->toBe($sum->toDecimalString());
    });
});

describe('authorization', function (): void {
    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/salary-advances')->assertUnauthorized();
        $this->getJson('/api/v1/salary-advance-categories')->assertUnauthorized();
    });

    it('denies a role without hr.view', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $this->getJson('/api/v1/salary-advances')->assertForbidden();
        $this->getJson('/api/v1/salary-advance-categories')->assertForbidden();
    });

    it('lets a read-only HR role list but not manage bands', function (): void {
        // Auditor holds hr.view and not hr.manage.
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/salary-advance-categories')->assertOk();
        $this->postJson('/api/v1/salary-advance-categories', categoryPayload())->assertForbidden();
    });
});
