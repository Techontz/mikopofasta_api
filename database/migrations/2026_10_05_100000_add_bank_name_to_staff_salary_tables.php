<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank of an employee's salary account (the bank disbursement file's "bank" column), kept with the account number on the
 * salary information and on each payroll line / salary payment, like the account itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['employee_salaries', 'payroll_items', 'salary_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('bank_name', 50)->nullable()->after('account_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['employee_salaries', 'payroll_items', 'salary_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('bank_name');
            });
        }
    }
};
