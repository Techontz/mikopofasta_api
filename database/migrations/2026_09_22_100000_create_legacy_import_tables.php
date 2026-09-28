<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy loan data import: bringing the old system's outstanding balances in as opening balances.
 *
 * An import is a file (Loan File, Penalty List or Active Salary Advance) uploaded for one branch. Its rows are staged
 * and validated first and nothing reaches a balance until someone other than the uploader approves it; on approval the
 * rows become real loans, penalties and salary advances flagged as legacy openings, and one balanced opening journal
 * entry is posted for the receivables they bring in.
 *
 * - legacy_imports: one uploaded file, its counts and totals, and who uploaded, submitted, approved or rejected it.
 * - legacy_import_rows: one row of that file, kept exactly as uploaded (`raw`) beside the values read from it, the
 *   customer it matched and how, what was wrong with it, and the record it became once approved.
 *
 * Opening balances are carried on the records themselves, not as payments: a legacy loan keeps the printed Loan Amount
 * in `amount_approved` and what the old system had already collected in `opening_paid_principal`, so the outstanding
 * balance is the printed Remain Amount without inventing a repayment that would show up as cash received today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('module', 20)->index();
            $table->string('loan_status', 20)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->json('totals')->nullable();
            $table->foreignId('uploaded_by')->constrained('employees')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('submitted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('legacy_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legacy_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->string('customer_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_method', 20)->nullable();
            $table->string('status', 20)->default('valid')->index();
            $table->json('messages')->nullable();

            $table->decimal('loan_amount', 15, 2)->nullable();
            $table->decimal('interest', 15, 2)->nullable();
            $table->decimal('total_payable', 15, 2)->nullable();
            $table->decimal('collection', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->decimal('remain_amount', 15, 2)->nullable();
            $table->decimal('penalty_amount', 15, 2)->nullable();
            $table->string('duration_type', 20)->nullable();
            $table->unsignedSmallInteger('sessions')->nullable();
            $table->date('withdrawal_date')->nullable();
            $table->date('penalty_date')->nullable();
            $table->string('loan_status', 20)->nullable();
            $table->json('monthly')->nullable();

            $table->string('fingerprint', 64)->index();
            $table->nullableMorphs('imported');
            $table->timestamps();

            $table->unique(['legacy_import_id', 'row_number'], 'legacy_import_rows_import_row_unique');
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->boolean('is_legacy_opening')->default(false)->after('is_special')->index();
            $table->decimal('opening_paid_principal', 15, 2)->default(0)->after('is_legacy_opening');
            $table->foreignId('legacy_import_row_id')->nullable()->after('opening_paid_principal')->constrained()->nullOnDelete();
        });

        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->boolean('is_legacy_opening')->default(false)->after('status')->index();
            $table->decimal('opening_paid', 15, 2)->default(0)->after('is_legacy_opening');
            $table->foreignId('legacy_import_row_id')->nullable()->after('opening_paid')->constrained()->nullOnDelete();
        });

        // A legacy penalty is a debt of the customer carried over on its own, with no loan of this system behind it.
        Schema::table('penalties', function (Blueprint $table): void {
            $table->dropForeign(['loan_id']);
        });
        Schema::table('penalties', function (Blueprint $table): void {
            $table->foreignId('loan_id')->nullable()->change();
            $table->boolean('is_legacy_opening')->default(false)->after('is_waived')->index();
            $table->foreignId('legacy_import_row_id')->nullable()->after('is_legacy_opening')->constrained()->nullOnDelete();
        });
        Schema::table('penalties', function (Blueprint $table): void {
            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table): void {
            $table->dropForeign(['legacy_import_row_id']);
            $table->dropColumn(['is_legacy_opening', 'legacy_import_row_id']);
        });
        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->dropForeign(['legacy_import_row_id']);
            $table->dropColumn(['is_legacy_opening', 'opening_paid', 'legacy_import_row_id']);
        });
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropForeign(['legacy_import_row_id']);
            $table->dropColumn(['is_legacy_opening', 'opening_paid_principal', 'legacy_import_row_id']);
        });
        Schema::dropIfExists('legacy_import_rows');
        Schema::dropIfExists('legacy_imports');
    }
};
