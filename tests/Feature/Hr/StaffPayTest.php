<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Services\StaffLoanCalculator;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Models\StaffAllowance;
use App\Models\StaffLoan;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Module 7 — what an employee draws, what is withheld, what they were paid, and
 * the staff loan lifecycle.
 *
 * See docs/modules/hr-payroll.md.
 */
beforeEach(function (): void {
    seedStaffBook();
});

/** The net position of an account for one employee, from the ledger. */
function staffAccountNet(SystemAccountCode $code, int $staffProfileId): string
{
    $accountId = app(AccountResolver::class)->systemId($code);

    $row = DB::table('journal_entry_lines')
        ->where('account_id', $accountId)
        ->where('staff_profile_id', $staffProfileId)
        ->selectRaw('COALESCE(SUM(debit_amount), 0) d, COALESCE(SUM(credit_amount), 0) c')
        ->first();

    return bcsub((string) $row->d, (string) $row->c, 2);
}

// ---------------------------------------------------------------------------
// Staff loans — the defect this module exists to fix
// ---------------------------------------------------------------------------

describe('staff loans', function (): void {
    it('walks request, HR approval and Finance disbursement', function (): void {
        $staff = staffFor('0754000006');

        $hr = actingAsHr();
        $id = $this->postJson('/api/v1/staff/loan/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => 300000,
            'recoveryPeriods' => 6,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            // Nothing has moved: a loan that has been asked for is not money.
            ->assertJsonPath('data.journalEntryId', null)
            ->json('data.id');

        $this->postJson('/api/v1/staff/loan/approve', ['loanId' => $id])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.journalEntryId', null);

        actingAsFinance();
        $body = $this->postJson('/api/v1/staff/loan/disburse', ['loanId' => $id])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        expect($body['journalEntryId'])->not->toBeNull();

        // §14: Dr Staff Loan Receivable · Cr Staff Fund.
        expect(staffAccountNet(SystemAccountCode::StaffLoanReceivable, (int) $staff->getKey()))
            ->toBe('300000.00');
    });

    it('refuses to let HR disburse — §16.8 gives that to Finance', function (): void {
        $staff = staffFor('0754000006');

        $hr = actingAsHr();
        $id = $this->postJson('/api/v1/staff/loan/request', [
            'staffProfileId' => $staff->getKey(),
            'amount' => 200000,
            'recoveryPeriods' => 4,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/loan/approve', ['loanId' => $id])->assertOk();

        // Still HR, and still refused.
        $this->postJson('/api/v1/staff/loan/disburse', ['loanId' => $id])->assertForbidden();
    });

    it('refuses a second loan while one is in progress', function (): void {
        $staff = staffFor('0754000006');
        actingAsHr();

        $this->postJson('/api/v1/staff/loan/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => 100000, 'recoveryPeriods' => 2,
        ])->assertCreated();

        $this->postJson('/api/v1/staff/loan/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => 100000, 'recoveryPeriods' => 2,
        ])->assertStatus(409);
    });

    it('derives the instalment from the loan rather than a flat figure', function (): void {
        $loan = StaffLoan::query()->firstOrFail();
        $calculator = app(StaffLoanCalculator::class);

        // 500,000 over 10 periods. The retired constant was 50,000 for
        // everybody, whatever they had borrowed.
        expect($calculator->recoveryFor($loan)->toDecimalString())->toBe('50000.00');

        $loan->update(['amount' => '120000.00', 'recovery_periods' => 3, 'amount_recovered' => '0.00']);
        expect($calculator->recoveryFor($loan->fresh())->toDecimalString())->toBe('40000.00');
    });

    it('caps the last instalment at what is still owed', function (): void {
        $loan = StaffLoan::query()->firstOrFail();
        $loan->update(['amount' => '100000.00', 'recovery_periods' => 4, 'amount_recovered' => '95000.00']);

        // A quarter of 100,000 is 25,000, but only 5,000 is left.
        expect(app(StaffLoanCalculator::class)->recoveryFor($loan->fresh())->toDecimalString())
            ->toBe('5000.00');
    });

    /*
     * The defect, pinned.
     *
     * Before Module 7 nothing assigned StaffLoanStatus::Closed and the flat
     * 50,000 was never capped. Twelve simulated runs against the seeded 500,000
     * loan cleared it at the ninth and kept going — 7010 Staff Loan Receivable
     * reached −150,000, asserting the company owed the employee money it did
     * not, with the trial balance balanced throughout.
     */
    it('closes when the balance clears, and never over-recovers', function (): void {
        $loan = StaffLoan::query()->firstOrFail();
        $staffId = (int) $loan->staff_profile_id;

        // Short-term, so the whole life of the loan fits in a few runs.
        $loan->update(['amount' => '90000.00', 'recovery_periods' => 3, 'amount_recovered' => '0.00']);

        $opening = staffAccountNet(SystemAccountCode::StaffLoanReceivable, $staffId);

        foreach (['2027-01', '2027-02', '2027-03', '2027-04', '2027-05'] as $period) {
            runPayrollFor($period);
        }

        $settled = $loan->fresh();

        expect($settled->status)->toBe(StaffLoanStatus::Closed)
            ->and($settled->amount_recovered)->toBe('90000.00')
            ->and($settled->closed_at)->not->toBeNull();

        // Exactly the opening balance recovered, and not a shilling more: the
        // two runs after it closed took nothing.
        expect(staffAccountNet(SystemAccountCode::StaffLoanReceivable, $staffId))
            ->toBe(bcsub($opening, '90000.00', 2));
    });

    it('stops deducting once the loan is closed', function (): void {
        $loan = StaffLoan::query()->firstOrFail();
        $loan->update(['amount_recovered' => $loan->amount, 'status' => StaffLoanStatus::Closed]);

        $run = runPayrollFor('2027-01');
        $line = $run->lines->firstWhere('staff_profile_id', $loan->staff_profile_id);

        expect($line->deductions->where('type', DeductionType::Loan))->toBeEmpty();
    });

    it('records the whole lifecycle in the audit trail', function (): void {
        $staff = staffFor('0754000006');

        actingAsHr();
        $id = $this->postJson('/api/v1/staff/loan/request', [
            'staffProfileId' => $staff->getKey(), 'amount' => 150000, 'recoveryPeriods' => 3,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/staff/loan/approve', ['loanId' => $id])->assertOk();

        actingAsFinance();
        $this->postJson('/api/v1/staff/loan/disburse', ['loanId' => $id])->assertOk();

        foreach ([
            AuditAction::StaffLoanRequested,
            AuditAction::StaffLoanApproved,
            AuditAction::StaffLoanDisbursed,
        ] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->where('auditable_id', $id)->exists())
                ->toBeTrue("missing {$action->value}");
        }
    });
});

// ---------------------------------------------------------------------------
// Allowances — §10
// ---------------------------------------------------------------------------

describe('allowances', function (): void {
    it('enrols every employee on the standard entitlements', function (): void {
        $branchStaff = staffFor('0754000005');
        $hqStaff = staffFor('0754000003');

        actingAsHr();

        $branchTypes = collect(
            $this->getJson("/api/v1/staff/{$branchStaff->getKey()}/allowances")->assertOk()->json('data'),
        )->pluck('type')->all();

        $hqTypes = collect(
            $this->getJson("/api/v1/staff/{$hqStaff->getKey()}/allowances")->assertOk()->json('data'),
        )->pluck('type')->all();

        // Transport for branch staff only — head office has no commute to fund.
        expect($branchTypes)->toContain('transport')->toContain('airtime')
            ->and($hqTypes)->toContain('airtime')->not->toContain('transport');
    });

    it('awards a bonus, which no code path could do before', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        $body = $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Bonus->value,
            'amount' => 250000,
            'reason' => 'Highest recovery rate in the zone',
        ])->assertCreated()->json('data');

        // Forced to a period even though none was sent: a recurring bonus is a
        // salary increase, and belongs on the profile instead.
        expect($body['recurring'])->toBeFalse()
            ->and($body['period'])->toBe(now()->format('Y-m'));
    });

    it('pays the bonus on the payslip for that month only', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Bonus->value,
            'amount' => 250000,
            'period' => '2027-03',
            'reason' => 'Quarterly award',
        ])->assertCreated();

        $withBonus = runPayrollFor('2027-03')->lines->firstWhere('staff_profile_id', $staff->getKey());
        $without = runPayrollFor('2027-04')->lines->firstWhere('staff_profile_id', $staff->getKey());

        expect($withBonus->allowances->firstWhere('type', AllowanceType::Bonus)?->amount)->toBe('250000.00')
            ->and($without->allowances->firstWhere('type', AllowanceType::Bonus))->toBeNull()
            ->and($withBonus->allowancesTotal()->greaterThan($without->allowancesTotal()))->toBeTrue();
    });

    it('refuses a second recurring allowance of the same type', function (): void {
        $staff = staffFor('0754000005');
        actingAsHr();

        // Transport is already an entitlement from registration.
        $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Transport->value,
            'amount' => 80000,
        ])->assertUnprocessable();
    });

    it('changes one person’s transport without touching anybody else', function (): void {
        $staff = staffFor('0754000005');
        $other = staffFor('0754000004');
        actingAsHr();

        $allowance = StaffAllowance::query()
            ->where('staff_profile_id', $staff->getKey())
            ->where('type', AllowanceType::Transport)
            ->firstOrFail();

        $this->putJson("/api/v1/staff-allowances/{$allowance->getKey()}", [
            'type' => AllowanceType::Transport->value,
            'amount' => 90000,
            'reason' => 'Posted to an outlying ward',
        ])->assertOk()->assertJsonPath('data.amount', '90000.00');

        // The whole point of rows over constants.
        $theirs = StaffAllowance::query()
            ->where('staff_profile_id', $other->getKey())
            ->where('type', AllowanceType::Transport)
            ->firstOrFail();

        expect($theirs->amount)->toBe('50000.00');
    });

    it('stands an allowance down without losing the record of it', function (): void {
        $staff = staffFor('0754000005');
        actingAsHr();

        $allowance = StaffAllowance::query()
            ->where('staff_profile_id', $staff->getKey())
            ->where('type', AllowanceType::Transport)
            ->firstOrFail();

        $this->deleteJson("/api/v1/staff-allowances/{$allowance->getKey()}")->assertOk();

        expect(StaffAllowance::query()->whereKey($allowance->getKey())->exists())->toBeFalse()
            ->and(StaffAllowance::withTrashed()->whereKey($allowance->getKey())->exists())->toBeTrue();

        // And it stops being paid.
        $line = runPayrollFor('2027-06')->lines->firstWhere('staff_profile_id', $staff->getKey());
        expect($line->allowances->firstWhere('type', AllowanceType::Transport))->toBeNull();
    });

    it('refuses a grant against a month whose payroll is already approved', function (): void {
        $staff = staffFor('0754000009');

        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-02'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk();

        // §16.1 — the figures stopped being editable at approval.
        $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Bonus->value,
            'amount' => 100000,
            'period' => '2027-02',
            'reason' => 'Too late',
        ])->assertStatus(409);
    });

    it('refuses every write without hr.manage', function (): void {
        $staff = staffFor('0754000005');
        actingAsRole(RoleName::Teller);

        $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Bonus->value, 'amount' => 1000,
        ])->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// Deductions — §11's penalties
// ---------------------------------------------------------------------------

describe('penalty deductions', function (): void {
    it('records a penalty, which no code path could do before', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        $this->postJson("/api/v1/staff/{$staff->getKey()}/deductions", [
            'type' => DeductionType::Penalty->value,
            'amount' => 30000,
            'period' => '2027-07',
            'reason' => 'Repeated late opening of the branch',
        ])->assertCreated()->assertJsonPath('data.type', 'penalty');
    });

    it('withholds it on that period’s payslip', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        $this->postJson("/api/v1/staff/{$staff->getKey()}/deductions", [
            'type' => DeductionType::Penalty->value,
            'amount' => 30000,
            'period' => '2027-07',
            'reason' => 'Repeated late opening of the branch',
        ])->assertCreated();

        $line = runPayrollFor('2027-07')->lines->firstWhere('staff_profile_id', $staff->getKey());

        expect($line->deductions->firstWhere('type', DeductionType::Penalty)?->amount)->toBe('30000.00');
    });

    it('refuses a hand-entered deduction of a computed type', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        // Staff fund, loan and advance are derived by payroll. A hand-entered
        // one would sit alongside the computed one and deduct twice.
        foreach ([DeductionType::StaffFund, DeductionType::Loan, DeductionType::Advance] as $type) {
            $this->postJson("/api/v1/staff/{$staff->getKey()}/deductions", [
                'type' => $type->value,
                'amount' => 10000,
                'period' => '2027-07',
                'reason' => 'Should not be possible',
            ])->assertUnprocessable()->assertJsonValidationErrors('type');
        }
    });

    it('insists on a reason', function (): void {
        $staff = staffFor('0754000009');
        actingAsHr();

        $this->postJson("/api/v1/staff/{$staff->getKey()}/deductions", [
            'type' => DeductionType::Penalty->value,
            'amount' => 30000,
            'period' => '2027-07',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    });
});

// ---------------------------------------------------------------------------
// Payroll approval — §16.1, §16.7
// ---------------------------------------------------------------------------

describe('payroll approval', function (): void {
    it('sits between HR’s draft and Finance’s posting', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-08'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson("/api/v1/payroll/{$runId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Approval posts nothing — Finance still does that.
        expect(PayrollRun::query()->findOrFail($runId)->lines()->whereNotNull('journal_entry_id')->exists())
            ->toBeFalse();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/finalize")->assertOk()
            ->assertJsonPath('data.status', 'finalized');
    });

    it('refuses to finalize a run nobody approved', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-08'])
            ->assertCreated()->json('data.id');

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/finalize")->assertStatus(409);
    });

    it('is HR’s decision, not Finance’s — §16.7', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-08'])
            ->assertCreated()->json('data.id');

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertForbidden();
    });

    it('records who approved it and when', function (): void {
        $hr = actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-08'])
            ->assertCreated()->json('data.id');

        $body = $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk()->json('data');

        expect($body['approvedBy'])->toBe((string) $hr->getKey())
            ->and($body['approvedAt'])->not->toBeNull();

        $log = AuditLog::query()->where('action', AuditAction::PayrollApproved->value)->sole();
        expect($log->user_id)->toBe($hr->getKey())
            ->and($log->after_json['ledger_posting'])->toContain('none');
    });

    it('cannot be approved twice', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => '2027-08'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk();
        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertStatus(409);
    });
});

// ---------------------------------------------------------------------------
// Payslips — §17
// ---------------------------------------------------------------------------

describe('payslips', function (): void {
    it('carries the employee, their branch and their bank account', function (): void {
        finalizedPayrollRun();
        actingAsHr();

        $rows = $this->getJson('/api/v1/payslips')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();
        expect($rows[0])->toHaveKeys([
            'employee', 'staffNo', 'department', 'branch', 'phone',
            'bankName', 'accountNumber', 'salary', 'netSalary', 'status',
        ]);
    });

    it('opens on the latest period and lists the ones that exist', function (): void {
        finalizedPayrollRun();
        actingAsHr();

        $meta = $this->getJson('/api/v1/payslips')->assertOk()->json('meta');

        expect($meta['period'])->toBe(currentPeriod())
            ->and($meta['periods'])->toContain(currentPeriod());
    });

    it('totals net, gross and deductions across the period', function (): void {
        $run = finalizedPayrollRun();
        actingAsHr();

        $meta = $this->getJson('/api/v1/payslips')->assertOk()->json('meta');

        expect($meta['totalNet'])->toBe($run->netTotal()->toDecimalString());
    });

    /*
     * §14 gives Finance `payroll.finalize` and no HR grant at all, and Finance
     * is the role that posts and pays a run. A payslip gated behind `hr.view`
     * would mean the person releasing the money could not see what they were
     * releasing, and Bank → Payroll — a Finance screen — would answer 403 to
     * the role it exists for.
     */
    it('opens to Finance, who holds no HR grant', function (): void {
        finalizedPayrollRun();
        actingAsFinance();

        $this->getJson('/api/v1/payslips')->assertOk();
        $this->getJson('/api/v1/staff-fund')->assertOk();

        // Reading, not deciding: what somebody draws is still HR's alone.
        $staff = staffFor('0754000005');
        $this->postJson("/api/v1/staff/{$staff->getKey()}/allowances", [
            'type' => AllowanceType::Bonus->value, 'amount' => 50000,
        ])->assertForbidden();
    });

    it('is closed to a role holding neither grant', function (): void {
        finalizedPayrollRun();
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/payslips')->assertForbidden();
        $this->getJson('/api/v1/staff-fund')->assertForbidden();
    });

    it('gives one employee their payment history', function (): void {
        finalizedPayrollRun();
        $staff = staffFor('0754000005');
        actingAsHr();

        $body = $this->getJson("/api/v1/staff/{$staff->getKey()}/payslips")->assertOk();

        $body->assertJsonPath('data.0.staffProfileId', (string) $staff->getKey());
        expect($body->json('meta.totalPaid'))->not->toBe('0.00');
    });
});

// ---------------------------------------------------------------------------
// Staff Fund and the §2B ledger views
// ---------------------------------------------------------------------------

describe('staff fund', function (): void {
    it('reports the fund’s position', function (): void {
        finalizedPayrollRun();
        actingAsHr();

        $body = $this->getJson('/api/v1/staff-fund')->assertOk()->json('data');

        expect($body)->toHaveKeys([
            'balance', 'contributions', 'advancesOutstanding', 'loansOutstanding',
            'lentOut', 'memberCount',
        ]);

        // The balance is the ledger's, not a figure derived beside it.
        $ledger = app(AccountResolver::class)->system(SystemAccountCode::StaffFund)
            ->load('balances')->cachedBalance();

        expect($body['balance'])->toBe($ledger->toDecimalString());
    });

    /*
     * §2B says registering an employee creates four accounts. §11 resolves them
     * as views over the `staff_profile_id` dimension rather than four real
     * chart rows per person — this is that dimension read back.
     */
    it('gives an employee their four §2B views', function (): void {
        finalizedPayrollRun();
        $staff = staffFor('0754000005');
        actingAsHr();

        $body = $this->getJson("/api/v1/staff/{$staff->getKey()}/ledger")->assertOk()->json('data');

        expect(array_keys($body))->toEqualCanonicalizing(['control', 'loan', 'advance', 'deductions']);

        // The loan view agrees with the loan itself.
        $loan = StaffLoan::query()->where('staff_profile_id', $staff->getKey())->firstOrFail();
        expect($body['loan']['balance'])
            ->toBe(app(StaffLoanCalculator::class)->outstanding($loan)->toDecimalString());
    });
});

// ---------------------------------------------------------------------------
// The §17 reports
// ---------------------------------------------------------------------------

describe('HR reports', function (): void {
    it('publishes all six §17 asks for', function (): void {
        actingAsRole(RoleName::Finance);

        $slugs = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))
            ->pluck('slug')->all();

        foreach (['payroll', 'staff-payslip', 'commission', 'staff-loan', 'staff-advance', 'staff-fund'] as $slug) {
            expect($slugs)->toContain($slug);
        }
    });

    it('reconciles the staff loan report against the ledger', function (): void {
        actingAsRole(RoleName::Finance);

        // Rows are `data`; columns, totals and summary are `meta` — §15.6's
        // envelope, which the report controller emits for every report.
        $meta = $this->getJson('/api/v1/reports/staff-loan')->assertOk()->json('meta');

        $outstanding = collect($meta['summary'])->firstWhere('label', 'Outstanding')['value'];

        // Every active loan's outstanding, against 7010's own balance.
        $ledger = app(AccountResolver::class)->system(SystemAccountCode::StaffLoanReceivable)
            ->load('balances')->cachedBalance();

        expect($outstanding)->toBe($ledger->toDecimalString());
    });

    it('itemises a payslip rather than totalling it', function (): void {
        finalizedPayrollRun();
        actingAsRole(RoleName::Finance);

        $rows = $this->getJson('/api/v1/reports/staff-payslip')->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();

        // The difference from the Payroll report: the items, not two totals.
        $withAllowances = collect($rows)->first(
            fn (array $r): bool => $r['allowanceDetail'] !== '—',
        );

        expect($withAllowances['allowanceDetail'])->toContain('airtime');
    });

    it('reports what each member has contributed to the fund', function (): void {
        finalizedPayrollRun();
        actingAsRole(RoleName::Finance);

        $response = $this->getJson('/api/v1/reports/staff-fund')->assertOk();

        $contributed = collect($response->json('meta.summary'))
            ->firstWhere('label', 'Contributed')['value'];
        $staff = staffFor('0754000005');

        // 10% of base, withheld from the one run that has happened.
        expect(Money::of($contributed)->isPositive())->toBeTrue();

        $row = collect($response->json('data'))->firstWhere('employeeNumber', $staff->employee_number);
        expect($row['contributed'])
            ->toBe($staff->baseSalary()->percentage(App\Support\Percentage::of('10.000'))->toDecimalString());
    });
});

/**
 * Generates, approves and finalizes a run for one period.
 *
 * All three steps, because the ledger and the recovery counters only move at
 * finalization — a helper that stopped at the draft would be testing
 * arithmetic rather than what the module actually does.
 */
function runPayrollFor(string $period): PayrollRun
{
    $hr = actingAsHr();
    $run = app(App\Domain\Hr\Actions\GeneratePayrollAction::class)->handle($period, $hr);
    app(App\Domain\Hr\Actions\ApprovePayrollAction::class)->handle($run, $hr);

    $finance = actingAsFinance();
    $finalized = app(App\Domain\Hr\Actions\FinalizePayrollAction::class)->handle($run->refresh(), $finance);

    forgetAuthGuards();

    return $finalized->load(['lines.allowances', 'lines.deductions']);
}
