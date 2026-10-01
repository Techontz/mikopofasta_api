<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Share of an old-system loan the customer must have repaid before it can be topped up, set by the Super Admin.
     * The default (100) keeps the earlier rule: an old-system loan must be cleared before a new loan.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('legacy_topup_percent', 5, 2)->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('legacy_topup_percent');
        });
    }
};
