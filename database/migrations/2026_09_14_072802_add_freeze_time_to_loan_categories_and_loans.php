<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-borrowing freeze moves from the company (companies.loan_freeze_days) to the loan category.
     *
     *  - loan_categories.freeze_time_days (0 = no freeze), backfilled from the owning company's loan_freeze_days.
     *    companies.loan_freeze_days stays as the default prefilled for NEW categories only.
     *  - loans.freeze_started_at / freeze_days record the freeze event (category length copied at that moment, so later
     *    category edits never rewrite history) and loans.frozen_until becomes a DATETIME.
     *  - Existing date-only frozen_until values were "frozen through that whole day" (whereDate >= today), so they are
     *    kept as END of that day (23:59:59); freeze_started_at is backfilled from closed_at and freeze_days from the company.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('loan_categories', 'freeze_time_days')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->unsignedInteger('freeze_time_days')->default(0)->after('topup_percent');
            });

            DB::table('loan_categories')->orderBy('id')->each(function (object $category): void {
                $days = (int) DB::table('companies')->where('id', $category->company_id)->value('loan_freeze_days');
                if ($days > 0) {
                    DB::table('loan_categories')->where('id', $category->id)->update(['freeze_time_days' => $days]);
                }
            });
        }

        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'freeze_started_at')) {
                $table->dateTime('freeze_started_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('loans', 'freeze_days')) {
                $table->unsignedInteger('freeze_days')->nullable()->after('freeze_started_at');
            }
        });

        if (Schema::getColumnType('loans', 'frozen_until') === 'date') {
            Schema::table('loans', function (Blueprint $table) {
                $table->dateTime('frozen_until')->nullable()->change();
            });

            DB::table('loans')->whereNotNull('frozen_until')->orderBy('id')->each(function (object $loan): void {
                $until = substr((string) $loan->frozen_until, 0, 10).' 23:59:59';
                DB::table('loans')->where('id', $loan->id)->update([
                    'frozen_until' => $until,
                    'freeze_started_at' => $loan->freeze_started_at ?? $loan->closed_at ?? $until,
                    'freeze_days' => $loan->freeze_days ?? (int) DB::table('companies')->where('id', $loan->company_id)->value('loan_freeze_days'),
                ]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::getColumnType('loans', 'frozen_until') !== 'date') {
            Schema::table('loans', function (Blueprint $table) {
                $table->date('frozen_until')->nullable()->change();
            });
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(['freeze_started_at', 'freeze_days'], fn (string $column): bool => Schema::hasColumn('loans', $column))));
        });

        if (Schema::hasColumn('loan_categories', 'freeze_time_days')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropColumn('freeze_time_days');
            });
        }
    }
};
