<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical "Penalty" reports carried over from the old system (printed as "PENARTY REPORT", one per branch).
 *
 * Like the historical File reports, these are records only: nothing here is a penalty of this system, so importing
 * them charges nobody, moves no money and changes no balance. The Report → Penalty page shows them next to the live
 * penalties, flagged with their source.
 *
 * - historical_penalty_reports: one printed report (branch, source document, the TOTAL row as printed).
 * - historical_penalty_records: one row of that report, exactly as printed (S/No, customer, loan amount, penalty, date).
 *
 * A penalty amount may be negative: the old system printed a waiver or a correction as a negative "Penart Amount",
 * and those are kept as printed. A customer name may be blank where the printout left it blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_penalty_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60);
            $table->string('title');
            $table->string('branch_name');
            $table->string('source_document');
            $table->date('printed_on')->nullable();
            $table->decimal('printed_total', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('historical_penalty_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_penalty_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('serial_number');
            $table->string('customer_name')->nullable();
            $table->string('branch_name');
            $table->decimal('loan_amount', 15, 2);
            $table->decimal('penalty_amount', 15, 2);
            $table->date('penalty_date')->nullable();
            $table->timestamps();

            $table->unique(['historical_penalty_report_id', 'serial_number'], 'historical_penalty_records_report_serial_unique');
            $table->index('penalty_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_penalty_records');
        Schema::dropIfExists('historical_penalty_reports');
    }
};
