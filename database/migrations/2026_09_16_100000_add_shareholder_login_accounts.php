<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shareholder login accounts reuse the `employees` authentication table (Sanctum):
     *  - employees.account_type: 'staff' (every existing row) or 'shareholder' (a portal-only login);
     *  - employees.must_change_password: forces a password change after a temporary password (false for existing rows);
     *  - share_holders.employee_id: the 1:1 link to the login (unique, ON DELETE RESTRICT — financial history never cascades);
     *  - capitals.source / cancelled_at / cancelled_by: contributions submitted from the Shareholder Portal and their cancellation.
     * Additive only: no existing value changes.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('account_type', 20)->default('staff')->after('status')->index();
            $table->boolean('must_change_password')->default(false)->after('password');
        });

        Schema::table('share_holders', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->after('company_id')->constrained('employees')->restrictOnDelete();
        });

        Schema::table('capitals', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('rejection_reason');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['source', 'cancelled_at']);
        });

        Schema::table('share_holders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['account_type']);
            $table->dropColumn(['account_type', 'must_change_password']);
        });
    }
};
