<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §29/§30/§32/§49 — staff loans and staff salary advances move through
     * submitted → hr_approved (or admin_approved for a request benefiting an HR user) → finance_approved → disbursed →
     * repaying → completed (or rejected). `approved_by`/`approved_at` keep recording the review stage (HR or Admin);
     * Finance approval, rejection and completion get their own who/when columns.
     *
     * Existing rows only get their status renamed; amounts, ledger entries and source accounts are untouched.
     */
    public function up(): void
    {
        foreach (['staff_loans', 'staff_salary_advances'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('review_stage')->default('hr')->after('status');
                $table->foreignId('finance_approved_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
                $table->timestamp('finance_approved_at')->nullable()->after('finance_approved_by');
                $table->foreignId('rejected_by')->nullable()->after('disbursed_at')->constrained('employees')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                $table->string('rejection_reason')->nullable()->after('rejected_at');
                $table->timestamp('completed_at')->nullable()->after('rejection_reason');
            });
        }

        DB::table('staff_loans')->where('status', 'pending')->update(['status' => 'submitted']);
        DB::table('staff_loans')->where('status', 'approved')->update(['status' => 'hr_approved']);
        DB::table('staff_loans')->where('status', 'active')->whereExists(fn ($query) => $query->from('staff_loan_payments')->whereColumn('staff_loan_payments.staff_loan_id', 'staff_loans.id'))->update(['status' => 'repaying']);
        DB::table('staff_loans')->where('status', 'active')->update(['status' => 'disbursed']);
        DB::table('staff_loans')->where('status', 'done')->update(['status' => 'completed', 'completed_at' => DB::raw('updated_at')]);

        DB::table('staff_salary_advances')->where('status', 'pending')->update(['status' => 'submitted']);
        DB::table('staff_salary_advances')->where('status', 'approved')->update(['status' => 'hr_approved']);
        DB::table('staff_salary_advances')->where('status', 'disbursed')->where('recovered_amount', '>', 0)->update(['status' => 'repaying']);
        DB::table('staff_salary_advances')->where('status', 'done')->update(['status' => 'completed', 'completed_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('staff_loans')->where('status', 'submitted')->update(['status' => 'pending']);
        DB::table('staff_loans')->whereIn('status', ['hr_approved', 'admin_approved', 'finance_approved'])->update(['status' => 'approved']);
        DB::table('staff_loans')->whereIn('status', ['disbursed', 'repaying'])->update(['status' => 'active']);
        DB::table('staff_loans')->where('status', 'completed')->update(['status' => 'done']);

        DB::table('staff_salary_advances')->where('status', 'submitted')->update(['status' => 'pending']);
        DB::table('staff_salary_advances')->whereIn('status', ['hr_approved', 'admin_approved', 'finance_approved'])->update(['status' => 'approved']);
        DB::table('staff_salary_advances')->where('status', 'repaying')->update(['status' => 'disbursed']);
        DB::table('staff_salary_advances')->where('status', 'completed')->update(['status' => 'done']);

        foreach (['staff_loans', 'staff_salary_advances'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('rejected_by');
                $table->dropConstrainedForeignId('finance_approved_by');
                $table->dropColumn(['review_stage', 'finance_approved_at', 'rejected_at', 'rejection_reason', 'completed_at']);
            });
        }
    }
};
