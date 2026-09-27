<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rule 6 (segregation of duties): internal money movements and capital contributions are initiated as PENDING and
     * posted only when a different authorised user approves them. Additive, nullable columns only — existing rows keep
     * their values (legacy rows without an initiator stay approvable by anyone authorised). `capitals.status` is a new
     * metadata column whose default marks every existing contribution as posted (they all carry their journal).
     */
    public function up(): void
    {
        Schema::table('float_transfers', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('capitals', function (Blueprint $table) {
            $table->string('status', 20)->default('posted')->after('idempotency_key')->index();
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('staff_loans', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
        });

        Schema::table('staff_salary_advances', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_salary_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
        });

        Schema::table('staff_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
        });

        Schema::table('capitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'approved_at', 'rejected_at', 'rejection_reason']);
        });

        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['approved_at', 'rejected_at', 'rejection_reason']);
        });

        Schema::table('float_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
