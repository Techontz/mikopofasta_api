<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Customers without a customer type get one only when it is unambiguous: every loan they hold belongs to loan categories
     * of ONE main loan category, whose customer type is then assigned. Customers without loans, or with loans in several
     * main loan categories, stay without a customer type. Loans keep their categories.
     */
    public function up(): void
    {
        $candidates = DB::table('customers')
            ->join('loans', 'loans.customer_id', '=', 'customers.id')
            ->join('loan_categories', 'loan_categories.id', '=', 'loans.loan_category_id')
            ->join('main_categories', 'main_categories.id', '=', 'loan_categories.main_category_id')
            ->whereNull('customers.customer_category_id')
            ->groupBy('customers.id')
            ->havingRaw('COUNT(DISTINCT main_categories.customer_category_id) = 1')
            ->selectRaw('customers.id, MIN(main_categories.customer_category_id) AS customer_category_id')
            ->get();

        foreach ($candidates as $candidate) {
            DB::table('customers')->where('id', $candidate->id)->whereNull('customer_category_id')->update(['customer_category_id' => $candidate->customer_category_id]);
        }
    }

    /**
     * Irreversible data backfill: the previous NULLs are not recorded, so nothing is undone.
     */
    public function down(): void {}
};
