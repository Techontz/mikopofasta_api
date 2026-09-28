<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings → Dividend Settings (Documents: ACCOUNT OVERVIEW "16. Dividend Account" — Profit → Dividend, split
     * 70% → Principal (reinvestment) / 30% → Shareholders). The split is stored per company; the two percentages must
     * total exactly 100.00 (enforced by the API). Defaults are the documented 30 / 70.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'dividend_shareholder_percent')) {
                $table->decimal('dividend_shareholder_percent', 5, 2)->default(30)->after('reserve_percent');
            }
            if (! Schema::hasColumn('companies', 'dividend_reinvest_percent')) {
                $table->decimal('dividend_reinvest_percent', 5, 2)->default(70)->after('dividend_shareholder_percent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['dividend_shareholder_percent', 'dividend_reinvest_percent']);
        });
    }
};
