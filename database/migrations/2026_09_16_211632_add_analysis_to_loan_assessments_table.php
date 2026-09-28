<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The remaining panels of the analysis screen the officer was shown (§37A summary, capacity, contextual influence,
     * the weights and caps in force), so a stored snapshot reads exactly like the live assessment it froze.
     */
    public function up(): void
    {
        Schema::table('loan_assessments', function (Blueprint $table) {
            $table->json('analysis')->nullable()->after('steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_assessments', function (Blueprint $table) {
            $table->dropColumn('analysis');
        });
    }
};
