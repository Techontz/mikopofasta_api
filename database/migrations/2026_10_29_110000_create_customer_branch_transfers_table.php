<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer branch transfer (enhancement §2.3): one employee requests moving a customer to another branch, another approves.
 * Approval moves the customer, their open loans and unpaid penalties and reclassifies the branch balances; history stays
 * with the old branch. New permissions: customers.transfer (Admin, Branch Manager, CRM) and customers.approve_transfer (Admin).
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'customers.transfer' => ['admin', 'branch_manager', 'crm'],
        'customers.approve_transfer' => ['admin'],
    ];

    public function up(): void
    {
        Schema::create('customer_branch_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->json('moved')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['customer_id', 'status']);
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
        Schema::dropIfExists('customer_branch_transfers');
    }
};
