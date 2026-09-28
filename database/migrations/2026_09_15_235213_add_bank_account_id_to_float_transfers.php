<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company → HQ float may be funded from a company bank account, so the float row records which one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('float_transfers', function (Blueprint $table): void {
            $table->foreignId('bank_account_id')->nullable()->after('from_account')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('float_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
