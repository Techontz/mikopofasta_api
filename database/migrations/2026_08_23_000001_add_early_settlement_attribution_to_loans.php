<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who settled a loan early, and which payment did it.
 *
 * `early_settled_at` and `interest_waived` already record WHEN and HOW MUCH was
 * forgiven. Neither records WHO decided or WHAT the customer handed over, and a
 * settlement is exactly the kind of act that has to answer both: it closes a
 * loan ahead of contract and writes off interest the institution would
 * otherwise have earned.
 *
 * Until now those two facts existed only in the audit log and in a payments row
 * nothing pointed at, so the loan itself could not say who closed it. The
 * settlement screen therefore could not show a reference or an officer without
 * the frontend going looking — which is the kind of reconstruction that lets a
 * screen and a ledger disagree.
 *
 * ## Why the payment is a nullable link rather than copied columns
 *
 * The reference and the amount already exist on `payments`, and copying them
 * here would create a second place for them to be wrong. The link is nullable
 * because a settlement does not always take money: a loan whose entire
 * remaining balance is unearned interest is settled by the waiver alone, and
 * `SettleLoanEarlyAction` creates no payment for it.
 *
 * `early_settled_by` is NOT nullable-by-accident for the same reason — there is
 * always an officer, even when there is no cash, which is precisely why it is
 * stored separately from the payment rather than read off it.
 *
 * Both are `nullOnDelete` to match `loans.approved_by`: a user record removed
 * years later must not take the loan's history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->foreignId('early_settled_by')
                ->nullable()
                ->after('early_settled_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('early_settlement_payment_id')
                ->nullable()
                ->after('early_settled_by')
                ->constrained('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('early_settlement_payment_id');
            $table->dropConstrainedForeignId('early_settled_by');
        });
    }
};
