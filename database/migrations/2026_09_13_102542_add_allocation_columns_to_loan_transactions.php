<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the full split of each repayment (allocation order: Principal → Penalty → Interest).
     */
    public function up(): void
    {
        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->decimal('penalty', 15, 2)->default(0)->after('principal');
            $table->decimal('insurance', 15, 2)->default(0)->after('interest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->dropColumn(['penalty', 'insurance']);
        });
    }
};
