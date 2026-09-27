<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Headquarters Transaction now sends money from an HQ fund row to a shareholders' (Investment) account, and that
 * account can be one of several bank accounts — which share one ledger account key, so the row must name the bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hq_transactions', function (Blueprint $table): void {
            $table->foreignId('to_bank_account_id')->nullable()->after('to_account')->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hq_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('to_bank_account_id');
        });
    }
};
