<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic penalties name the instalment they were charged on, with a unique key, so the overdue job can run any number
 * of times (or concurrently) without charging the same missed instalment twice. Existing automatic penalties are linked
 * to their instalment (same loan, penalty date = due date) where that match is unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table): void {
            $table->foreignId('loan_schedule_id')->nullable()->after('loan_id')->constrained('loan_schedules')->nullOnDelete();
            $table->unique('loan_schedule_id');
        });

        $matches = DB::table('penalties')
            ->join('loan_schedules', fn ($join) => $join->on('loan_schedules.loan_id', '=', 'penalties.loan_id')->on('loan_schedules.due_date', '=', 'penalties.penalty_date'))
            ->whereNotNull('penalties.loan_id')
            ->where(fn ($query) => $query->whereNull('penalties.is_legacy_opening')->orWhere('penalties.is_legacy_opening', false))
            ->get(['penalties.id as penalty_id', 'loan_schedules.id as schedule_id']);

        $schedulesPerPenalty = $matches->groupBy('penalty_id');
        $penaltiesPerSchedule = $matches->groupBy('schedule_id');
        foreach ($matches as $match) {
            if ($schedulesPerPenalty[$match->penalty_id]->count() === 1 && $penaltiesPerSchedule[$match->schedule_id]->count() === 1) {
                DB::table('penalties')->where('id', $match->penalty_id)->update(['loan_schedule_id' => $match->schedule_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table): void {
            $table->dropUnique(['loan_schedule_id']);
            $table->dropConstrainedForeignId('loan_schedule_id');
        });
    }
};
