<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH kind of account the customer gave, not merely what it says.
 *
 * The registration form asks for a mobile wallet or a bank account and stores
 * whichever was filled in. The question itself was never kept, so every screen
 * that wanted to know rebuilt it by inspecting the columns — see the draft
 * repair in wizard-schema.ts and PaymentMethod's own note. Inspection cannot
 * distinguish "this customer banks with CRDB" from "somebody typed a bank name
 * once and cleared the account number", and it silently changes its mind when
 * an unrelated edit empties a field.
 *
 * BACKFILLED THE WAY THE APPLICATION ALREADY GUESSES, so existing customers
 * keep reading the way they read yesterday: an account number means a bank, a
 * wallet number or a provider means MNO, neither means null. The account
 * number is what identifies a bank account for the same reason the API uses it
 * — it is the part whose presence means nothing is missing.
 *
 * NULLABLE, because an account type that does not require an account leaves a
 * customer with neither, and that is not a defect to be filled in with a
 * default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('payment_method', 10)->nullable()->after('wallet_number');
            $table->index('payment_method');
        });

        /* MNO first: a customer holding both a wallet and an account number is
           recorded the way the frontend's own repair reads them. */
        DB::table('customers')
            ->where(fn ($q) => $q->whereNotNull('wallet_number')->orWhereNotNull('mobile_money_provider_id'))
            ->update(['payment_method' => PaymentMethod::Mno->value]);

        DB::table('customers')
            ->whereNull('payment_method')
            ->where(fn ($q) => $q->whereNotNull('account_number')->orWhereNotNull('bank_id'))
            ->update(['payment_method' => PaymentMethod::Bank->value]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['payment_method']);
            $table->dropColumn('payment_method');
        });
    }
};
