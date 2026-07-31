<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary Advance — categories, and the terms an advance is agreed on.
 * See docs/modules/salary-advance.md.
 *
 * `staff_advances` already existed with the §11 lifecycle on it (request → HR
 * approval → Finance disbursement) but recorded only an amount. The six legacy
 * Salary Advance screens show a good deal more: a category, an interest figure
 * in money, a charge fee, what has been repaid and what remains.
 *
 * The missing piece was the category, and it is not cosmetic. It is what
 * decides how much an advance costs and how long it runs — and, in the code as
 * it stood, nothing did: `PayrollCalculator::RECOVERY_PER_PERIOD` recovered a
 * flat 50,000 a month from everyone, and its own docblock admits the figure was
 * picked rather than derived ("§11 says an advance is recovered automatically
 * from payroll without giving a schedule, and the frontend picked one number").
 *
 * With a category, the schedule is derived: the advance carries its own terms
 * and its own running recovery, so payroll can ask it what it needs this month
 * and stop when it is clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The bands an advance is priced by — Salary Advance → Salary Advance
         * Category, and HRM → Staff salary advance category (the legacy menu
         * reaches the same screen from two places).
         */
        Schema::create('salary_advance_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            /*
             * Percent of the principal. DECIMAL(6,3) matches every other rate
             * column in this schema, so 12.5% survives the round trip.
             */
            $table->decimal('interest_rate', 6, 3)->default(0);

            /*
             * The amount band this category applies to. A request picks its
             * category by amount, so the bands are what make that automatic
             * rather than a choice the requester can get wrong.
             */
            $table->decimal('from_amount', 18, 2);
            $table->decimal('to_amount', 18, 2);

            // A flat processing charge, separate from interest.
            $table->decimal('charge_fee', 18, 2)->default(0);

            /*
             * How many payroll periods the advance is recovered over.
             *
             * This is the column that replaces the hardcoded 50,000: the
             * per-period recovery is the total repayable divided across these,
             * so a bigger advance takes bigger bites rather than the same bite
             * for longer. One is valid — the whole thing off the next payslip.
             */
            $table->unsignedSmallInteger('recovery_periods')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One live name, the same generated-marker trick expense_categories
            // uses — MySQL treats NULLs in a unique index as distinct, so
            // indexing deleted_at directly would constrain nothing.
            $table->string('deleted_marker', 30)->virtualAs("COALESCE(CAST(deleted_at AS CHAR), 'live')");
            $table->unique(['name', 'deleted_marker']);
            $table->index(['from_amount', 'to_amount']);
        });

        Schema::table('staff_advances', function (Blueprint $table): void {
            // ADV-0000001. The legacy screens print a reference on every row.
            $table->string('reference', 20)->nullable()->after('id');

            /*
             * Nullable, because advances predating this module have no
             * category and inventing one for them would put terms on an
             * agreement nobody made. They recover on their principal alone.
             */
            $table->foreignId('salary_advance_category_id')->nullable()->after('staff_profile_id')
                ->constrained('salary_advance_categories')->restrictOnDelete();

            /*
             * The terms, snapshotted at request — the same rule loans follow.
             * Re-pricing a category must not rewrite an advance already agreed.
             *
             * `interest_amount` is money, not a rate: the legacy screens print
             * "Interest" as a figure beside the principal, and storing the
             * money means the row cannot disagree with itself if the category
             * is later re-rated.
             */
            $table->decimal('interest_amount', 18, 2)->default(0)->after('amount');
            $table->decimal('charge_fee', 18, 2)->default(0)->after('interest_amount');
            $table->unsignedSmallInteger('recovery_periods')->default(1)->after('charge_fee');

            /*
             * What has been recovered so far, accumulated by payroll.
             *
             * Stored rather than summed from `deductions` because an advance
             * has to know when it is finished without scanning every payroll
             * run ever generated — and because the deduction rows are keyed by
             * a loose `reference_id` that carries no foreign key.
             */
            $table->decimal('amount_recovered', 18, 2)->default(0)->after('recovery_periods');

            $table->timestamp('recovered_at')->nullable()->after('disbursed_at');
            $table->string('rejection_reason', 255)->nullable()->after('recovered_at');

            /*
             * When recovery should be complete — disbursement plus the
             * category's periods. Null until disbursed, because the clock
             * starts when the money leaves. Drives the Alert column's
             * "overdue days" on the Active screen.
             */
            $table->date('due_date')->nullable()->after('rejection_reason');

            $table->softDeletes();

            $table->index('reference');
            $table->index(['status', 'due_date']);
        });

        // Backfill so `reference` can be made unique and non-null.
        foreach (Illuminate\Support\Facades\DB::table('staff_advances')->whereNull('reference')->orderBy('id')->pluck('id') as $id) {
            Illuminate\Support\Facades\DB::table('staff_advances')
                ->where('id', $id)
                ->update(['reference' => 'ADV-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT)]);
        }

        Schema::table('staff_advances', function (Blueprint $table): void {
            $table->string('reference', 20)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('staff_advances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('salary_advance_category_id');
            $table->dropUnique(['reference']);
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn([
                'reference', 'interest_amount', 'charge_fee', 'recovery_periods',
                'amount_recovered', 'recovered_at', 'rejection_reason', 'due_date', 'deleted_at',
            ]);
        });

        Schema::dropIfExists('salary_advance_categories');
    }
};
