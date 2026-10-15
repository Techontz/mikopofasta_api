<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM becomes its own department (user ruling 2026-10-15):
 *
 * - a system CRM role for every company, holding the permissions config/permissions.php gives it;
 * - KYC approval (customers.approve) leaves the Branch Manager: only Admin, Super Admin and CRM approve registrations;
 * - customers.approve_updates (CRM and Admin): changes branch staff make to a registered customer wait in
 *   customer_update_requests until CRM approves them; CRM's and Admin's own changes are recorded there as already approved;
 * - CRM cases are either a complaint or a customer request (crm_tickets.type), existing ones classified by their category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_update_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->json('changes');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::table('crm_tickets', function (Blueprint $table): void {
            $table->string('type', 20)->default('complaint')->after('ticket_number');
        });
        DB::table('crm_tickets')->where('category', 'request')->update(['type' => 'request']);

        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('roles')->insertOrIgnore(['company_id' => $companyId, 'key' => 'crm', 'name' => 'CRM', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('roles')->where('is_system', true)->where('key', 'crm')->pluck('id') as $roleId) {
            foreach (config('permissions.roles.crm.permissions') as $permission) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
        foreach (DB::table('roles')->where('is_system', true)->where('key', 'admin')->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'customers.approve_updates']);
        }
        DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->where('is_system', true)->where('key', 'branch_manager')->select('id'))
            ->where('permission', 'customers.approve')
            ->delete();
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->where('key', 'branch_manager')->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'customers.approve']);
        }
        DB::table('role_permissions')->where('permission', 'customers.approve_updates')->delete();
        // A CRM role someone still holds is kept, so rolling back never leaves an employee without a role.
        $crmRoles = DB::table('roles')->where('is_system', true)->where('key', 'crm')->whereNotIn('id', DB::table('employees')->whereNotNull('role_id')->select('role_id'))->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $crmRoles)->delete();
        DB::table('roles')->whereIn('id', $crmRoles)->delete();

        Schema::table('crm_tickets', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
        Schema::dropIfExists('customer_update_requests');
    }
};
