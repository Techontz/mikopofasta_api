<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teller → Bank Deposit (enhancement §8.3): the slip number is no longer asked (existing numbers are kept) and the teller
 * may attach the bank receipt (PDF or image), viewed from Bank Reconciliation (§8.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teller_deposits', function (Blueprint $table): void {
            $table->string('slip_number')->nullable()->change();
            $table->string('receipt_file')->nullable()->after('slip_number');
            $table->string('receipt_file_name', 191)->nullable()->after('receipt_file');
            $table->string('receipt_mime', 100)->nullable()->after('receipt_file_name');
        });
    }

    public function down(): void
    {
        Schema::table('teller_deposits', function (Blueprint $table): void {
            $table->dropColumn(['receipt_file', 'receipt_file_name', 'receipt_mime']);
        });
    }
};
