<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy loan statuses → documented lifecycle statuses.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'pending' => 'pending_manager_approval',
        'disbursed' => 'awaiting_disbursement',
        'done' => 'closed',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('loans')->where('status', $old)->update(['status' => $new]);
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->string('status')->default('pending_manager_approval')->change();
        });
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('loans')->where('status', $new)->update(['status' => $old]);
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }
};
