<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration collects a list of next of kin (CUSTOMER_MODULE_SPEC.md §3.4), so a customer may have many rows.
     */
    public function up(): void
    {
        Schema::table('customer_next_of_kin', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropUnique(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_next_of_kin', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex('customer_next_of_kin_customer_id_foreign');
            $table->unique('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
