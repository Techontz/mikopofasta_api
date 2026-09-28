<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents (LOAN PROCESS OVERVIEW): "IF loan product.requires_mandate = TRUE → E-MANDATE FLOW ELSE → NORMAL LOAN FLOW".
     */
    public function up(): void
    {
        if (Schema::hasColumn('loan_categories', 'requires_mandate')) {
            return;
        }

        Schema::table('loan_categories', function (Blueprint $table) {
            $table->boolean('requires_mandate')->default(false)->after('approve_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('loan_categories', 'requires_mandate')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropColumn('requires_mandate');
            });
        }
    }
};
