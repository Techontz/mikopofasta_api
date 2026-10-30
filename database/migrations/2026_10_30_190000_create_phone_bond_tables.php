<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Bond (enhancement §5): devices enrolled with Linda against a loan, and every lock / unlock / debt update asked of
 * Linda with its trigger, reason, actor and Linda's answer. New permissions: phone_bond.manage (register and manage devices;
 * Admin, Branch Manager, Loan Officer) and phone_bond.lock (manual lock / unlock; Admin, Branch Manager, Finance).
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'phone_bond.manage' => ['admin', 'branch_manager', 'loan_officer'],
        'phone_bond.lock' => ['admin', 'branch_manager', 'finance'],
    ];

    public function up(): void
    {
        Schema::create('phone_bond_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('imei', 32);
            $table->string('serial_number', 64)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('linda_device_id', 100)->nullable();
            $table->string('enrollment_status', 20)->default('pending');
            $table->boolean('sim_protection')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('lock_state', 20)->default('unlocked');
            $table->string('closed_reason', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'imei']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('phone_bond_lock_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('phone_bond_devices')->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->string('trigger', 20);
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20);
            $table->string('provider_reference', 100)->nullable();
            $table->string('provider_message', 500)->nullable();
            $table->json('provider_response')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['device_id', 'status']);
            $table->index(['company_id', 'created_at']);
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
        Schema::dropIfExists('phone_bond_lock_requests');
        Schema::dropIfExists('phone_bond_devices');
    }
};
