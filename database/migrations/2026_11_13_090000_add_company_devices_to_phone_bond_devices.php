<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Devices: company-owned smartphones managed with Linda Device Lock next to the eSIMU loan devices, in the same
 * table so they reuse the Linda registration, enrollment, lock / unlock commands, webhooks, incidents and audit. `kind`
 * keeps them apart: a company device has no customer and never secures a loan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_bond_devices', function (Blueprint $table): void {
            $table->string('kind', 20)->default('loan')->after('company_id');
            $table->string('department', 100)->nullable()->after('kind');
            $table->foreignId('customer_id')->nullable()->change();
            $table->index(['company_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_bond_devices', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'kind', 'status']);
            $table->dropColumn(['kind', 'department']);
        });
    }
};
