<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loan Discount / Waiver (enhancement §6): a request to reduce an issued loan's outstanding debt waits `pending` until an
 * approver (another user) approves it — only then is the balance reduced (a `discount` loan transaction) and the principal
 * part posted Dr LOAN DISCOUNT / WAIVER EXPENSE / Cr LOAN RECEIVABLE. New permissions: loans.discount_request (Admin,
 * Finance, Branch Manager) and loans.discount_approve (Admin, Finance).
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'loans.discount_request' => ['admin', 'finance', 'branch_manager'],
        'loans.discount_approve' => ['admin', 'finance'],
    ];

    public function up(): void
    {
        Schema::create('loan_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('original_debt', 15, 2);
            $table->decimal('actual_payment', 15, 2)->default(0);
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('loan_transaction_id')->nullable()->unique()->constrained('loan_transactions')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('interest', 15, 2)->default(0);
            $table->decimal('insurance', 15, 2)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['loan_id', 'status']);
        });

        foreach (self::GRANTS as $permission => $roles) {
            foreach (DB::table('roles')->where('is_system', true)->whereIn('key', $roles)->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', array_keys(self::GRANTS))->delete();
        Schema::dropIfExists('loan_discounts');
    }
};
