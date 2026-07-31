<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Services\PayrollCalculator;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StaffProfile;
use App\Support\Money;

beforeEach(function (): void {
    seedStaffBook();
});

describe('generation', function (): void {
    it('produces a draft run with a line per active staff member', function (): void {
        actingAsHr();

        $response = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $expected = StaffProfile::query()->where('employment_status', EmploymentStatus::Active)->count();

        expect($response->json('data.lines'))->toHaveCount($expected)
            ->and($expected)->toBeGreaterThan(0);
    });

    it('posts nothing at all', function (): void {
        $before = JournalEntry::query()->count();

        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()
            // Said in the response, not merely implied by an absent entry.
            ->assertJsonPath('meta.ledgerPosting', 'none — a draft run posts nothing until Finance finalizes it');

        expect(JournalEntry::query()->count())->toBe($before)
            ->and(PayrollLine::query()->whereNotNull('journal_entry_id')->exists())->toBeFalse();
    });

    it('computes each line through the shared calculator', function (): void {
        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        $calculator = app(PayrollCalculator::class);

        foreach (PayrollLine::query()->with('staffProfile.user')->get() as $line) {
            $staff = $line->staffProfile;

            $expected = $calculator->compute(
                staff: $staff,
                commissionAmount: $line->commissionAmount(),
                /*
                 * The entitlement ROWS, not a branch-based flag. Allowances
                 * stopped being constants in Module 7 — every branch employee
                 * drew the same transport figure and no bonus could exist.
                 */
                entitlements: $staff->allowanceEntitlements()->forPeriod(currentPeriod())->get(),
                penalties: $staff->payDeductions()->forPeriod(currentPeriod())->get(),
                // The debts themselves, not flags: each instalment is derived
                // from the record's own terms.
                outstandingLoan: $staff->loans
                    ->filter(fn ($l): bool => $l->status === App\Domain\Hr\Enums\StaffLoanStatus::Active)
                    ->sortBy('id')
                    ->first(),
                outstandingAdvance: $staff->advances
                    ->filter(fn ($a): bool => $a->status === App\Domain\Hr\Enums\StaffAdvanceStatus::Disbursed)
                    ->sortBy('id')
                    ->first(),
            );

            // The runtime path and the engine must agree exactly — one
            // implementation, as §11 requires.
            expect($line->net_salary)->toBe($expected->netSalary->toDecimalString())
                ->and($line->deductions_total)->toBe($expected->deductionsTotal->toDecimalString());
        }
    });

    it('itemises allowances and deductions alongside the totals', function (): void {
        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        foreach (PayrollLine::query()->with(['allowances', 'deductions'])->get() as $line) {
            $allowances = Money::sum($line->allowances->map->amountMoney());
            $deductions = Money::sum($line->deductions->map->amountMoney());

            expect($allowances->toDecimalString())->toBe($line->allowances_total)
                ->and($deductions->toDecimalString())->toBe($line->deductions_total);
        }
    });

    it('references the debt a recovery deduction pays down', function (): void {
        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        // Esther Mollel carries the seeded staff loan.
        $staff = staffFor('0754000005');
        $line = PayrollLine::query()->where('staff_profile_id', $staff->getKey())->sole();

        $recovery = $line->deductions()->where('type', DeductionType::Loan)->sole();

        expect($recovery->reference_id)->toBe($staff->loans()->value('id'))
            ->and($recovery->amount)->toBe('50000.00');
    });

    it('excludes suspended and terminated staff', function (): void {
        $suspended = staffFor('0754000010');
        $suspended->update(['employment_status' => EmploymentStatus::Suspended]);

        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        expect(PayrollLine::query()->where('staff_profile_id', $suspended->getKey())->exists())->toBeFalse();
    });

    it('refuses a second run for the same period', function (): void {
        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        // A second June payroll would pay everyone twice.
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYROLL_PERIOD_EXISTS');

        expect(PayrollRun::query()->count())->toBe(1);
    });

    it('rejects a malformed period', function (): void {
        actingAsHr();

        $this->postJson('/api/v1/payroll/generate', ['period' => 'June 2026'])
            ->assertStatus(422)
            ->assertJsonPath('errors.period.0', 'Period must be in YYYY-MM format.');
    });
});

describe('finalization', function (): void {
    it('posts a recognition entry per employee and marks the run finalized', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()->json('data.id');

        // HR approves first — §16.7, added in Module 7.
        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');

        $run = PayrollRun::query()->with('lines')->findOrFail($runId);

        expect($run->finalized_at)->not->toBeNull()
            ->and($run->lines->every(fn (PayrollLine $l): bool => $l->journal_entry_id !== null))->toBeTrue();
    });

    it('debits salary and commission expense and credits staff payable', function (): void {
        $run = finalizedPayrollRun();
        $accounts = app(AccountResolver::class);

        $line = $run->lines->firstWhere(fn (PayrollLine $l): bool => $l->commissionAmount()->isPositive());

        expect($line)->not->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($line->journal_entry_id);

        $debitOn = fn (SystemAccountCode $c): string => $entry->lines
            ->firstWhere('account_id', $accounts->systemId($c))?->debit_amount ?? '0.00';

        expect($debitOn(SystemAccountCode::SalaryExpense))
            ->toBe($line->baseSalary()->add($line->allowancesTotal())->toDecimalString())
            ->and($debitOn(SystemAccountCode::CommissionExpense))->toBe($line->commission_amount)
            ->and($entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::StaffPayable))?->credit_amount)
            ->toBe($line->grossPay()->toDecimalString())
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('routes each deduction to the account it belongs to', function (): void {
        $run = finalizedPayrollRun();
        $accounts = app(AccountResolver::class);

        // Joseph Mrema carries the seeded advance; Esther Mollel the loan.
        $advanceStaff = staffFor('0754000010');
        $line = $run->lines->firstWhere('staff_profile_id', $advanceStaff->getKey());

        $entry = JournalEntry::query()->with('lines')
            ->where('description', 'like', 'Payroll deductions%')
            ->get()
            ->first(fn (JournalEntry $e): bool => $e->lines->contains('staff_profile_id', $advanceStaff->getKey()));

        $creditOn = fn (SystemAccountCode $c): string => $entry->lines
            ->firstWhere('account_id', $accounts->systemId($c))?->credit_amount ?? '0.00';

        // The advance this payslip recovered, read before asserting against it.
        $advance = $advanceStaff->advances()->orderBy('id')->firstOrFail();

        expect($entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::StaffPayable))?->debit_amount)
            ->toBe($line->deductions_total)
            /*
             * The advance's own instalment, not a flat figure: total repayable
             * (principal + interest + charge fee) over its agreed recovery
             * periods, capped at what is still owed.
             */
            /*
             * An advance instalment splits across two accounts, and this is
             * where that is pinned.
             *
             * 7020 Staff Advance Receivable is credited with the PRINCIPAL
             * portion only — the part that clears what disbursement debited.
             * Crediting the whole instalment there drove the receivable
             * negative by exactly the advance's interest and charge fee, and
             * recognised those charges nowhere; the trial balance still
             * balanced, which is why it went unnoticed.
             */
            ->and($creditOn(SystemAccountCode::StaffAdvanceReceivable))
            ->toBe($advance->amountMoney()->toDecimalString())
            /*
             * 7000 Staff Fund takes the fund contribution AND the advance's
             * charges — §12's revolving fund earns its own profit. One line,
             * not two: this builder promises a reader the total against an
             * account.
             */
            ->and($creditOn(SystemAccountCode::StaffFund))
            ->toBe(
                $advanceStaff->baseSalary()->percentage(App\Support\Percentage::of('10.000'))
                    ->add($advance->interestMoney())
                    ->add($advance->chargeFeeMoney())
                    ->toDecimalString(),
            )
            // And the two portions still sum to the instalment that was taken.
            ->and(
                $advance->amountMoney()
                    ->add($advance->interestMoney())
                    ->add($advance->chargeFeeMoney())
                    ->toDecimalString(),
            )
            ->toBe($line->deductions->firstWhere('type', DeductionType::Advance)?->amount)
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('leaves the book balanced', function (): void {
        finalizedPayrollRun();

        $tb = app(TrialBalanceBuilder::class)->build();

        expect($tb['balanced'])->toBeTrue()
            ->and(JournalEntry::query()->with('lines')->get()->reject(fn (JournalEntry $e): bool => $e->isBalanced()))
            ->toBeEmpty();
    });

    it('refuses to finalize a run twice', function (): void {
        $run = finalizedPayrollRun();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_PAYROLL_STATE');
    });
});

describe('payment', function (): void {
    it('settles staff payable against the bank', function (): void {
        $run = finalizedPayrollRun();
        $accounts = app(AccountResolver::class);

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $payment = JournalEntry::query()->with('lines')
            ->where('description', 'like', 'Salary payment%')
            ->latest('id')->firstOrFail();

        expect($payment->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::StaffPayable))?->debit_amount)
            ->not->toBe('0.00')
            ->and($payment->lines->firstWhere('account_id', $accounts->defaultBankAccount()->getKey()))
            ->not->toBeNull();
    });

    it('nets staff payable to zero once the run is paid', function (): void {
        $run = finalizedPayrollRun();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")->assertOk();

        $rows = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

        /*
         * Recognition credits Staff Payable, deductions and payment debit it.
         * A residual balance would mean the company still owes an employee
         * money it believes it has paid — which is the entire reason the three
         * entries are separate rather than one.
         */
        expect($rows[SystemAccountCode::StaffPayable->value]['balance'])->toBe('0.00');
    });

    it('refuses to pay a draft run', function (): void {
        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()->json('data.id');

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/pay")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_PAYROLL_STATE');
    });

    it('keeps the book balanced through the whole cycle', function (): void {
        $run = finalizedPayrollRun();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")->assertOk();

        expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
    });

    it('skips an employee whose deductions consumed their salary', function (): void {
        /*
         * Base 10,000 plus 70,000 of allowances, against a 50,000 loan
         * recovery, a 50,000 advance recovery and the fund contribution —
         * 101,000 out against 80,000 in. Nothing is owed this month.
         */
        $staff = staffFor('0754000005');
        $staff->update(['base_salary' => '10000.00']);
        $staff->advances()->create([
            'amount' => '100000.00',
            'status' => App\Domain\Hr\Enums\StaffAdvanceStatus::Disbursed,
            'requested_at' => now(),
        ]);

        $run = finalizedPayrollRun();
        $line = $run->lines->firstWhere('staff_profile_id', $staff->getKey());

        expect($line->netSalary()->isPositive())->toBeFalse();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")->assertOk();

        // No payment entry for them — a journal line must carry a positive
        // amount, and there is nothing to pay.
        $paid = JournalEntry::query()->with('lines')
            ->where('description', 'like', 'Salary payment%')
            ->get()
            ->filter(fn (JournalEntry $e): bool => $e->lines->contains('staff_profile_id', $staff->getKey()));

        expect($paid)->toBeEmpty()
            ->and(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
    });
});

describe('separation of duties', function (): void {
    it('lets HR generate but never finalize or pay', function (): void {
        actingAsHr();

        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()->json('data.id');

        // §14: "HR can generate payroll but not finalize/pay it (Finance does)."
        $this->postJson("/api/v1/payroll/{$runId}/finalize")->assertForbidden();
        $this->postJson("/api/v1/payroll/{$runId}/pay")->assertForbidden();
    });

    it('lets Finance finalize and pay but never generate', function (): void {
        actingAsFinance();

        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertForbidden();

        actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()->json('data.id');

        // HR approves first — §16.7, added in Module 7.
        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/finalize")->assertOk();
        $this->postJson("/api/v1/payroll/{$runId}/pay")->assertOk();
    });

    it('denies payroll entirely to a branch role', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/payroll')->assertForbidden();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertForbidden();
    });

    it('has no endpoint that posts a payroll entry directly', function (): void {
        actingAsFinance();

        // Payroll reaches the ledger through finalization and payment, and
        // LedgerService remains the only writer (§5).
        $this->postJson('/api/v1/payroll', [])->assertStatus(405);
    });
});

describe('audit logging', function (): void {
    it('records generation, finalization and payment as three separate acts', function (): void {
        $run = finalizedPayrollRun();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")->assertOk();

        foreach ([AuditAction::PayrollGenerated, AuditAction::PayrollFinalized, AuditAction::PayrollPaid] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->exists())->toBeTrue();
        }
    });

    it('records that generation posted nothing', function (): void {
        actingAsHr();
        $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::PayrollGenerated->value)->latest('id')->firstOrFail();

        expect($log->after_json['ledger_posting'])->toContain('none');
    });

    it('attributes generation to HR and finalization to Finance', function (): void {
        $hr = actingAsHr();
        $runId = $this->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payroll/{$runId}/approve")->assertOk();

        $finance = actingAsFinance();
        $this->postJson("/api/v1/payroll/{$runId}/finalize")->assertOk();

        $generated = AuditLog::query()->where('action', AuditAction::PayrollGenerated->value)->latest('id')->firstOrFail();
        $finalized = AuditLog::query()->where('action', AuditAction::PayrollFinalized->value)->latest('id')->firstOrFail();

        // Two different people, which is the point of the control.
        expect($generated->user_id)->toBe($hr->getKey())
            ->and($finalized->user_id)->toBe($finance->getKey())
            ->and($generated->user_id)->not->toBe($finalized->user_id);
    });
});
