<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maker/checker for money reversals (user ruling 2026-09-17): Finance requests the reversal of a loan repayment, a loan
 * disbursement or a direct penalty payment; nothing is posted until another Finance user, an Admin or the Super Admin
 * approves it. Also links direct penalty payments to their journal entry and gives them reversal trace columns (a reversed
 * penalty payment is kept, never deleted). Only the new penalty_payments.journal_entry_id column is backfilled, and only
 * where the payment and its PENALTY entry match one to one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reversal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->index();
            $table->morphs('subject');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
        });

        Schema::table('penalty_payments', function (Blueprint $table): void {
            $table->foreignId('journal_entry_id')->nullable()->after('loan_transaction_id')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('journal_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('employees')->nullOnDelete();
            $table->string('reversal_reason')->nullable()->after('reversed_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->nullOnDelete();
        });

        $this->linkDirectPenaltyPayments();
    }

    public function down(): void
    {
        Schema::table('penalty_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        Schema::dropIfExists('reversal_requests');
    }

    /**
     * A direct penalty payment posted one PENALTY entry sourced on its penalty, dated the payment day, for the same amount.
     * Link only unambiguous pairs (one payment and one entry for that penalty, day and amount).
     */
    private function linkDirectPenaltyPayments(): void
    {
        $payments = DB::table('penalty_payments')->whereNull('loan_transaction_id')->whereNull('journal_entry_id')->get(['id', 'penalty_id', 'amount', 'paid_on']);

        foreach ($payments->groupBy(fn (object $row): string => $row->penalty_id.'|'.substr((string) $row->paid_on, 0, 10).'|'.number_format((float) $row->amount, 2, '.', '')) as $group) {
            if ($group->count() !== 1) {
                continue;
            }
            $payment = $group->first();

            $entries = DB::table('journal_entries')
                ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.source_type', 'App\\Models\\Penalty')
                ->where('journal_entries.source_id', $payment->penalty_id)
                ->whereNull('journal_entries.reversal_of_id')
                ->where('journal_entries.description', 'PENALTY')
                ->whereDate('journal_entries.entry_date', substr((string) $payment->paid_on, 0, 10))
                ->groupBy('journal_entries.id')
                ->havingRaw('ABS(SUM(journal_lines.debit) - ?) < 0.005', [(float) $payment->amount])
                ->pluck('journal_entries.id');

            if ($entries->count() === 1) {
                DB::table('penalty_payments')->where('id', $payment->id)->update(['journal_entry_id' => $entries->first()]);
            }
        }
    }
};
