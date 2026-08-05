<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Enums\ReserveUtilisationPurpose;
use App\Domain\Accounting\Enums\ReserveUtilisationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Month-end close and the Reserve fund — Decision Register D1.
 *
 * D1 moved the reserve off the repayment path. It used to be taken as
 * `Dr Interest Income · Cr Reserve` on every interest collection, at a
 * hardcoded 10%. The client's ruling is that reserve is calculated from
 * REALISED PROFIT during the accounting close, that moving it requires Admin
 * approval, and that it belongs to Headquarters rather than to any branch.
 *
 * That makes the close a real business event with its own record rather than a
 * report you could run twice, which is why `accounting_periods` exists at all:
 * a period that has been closed is a period whose profit has been recognised
 * and whose reserve has been appropriated, and neither may happen twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per YYYY-MM. Created on demand by the close, not seeded
         * ahead of time — a period nobody has closed has nothing to say, and
         * pre-creating twelve rows a year would make "does this period exist"
         * a different question from "has this period been closed".
         */
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->unique();
            $table->enum('status', PeriodStatus::values())->default(PeriodStatus::Open->value);

            /*
             * The figures the close computed, kept on the row rather than
             * recomputed on read. The ledger can always be re-summed, but a
             * closed period must report the numbers it was closed on — if a
             * back-dated entry lands later, the discrepancy is a finding, and
             * recomputing on read would hide exactly that.
             */
            $table->decimal('income_total', 18, 2)->default(0);
            $table->decimal('expense_total', 18, 2)->default(0);
            $table->decimal('realised_profit', 18, 2)->default(0);

            // The reserve rate at the moment of close, and what it produced.
            // Storing the rate means a later change to ReserveSetting cannot
            // silently reinterpret a historical close.
            $table->decimal('reserve_percentage', 6, 3)->default(0);
            $table->decimal('reserve_appropriated', 18, 2)->default(0);

            $table->foreignId('profit_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reserve_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'period']);
        });

        /*
         * The per-branch breakdown behind one close.
         *
         * Kept because reserve is appropriated with a branch dimension even
         * though the Reserve account itself is company-wide: the branch that
         * earned the profit is the branch whose profit the reserve came out
         * of, and commission (§11) is computed per branch from exactly these
         * figures. Without this table, "why is this branch's pool what it is"
         * would have no answer once the period is closed.
         */
        Schema::create('period_branch_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->decimal('income_total', 18, 2)->default(0);
            $table->decimal('expense_total', 18, 2)->default(0);
            // Signed: a branch in loss carries a negative figure, which is what
            // §11's loss-carry-forward rule reads.
            $table->decimal('realised_profit', 18, 2);
            // Never negative: a loss-making branch appropriates no reserve.
            $table->decimal('reserve_appropriated', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['accounting_period_id', 'branch_id'], 'period_branch_results_period_branch_unique');
        });

        /*
         * D1: "Reserve transfers require Admin approval. Branches cannot
         * directly use Reserve funds."
         *
         * A request row rather than a direct posting, for the same reason
         * float transfers are: money moves on approval, so a queue of requests
         * never touches the trial balance. `journal_entry_id` is null until
         * someone with the authority says yes.
         */
        Schema::create('reserve_utilisations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->enum('purpose', ReserveUtilisationPurpose::values());
            $table->decimal('amount', 18, 2);
            $table->string('narrative', 500);

            /*
             * What the release is FOR, not where it posts.
             *
             * Every purpose posts `Dr Reserve · Cr Capital` — the reserve is a
             * control account holding no cash, so a release un-reserves equity
             * rather than moving money (see ReserveUtilisationPurpose). Only
             * opening a branch names one, and it is carried for the audit trail
             * and the utilisation report.
             */
            $table->foreignId('target_branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->enum('status', ReserveUtilisationStatus::values())
                ->default(ReserveUtilisationStatus::Pending->value);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_utilisations');
        Schema::dropIfExists('period_branch_results');
        Schema::dropIfExists('accounting_periods');
    }
};
