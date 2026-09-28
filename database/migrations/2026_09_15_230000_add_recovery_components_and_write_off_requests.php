<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 Option B (write-off recovery by component) and C6 (write-off maker/checker). Additive only:
 *  - write_offs: nullable interest / penalty / insurance snapshots of the loan's outstanding at write-off (principal is
 *    already principal_amount). Existing write-offs keep NULL; their split is derived from loan data or reported ambiguous.
 *  - loan_recoveries: nullable component columns (principal, penalty, interest, reserve, insurance). Existing rows keep NULL
 *    and stay legacy interest-only recoveries. `standing_payment_id` (the payment while the recovery stands, cleared on
 *    reversal) is unique, so one payment can never hold two standing recoveries while a reversed recovery's payment can be
 *    re-allocated. (A MySQL generated column cannot be used: payment_id carries an ON DELETE SET NULL foreign key.)
 *  - write_off_requests: a write-off is requested (pending, nothing posted) and posted only when a different authorised
 *    user approves it; a rejection keeps the row with the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('write_offs', function (Blueprint $table): void {
            $table->decimal('interest_amount', 15, 2)->nullable()->after('principal_amount');
            $table->decimal('penalty_amount', 15, 2)->nullable()->after('interest_amount');
            $table->decimal('insurance_amount', 15, 2)->nullable()->after('penalty_amount');
        });

        Schema::table('loan_recoveries', function (Blueprint $table): void {
            $table->decimal('principal_amount', 15, 2)->nullable()->after('amount');
            $table->decimal('penalty_amount', 15, 2)->nullable()->after('principal_amount');
            $table->decimal('interest_amount', 15, 2)->nullable()->after('penalty_amount');
            $table->decimal('reserve_amount', 15, 2)->nullable()->after('interest_amount');
            $table->decimal('insurance_amount', 15, 2)->nullable()->after('reserve_amount');
            $table->unsignedBigInteger('standing_payment_id')->nullable()->after('payment_id');
            $table->unique('standing_payment_id');
        });

        Schema::create('write_off_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('write_off_id')->nullable()->constrained('write_offs')->nullOnDelete();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('write_off_requests');

        Schema::table('loan_recoveries', function (Blueprint $table): void {
            $table->dropUnique(['standing_payment_id']);
            $table->dropColumn(['standing_payment_id', 'principal_amount', 'penalty_amount', 'interest_amount', 'reserve_amount', 'insurance_amount']);
        });

        Schema::table('write_offs', function (Blueprint $table): void {
            $table->dropColumn(['interest_amount', 'penalty_amount', 'insurance_amount']);
        });
    }
};
