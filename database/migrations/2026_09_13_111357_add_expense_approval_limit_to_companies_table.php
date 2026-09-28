<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch expenses up to this amount are approved by Finance; above it by Admin.
     * Inferred: the Documents give no figure, so the default (500,000) is configurable per company.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('expense_approval_limit', 15, 2)->default(500000)->after('reserve_percent');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('expense_approval_limit');
        });
    }
};
