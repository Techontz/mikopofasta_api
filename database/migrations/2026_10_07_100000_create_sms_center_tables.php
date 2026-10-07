<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS centre: message templates (automatic ones — payment receipt, repayment reminder, overdue reminder — and
     * announcement drafts), contact groups of free phone numbers, and the category / status / reference of every SMS
     * so the log can be filtered and an automatic reminder is never sent twice. sms.manage goes to the Admin role
     * (Super Admin holds it through "*").
     */
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key')->nullable();
            $table->string('type', 20);
            $table->string('name');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('days')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('sms_contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_contact_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_contact_group_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20);
            $table->timestamps();
            $table->unique(['sms_contact_group_id', 'phone']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('message');
            $table->string('status', 10)->default('sent')->after('category');
            $table->string('error')->nullable()->after('status');
            $table->string('reference')->nullable()->after('error');
            $table->foreignId('employee_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->index(['company_id', 'reference']);
        });

        $roles = DB::table('roles')->where('is_system', true)->where('key', 'admin')->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'sms.manage']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'sms.manage')->delete();

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'reference']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['category', 'status', 'error', 'reference']);
        });

        Schema::dropIfExists('sms_contact_group_members');
        Schema::dropIfExists('sms_contact_groups');
        Schema::dropIfExists('sms_templates');
    }
};
