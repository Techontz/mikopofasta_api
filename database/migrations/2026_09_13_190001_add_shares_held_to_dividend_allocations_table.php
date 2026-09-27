<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dividends are split by share-register ownership: each allocation records the shares the shareholder held and
     * the total issued shares on the declaration date. Existing rows (contribution-based) keep null.
     */
    public function up(): void
    {
        Schema::table('dividend_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('shares_held')->nullable()->after('share_holder_id');
            $table->unsignedBigInteger('total_shares')->nullable()->after('shares_held');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dividend_allocations', function (Blueprint $table) {
            $table->dropColumn(['shares_held', 'total_shares']);
        });
    }
};
