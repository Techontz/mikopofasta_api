<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The loan method moves from the loan category to the loan itself (chosen on the application form). Existing loans keep
 * the method their category gave them; a category marked as needing a mandate counts as E-Mandate. The category columns
 * stay for history but no longer decide anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('loan_method', 20)->default('normal')->after('loan_category_id');
        });

        DB::table('loan_categories')->where('requires_mandate', true)->where('loan_method', 'normal')->update(['loan_method' => 'e_mandate']);
        foreach (DB::table('loan_categories')->where('loan_method', '!=', 'normal')->get(['id', 'loan_method']) as $category) {
            DB::table('loans')->where('loan_category_id', $category->id)->update(['loan_method' => $category->loan_method]);
        }
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('loan_method');
        });
    }
};
