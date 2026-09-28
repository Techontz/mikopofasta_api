<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical "File" reports carried over from the old system (e.g. the Kakonko Branch file report for 2022).
 *
 * These are records only: nothing here is a loan, a loan transaction, a payment or a journal entry, so importing them
 * moves no money and changes no balance. The File report shows them next to live loans, flagged with their source.
 *
 * - historical_file_reports: one printed report (branch, year, source document, the TOTAL row as printed).
 * - historical_file_records: one row of that report, exactly as printed (S/No, customer, number, loan, ...).
 * - historical_file_payments: the amount the row shows under each month column (non-zero months only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_file_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60);
            $table->string('title');
            $table->string('branch_name');
            $table->unsignedSmallInteger('year')->index();
            $table->string('source_document');
            $table->json('printed_totals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('historical_file_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_file_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('serial_number');
            $table->string('customer_name');
            $table->string('phone', 30)->nullable();
            $table->decimal('loan_amount', 15, 2);
            $table->string('duration_type', 20)->nullable();
            $table->unsignedSmallInteger('sessions')->nullable();
            $table->decimal('collection', 15, 2);
            $table->decimal('paid_amount', 15, 2);
            $table->decimal('remain_amount', 15, 2);
            $table->date('withdrawal_date')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();

            $table->unique(['historical_file_report_id', 'serial_number'], 'historical_file_records_report_serial_unique');
        });

        Schema::create('historical_file_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_file_record_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['historical_file_record_id', 'year', 'month'], 'historical_file_payments_record_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_file_payments');
        Schema::dropIfExists('historical_file_records');
        Schema::dropIfExists('historical_file_reports');
    }
};
