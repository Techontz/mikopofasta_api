<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a loan entered its current status (enhancement §7.2): Ready to Pay Out filters each tab by the day the loan reached
 * it. Existing loans start from their last update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->index(['status', 'status_changed_at']);
        });

        DB::table('loans')->update(['status_changed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropIndex(['status', 'status_changed_at']);
            $table->dropColumn('status_changed_at');
        });
    }
};
