<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM employee registration (enhancement §12.1–12.2): CV, certificates and employment contract (PDF) kept per employee, and
 * employee KYC — a new employee's details are `pending` until an HR user approves them; once approved, only HR may change
 * them (never the employee), with a reason and the old and new values audited. Staff already working are marked approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('kyc_status', 20)->default('pending')->after('status');
            $table->foreignId('kyc_decided_by')->nullable()->after('kyc_status')->constrained('employees')->nullOnDelete();
            $table->timestamp('kyc_decided_at')->nullable()->after('kyc_decided_by');
            $table->string('kyc_rejection_reason', 1000)->nullable()->after('kyc_decided_at');
        });
        DB::table('employees')->update(['kyc_status' => 'approved']);

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('file_path');
            $table->string('original_name', 191);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('kyc_decided_by');
            $table->dropColumn(['kyc_status', 'kyc_decided_at', 'kyc_rejection_reason']);
        });
    }
};
