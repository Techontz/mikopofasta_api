<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every loan category belongs to exactly one main loan category: loan_categories.main_category_id becomes NOT NULL and a
     * main category holding loan categories can no longer be deleted (was ON DELETE SET NULL).
     */
    public function up(): void
    {
        $orphans = DB::table('loan_categories')->whereNull('main_category_id')->pluck('name', 'id');
        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('Loan categories without a main loan category: '.$orphans->map(fn ($name, $id): string => "#{$id} {$name}")->implode(', ').'. Assign a main loan category before migrating.');
        }

        Schema::table('loan_categories', function (Blueprint $table) {
            $table->dropForeign(['main_category_id']);
        });
        Schema::table('loan_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('main_category_id')->nullable(false)->change();
            $table->foreign('main_category_id')->references('id')->on('main_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_categories', function (Blueprint $table) {
            $table->dropForeign(['main_category_id']);
        });
        Schema::table('loan_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('main_category_id')->nullable()->change();
            $table->foreign('main_category_id')->references('id')->on('main_categories')->nullOnDelete();
        });
    }
};
