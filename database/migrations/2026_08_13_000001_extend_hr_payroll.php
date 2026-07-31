<?php

declare(strict_types=1);

use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR & Payroll — the parts §2.9 left as stubs.
 * See docs/modules/hr-payroll.md.
 *
 * Four changes, each closing something the HR document specifies and the schema
 * could not express.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendStaffLoans();
        $this->createStandingAllowances();
        $this->createStandingDeductions();
        $this->addPayrollApproval();
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_deductions');
        Schema::dropIfExists('staff_allowances');

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['approved_at', 'paid_at']);
        });

        DB::statement(
            "ALTER TABLE payroll_runs MODIFY status ENUM('draft','finalized','paid') NOT NULL DEFAULT 'draft'",
        );

        Schema::table('staff_loans', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropColumn([
                'reference', 'amount_recovered', 'recovery_periods', 'requested_at',
                'approved_at', 'rejection_reason', 'closed_at',
            ]);
        });
    }

    /**
     * Staff loans get terms, a lifecycle and a recovery counter.
     *
     * ## The defect this exists to fix
     *
     * A staff loan had an amount and a status, and nothing else. Payroll
     * deducted a flat 50,000 from anyone with an `active` loan, and
     * `StaffLoanStatus::Closed` was assigned nowhere in the codebase — so the
     * loan never finished. Twelve simulated runs against the seeded 500,000
     * loan cleared it at the ninth and then kept going: Staff Loan Receivable
     * reached −150,000, asserting the company owed the employee money it did
     * not, while the trial balance stayed balanced throughout because both
     * sides of each entry moved together.
     *
     * That is the salary-advance bug of Module 5, unfixed for loans. The
     * columns below are what let it be fixed: `amount_recovered` so progress is
     * known, `recovery_periods` so the instalment is derived from what was
     * borrowed rather than picked, and the request/approval fields so a loan is
     * agreed the way §16.7–16.8 require rather than appearing from a seeder.
     */
    private function extendStaffLoans(): void
    {
        Schema::table('staff_loans', function (Blueprint $table): void {
            /*
             * Not NOT NULL yet: existing rows have none, and the reference
             * generator runs in PHP. Backfilled below, then constrained.
             */
            $table->string('reference', 30)->nullable()->after('id');

            $table->decimal('amount_recovered', 18, 2)->default(0)->after('amount');

            /*
             * How many payslips the loan is recovered over.
             *
             * A staff loan has no category to derive terms from — the document
             * describes the ledger movement (§14) and nothing about pricing —
             * so the term is agreed per loan at request time instead of being
             * looked up. Default 10, which is what the retired flat 50,000
             * happened to imply for the seeded 500,000 loan; it is a starting
             * value in a form, not a rule.
             */
            $table->unsignedSmallInteger('recovery_periods')->default(10)->after('amount_recovered');

            /*
             * §16.7 "Malipo yote HR ata-approval", §16.8 "disbursement zote
             * zitafanyika finance" — the same separation staff advances carry.
             * A loan is requested, approved by HR, disbursed by Finance.
             */
            $table->timestamp('requested_at')->nullable()->after('status');
            $table->foreignId('requested_by')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('approved_by')->nullable()->after('requested_by')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');

            $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('closed_at')->nullable()->after('disbursed_by');
        });

        /*
         * `disbursed_at` and `journal_entry_id` were NOT NULL, which encoded
         * "a loan exists only once money has moved". A requested loan has moved
         * none, so both become nullable — the same shape `staff_advances`
         * already has, and for the same reason.
         */
        Schema::table('staff_loans', function (Blueprint $table): void {
            $table->date('disbursed_at')->nullable()->change();
            $table->foreignId('journal_entry_id')->nullable()->change();
        });

        // Existing rows predate the lifecycle: they were seeded already
        // disbursed, so that is the state they are given.
        DB::table('staff_loans')->whereNull('requested_at')->update([
            'requested_at' => DB::raw('created_at'),
            'approved_at' => DB::raw('created_at'),
        ]);

        foreach (DB::table('staff_loans')->whereNull('reference')->pluck('id') as $index => $id) {
            DB::table('staff_loans')->where('id', $id)->update([
                'reference' => sprintf('SL-%06d', $index + 1),
            ]);
        }

        Schema::table('staff_loans', function (Blueprint $table): void {
            $table->string('reference', 30)->nullable(false)->change();
            $table->unique('reference');
        });

        /*
         * The status column gains the lifecycle states. Existing rows are all
         * `active` — seeded as already disbursed — so nothing needs remapping;
         * the widening is purely additive.
         */
        DB::statement(sprintf(
            "ALTER TABLE staff_loans MODIFY status ENUM(%s) NOT NULL DEFAULT '%s'",
            implode(',', array_map(static fn (string $v): string => "'{$v}'", StaffLoanStatus::values())),
            StaffLoanStatus::Requested->value,
        ));
    }

    /**
     * Allowances an employee draws, rather than constants in the calculator.
     *
     * `PayrollCalculator` held TRANSPORT_ALLOWANCE and AIRTIME_ALLOWANCE as
     * class constants, which meant two things: every branch employee drew
     * exactly the same transport figure, and `AllowanceType::Bonus` — present
     * in the enum, the `allowances` table and the frontend — was unreachable.
     * Nothing in the system could ever award a bonus, which is the one
     * allowance a manager actually needs to decide.
     *
     * This table is what an employee is *entitled to*; `allowances` stays what
     * a payslip actually *paid*, and payroll copies the former into the latter.
     * Keeping them apart is what lets a rate change next month without
     * rewriting last month's payslip.
     */
    private function createStandingAllowances(): void
    {
        Schema::create('staff_allowances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->enum('type', AllowanceType::values());
            $table->decimal('amount', 18, 2);

            /*
             * NULL means recurring — drawn every month until stood down.
             * A period means this month only, which is what a bonus is: §10
             * lists it beside transport and airtime, but a bonus that repeated
             * silently every month would be a salary increase nobody approved.
             */
            $table->string('period', 7)->nullable();

            $table->string('reason', 200)->nullable();
            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_profile_id', 'active']);
            $table->index('period');

            /*
             * One live recurring allowance of each type per employee.
             *
             * Two active transport allowances would both be copied onto the
             * payslip and the employee would draw it twice — an error nobody
             * would notice, because each row looks correct on its own.
             *
             * Same generated-column technique as `notification_templates`, and
             * for the same reason: MySQL's NULL-distinctness leaves every
             * non-live row unconstrained. One-off rows carry a period, so they
             * sit outside it too — several bonuses in a month are legitimate,
             * and each was decided separately.
             */
            $table->string('uniqueness_marker', 10)
                ->virtualAs(
                    "CASE WHEN deleted_at IS NULL AND active = 1 AND period IS NULL THEN 'live' ELSE NULL END",
                )
                ->nullable();

            $table->unique(['staff_profile_id', 'type', 'uniqueness_marker'], 'staff_allowances_recurring_unique');
        });
    }

    /**
     * Deductions decided by a person, rather than derived by the engine.
     *
     * The Staff Fund contribution, loan recovery and advance recovery are all
     * computed — they follow from a rate or a balance, and payroll works them
     * out. `DeductionType::Penalty` is the one that cannot be: §11 lists it,
     * `creditAccount()` maps it, the frontend renders it, and no code path
     * could ever create one because a penalty is somebody's decision.
     *
     * Deliberately period-scoped and never recurring. A recurring penalty is a
     * salary cut, and it should be made as one — by changing the base salary,
     * where it is visible on the employee's profile rather than buried in a
     * deduction row.
     */
    private function createStandingDeductions(): void
    {
        Schema::create('staff_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->enum('type', DeductionType::values());
            $table->decimal('amount', 18, 2);
            $table->string('period', 7);
            $table->string('reason', 200);

            $table->foreignId('created_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_profile_id', 'period']);
        });
    }

    /**
     * The approval step between HR's draft and Finance's posting.
     *
     * §16.1 is the reason it exists: "Salary haiwezi kubadilishwa baada ya
     * approval" — salary cannot be changed after approval. That sentence needs
     * a moment at which approval happened, and the run had none: HR generated a
     * draft that could be regenerated at will and Finance posted it, so there
     * was no point at which the figures became the agreed figures.
     *
     * §16.7 and §16.8 name who does which: HR approves, Finance disburses. The
     * run now carries four states — draft, approved, finalized, paid — and the
     * enum's own docblock explains what each is for.
     */
    private function addPayrollApproval(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('generated_by')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->foreignId('finalized_by')->nullable()->after('finalized_at')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('paid_by')->nullable()->after('finalized_by')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });

        // The enum gains `approved`; MySQL needs the column redefined.
        DB::statement(sprintf(
            "ALTER TABLE payroll_runs MODIFY status ENUM(%s) NOT NULL DEFAULT '%s'",
            implode(',', array_map(static fn (string $v): string => "'{$v}'", PayrollRunStatus::values())),
            PayrollRunStatus::Draft->value,
        ));

        /*
         * A run that was already finalized was approved implicitly — Finance
         * would not have posted figures nobody agreed. Stamping the time
         * without an actor is the honest record: the moment is knowable, the
         * person is not, and inventing one would put a name against a decision
         * they never made.
         */
        DB::table('payroll_runs')
            ->whereNull('approved_at')
            ->whereNotNull('finalized_at')
            ->update(['approved_at' => DB::raw('finalized_at')]);
    }
};
