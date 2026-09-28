<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers created from historical File reports (user ruling 2026-09-18). The old reports print only a name and a phone
 * number, so gender and date of birth become optional at the database level (registration still requires them) and phone
 * becomes optional for the customers whose printed number already belongs to another customer (it is kept in
 * alternative_phone). Such customers stay KYC "incomplete" — no phone fails the KYC checklist — so they cannot take a
 * new loan until registration is completed. Each historical record is linked to the customer created from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
            $table->string('gender')->nullable()->change();
            $table->date('date_of_birth')->nullable()->change();
        });

        Schema::table('historical_file_records', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('historical_file_report_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('historical_file_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('phone')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
            $table->date('date_of_birth')->nullable(false)->change();
        });
    }
};
