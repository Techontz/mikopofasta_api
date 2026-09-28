<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second part of the legacy loan data import (see 2026_09_22_100000_create_legacy_import_tables).
 *
 * - legacy_imports can be rolled back (safe recovery): who did it, when and why, kept beside the approval.
 * - legacy_import_rows keep the salary advance's "Carger" (fee) and "Date Alert", and who mapped an unmatched row.
 * - salary_advances.salary_advance_category_id becomes nullable: an advance carried over from the old system was issued
 *   under that system's terms, not under one of this system's categories, and must not borrow a category's rate.
 * - legacy_imports.manage (export, upload, submit) goes to Finance and Admin, legacy_imports.approve (approve, reject,
 *   map unmatched customers, roll back) to Admin. Super Admin holds both through "*".
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'legacy_imports.manage' => ['finance', 'admin'],
        'legacy_imports.approve' => ['admin'],
    ];

    public function up(): void
    {
        Schema::table('legacy_imports', function (Blueprint $table): void {
            $table->foreignId('rolled_back_by')->nullable()->after('journal_entry_id')->constrained('employees')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable()->after('rolled_back_by');
            $table->string('rollback_reason', 500)->nullable()->after('rolled_back_at');
        });

        Schema::table('legacy_import_rows', function (Blueprint $table): void {
            $table->decimal('fee', 15, 2)->nullable()->after('penalty_amount');
            $table->date('alert_date')->nullable()->after('penalty_date');
            $table->foreignId('mapped_by')->nullable()->after('match_method')->constrained('employees')->nullOnDelete();
        });

        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->dropForeign(['salary_advance_category_id']);
        });
        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->foreignId('salary_advance_category_id')->nullable()->change();
            $table->foreign('salary_advance_category_id')->references('id')->on('salary_advance_categories')->cascadeOnDelete();
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

        Schema::table('legacy_import_rows', function (Blueprint $table): void {
            $table->dropForeign(['mapped_by']);
            $table->dropColumn(['fee', 'alert_date', 'mapped_by']);
        });

        Schema::table('legacy_imports', function (Blueprint $table): void {
            $table->dropForeign(['rolled_back_by']);
            $table->dropColumn(['rolled_back_by', 'rolled_back_at', 'rollback_reason']);
        });
    }
};
