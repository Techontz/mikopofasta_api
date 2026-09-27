<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-borrowing freeze = EARLY FULL SETTLEMENT freeze counted from DISBURSEMENT (see LoanService::recordSettlement()).
     *
     *  - loans.expected_completion_date: maturity snapshot taken at settlement (loans.end_date = the last schedule due
     *    date, falling back to MAX(loan_schedules.due_date)).
     *  - loans.early_settlement: null = not (yet) settled, true = fully settled on a date before the maturity date,
     *    false = settled on/after maturity or closed by a top-up. The settlement moment is loans.closed_at.
     *  - Every closed loan is recomputed with the new rule: early → freeze_started_at = disbursed_at (legacy loans without
     *    it: withdrawn_at 00:00), freeze_days = the category's current freeze_time_days, frozen_until = start + days;
     *    otherwise all freeze fields are cleared. Loans that are not closed lose any freeze started by the old
     *    top-up-threshold rule.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'expected_completion_date')) {
                $table->date('expected_completion_date')->nullable()->after('end_date');
            }
            if (! Schema::hasColumn('loans', 'early_settlement')) {
                $table->boolean('early_settlement')->nullable()->after('closed_at');
            }
        });

        DB::table('loans')->whereNull('closed_at')->update([
            'early_settlement' => null, 'expected_completion_date' => null,
            'freeze_started_at' => null, 'freeze_days' => null, 'frozen_until' => null,
        ]);

        DB::table('loans')->whereNotNull('closed_at')->orderBy('id')->each(function (object $loan): void {
            $maturity = $loan->end_date ?? DB::table('loan_schedules')->where('loan_id', $loan->id)->max('due_date');
            $maturity = $maturity !== null ? substr((string) $maturity, 0, 10) : null;
            $byTopup = DB::table('loans')->where('topup_of_loan_id', $loan->id)->whereNotNull('disbursed_at')->exists();
            $early = ! $byTopup && $maturity !== null && substr((string) $loan->closed_at, 0, 10) < $maturity;
            $start = $loan->disbursed_at ?? ($loan->withdrawn_at !== null ? substr((string) $loan->withdrawn_at, 0, 10).' 00:00:00' : null);
            $days = (int) DB::table('loan_categories')->where('id', $loan->loan_category_id)->value('freeze_time_days');
            $freezes = $early && $days > 0 && $start !== null;

            DB::table('loans')->where('id', $loan->id)->update([
                'expected_completion_date' => $maturity,
                'early_settlement' => $early,
                'freeze_days' => $early ? $days : null,
                'freeze_started_at' => $freezes ? $start : null,
                'frozen_until' => $freezes ? date('Y-m-d H:i:s', strtotime($start.' +'.$days.' days')) : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(['expected_completion_date', 'early_settlement'], fn (string $column): bool => Schema::hasColumn('loans', $column))));
        });
    }
};
