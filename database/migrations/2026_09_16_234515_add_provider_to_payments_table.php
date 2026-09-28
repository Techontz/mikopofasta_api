<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finance-entered payments name the channel (BANK or MNO) and the provider it came through: a bank or network from the
     * company's own list (Settings → Payment Channels). These are only names — never the company's own bank
     * accounts, which hold company funds. A transaction ID is unique per channel and provider; older rows keep an empty provider.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider', 100)->default('')->after('channel');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['channel', 'provider', 'transaction_id']);
            $table->dropUnique(['channel', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['channel', 'transaction_id']);
            $table->dropUnique(['channel', 'provider', 'transaction_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
    }
};
