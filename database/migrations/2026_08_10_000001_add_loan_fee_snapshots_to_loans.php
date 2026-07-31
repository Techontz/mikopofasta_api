<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\ChargeValueType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the loan fee onto the loan, so it can be charged at disbursement.
 *
 * docs/modules/loan-charges.md names the four things wiring `loan_fees` into
 * disbursement requires, and this is the first: "the fee snapshotted onto the
 * loan, as the penalty rate already is". The reasoning is the same one that
 * migration gives for the three snapshots already there — an in-flight loan
 * must be immune to a mid-term product edit. A borrower quoted a 5% fee is owed
 * a 5% fee, whatever Settings says by the time the money moves.
 *
 * Three columns rather than one, because the fee has three parts and collapsing
 * them would lose the distinction the moment a percentage fee needs re-reading:
 *
 *   `fee_type_snapshot`        how to read `fee_amount_snapshot`
 *   `fee_amount_snapshot`      the arrangement fee — a percentage or flat TZS
 *   `insurance_amount_snapshot` the premium, always flat TZS
 *
 * All three are nullable. A product with no `loan_fees` row charges nothing,
 * which is the state every existing loan is in — and nullable says "no fee was
 * agreed" where zero would say "a fee of nothing was agreed". Those are
 * different facts, and the Deducted Income screen shows only the first kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->enum('fee_type_snapshot', ChargeValueType::values())
                ->nullable()
                ->after('penalty_rate_snapshot');

            /*
             * DECIMAL(18,3) rather than (18,2), matching penalty_rate_snapshot:
             * when fee_type is percentage_value this holds a rate, and a rate
             * rounded to two places cannot express 12.5%.
             */
            $table->decimal('fee_amount_snapshot', 18, 3)->nullable()->after('fee_type_snapshot');

            // Always a flat amount, so the money scale is right.
            $table->decimal('insurance_amount_snapshot', 18, 2)->nullable()->after('fee_amount_snapshot');

            /*
             * What was actually withheld, in shillings, resolved at
             * disbursement.
             *
             * Derived from the three columns above, and stored anyway. The
             * others record the terms; this records the event. Recomputing a
             * percentage years later from a snapshot rate would be arithmetic
             * on a figure the ledger already fixed — and if the two ever
             * disagreed, the ledger would be right and the screen wrong.
             * Storing it means the Deducted Income screen and the Fee Income
             * account read the same number by construction.
             *
             * Null until the loan disburses; a loan that never disburses is
             * never charged.
             */
            $table->decimal('fee_charged', 18, 2)->nullable()->after('insurance_amount_snapshot');

            // The Deducted Income screen lists loans by when the fee was taken.
            $table->index(['fee_charged', 'disbursement_date'], 'loans_fee_charged_index');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropIndex('loans_fee_charged_index');
            $table->dropColumn([
                'fee_type_snapshot',
                'fee_amount_snapshot',
                'insurance_amount_snapshot',
                'fee_charged',
            ]);
        });
    }
};
