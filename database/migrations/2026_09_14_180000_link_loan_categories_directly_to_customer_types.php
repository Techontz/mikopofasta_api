<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business model: CUSTOMER TYPE (customer_categories, 1) → (many) LOAN CATEGORY. The "main loan category" level is removed.
     *
     * 1. Adds loan_categories.customer_category_id (FK → customer_categories, restrict on delete).
     * 2. Backfills it from the loan category's main category (main_categories.customer_category_id, 1:1 with the customer type).
     * 3. Fails loudly when a loan category is left without a customer type or points at a customer type of another company.
     * 4. Makes it NOT NULL (+ company/customer type index) and drops loan_categories.main_category_id.
     *
     * The `main_categories` and legacy sub category (`customer_types`) tables are kept untouched as history and for down(); no
     * code reads them any more. Loans keep their loan_category_id. Idempotent: every step is guarded.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('loan_categories', 'customer_category_id')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->foreignId('customer_category_id')->nullable()->after('company_id')->constrained('customer_categories')->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('loan_categories', 'main_category_id')) {
            DB::transaction(function (): void {
                $links = DB::table('loan_categories')
                    ->join('main_categories', 'main_categories.id', '=', 'loan_categories.main_category_id')
                    ->whereNull('loan_categories.customer_category_id')
                    ->pluck('main_categories.customer_category_id', 'loan_categories.id');

                foreach ($links as $loanCategoryId => $customerTypeId) {
                    DB::table('loan_categories')->where('id', $loanCategoryId)->update(['customer_category_id' => $customerTypeId]);
                }
            });
        }

        $this->assertEveryLoanCategoryHasACustomerTypeOfItsCompany();

        if ($this->isNullable('loan_categories', 'customer_category_id')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropForeign(['customer_category_id']);
            });
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_category_id')->nullable(false)->change();
                $table->foreign('customer_category_id')->references('id')->on('customer_categories')->restrictOnDelete();
                $table->index(['company_id', 'customer_category_id']);
            });
        }

        if (Schema::hasColumn('loan_categories', 'main_category_id')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropForeign(['main_category_id']);
            });
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropColumn('main_category_id');
            });
        }
    }

    private function assertEveryLoanCategoryHasACustomerTypeOfItsCompany(): void
    {
        $missing = DB::table('loan_categories')->whereNull('customer_category_id')->pluck('name', 'id');
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Loan categories without a customer type: '.$missing->map(fn ($name, $id): string => "#{$id} {$name}")->implode(', ').'. Link their main loan category to a customer type before migrating.');
        }

        $mismatched = DB::table('loan_categories')
            ->leftJoin('customer_categories', 'customer_categories.id', '=', 'loan_categories.customer_category_id')
            ->where(fn ($query) => $query->whereNull('customer_categories.id')->orWhereColumn('customer_categories.company_id', '!=', 'loan_categories.company_id'))
            ->pluck('loan_categories.name', 'loan_categories.id');
        if ($mismatched->isNotEmpty()) {
            throw new RuntimeException('Loan categories whose customer type is missing or belongs to another company: '.$mismatched->map(fn ($name, $id): string => "#{$id} {$name}")->implode(', ').'.');
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        return (bool) collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'];
    }

    /**
     * Restores loan_categories.main_category_id (NOT NULL, restrict) from the customer type's main category — creating the main
     * category when the customer type has none — and drops customer_category_id.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('loan_categories', 'main_category_id')) {
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->foreignId('main_category_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasColumn('loan_categories', 'customer_category_id')) {
            DB::transaction(function (): void {
                foreach (DB::table('loan_categories')->whereNull('main_category_id')->get(['id', 'company_id', 'customer_category_id']) as $category) {
                    $mainId = DB::table('main_categories')->where('company_id', $category->company_id)->where('customer_category_id', $category->customer_category_id)->value('id');
                    if ($mainId === null) {
                        $type = DB::table('customer_categories')->where('id', $category->customer_category_id)->first();
                        $mainId = DB::table('main_categories')->insertGetId([
                            'company_id' => $category->company_id,
                            'customer_category_id' => $type->id,
                            'code' => $type->code ?? $type->key,
                            'name' => $type->name,
                            'is_enabled' => (bool) $type->is_active,
                        ]);
                    }
                    DB::table('loan_categories')->where('id', $category->id)->update(['main_category_id' => $mainId]);
                }
            });

            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropForeign(['main_category_id']);
                $table->dropForeign(['customer_category_id']);
            });
            $indexes = collect(Schema::getIndexes('loan_categories'));
            // MySQL may serve the company_id foreign key from the composite index: give company_id its own index back first.
            if (! $indexes->contains(fn (array $index): bool => $index['columns'] === ['company_id'])) {
                Schema::table('loan_categories', function (Blueprint $table) {
                    $table->index('company_id', 'loan_categories_company_id_foreign');
                });
            }
            Schema::table('loan_categories', function (Blueprint $table) use ($indexes) {
                if ($indexes->contains(fn (array $index): bool => $index['columns'] === ['company_id', 'customer_category_id'])) {
                    $table->dropIndex(['company_id', 'customer_category_id']);
                }
            });
            Schema::table('loan_categories', function (Blueprint $table) {
                $table->dropColumn('customer_category_id');
                $table->unsignedBigInteger('main_category_id')->nullable(false)->change();
                $table->foreign('main_category_id')->references('id')->on('main_categories')->restrictOnDelete();
            });
        }
    }
};
