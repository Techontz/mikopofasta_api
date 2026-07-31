<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The headquarters accounts and the transfers between them.
 *
 * Reconstructed from three legacy screens: Headquater Account Balance
 * (/admin/hq_account_balance), Headquater Transaction requested
 * (/admin/request_headqueter) and Headquater Transaction Aproved
 * (/admin/request_headqueter_aproved).
 *
 * The legacy HQ module is not the chart of accounts. It is a small internal
 * ledger over seven named pots — SALARY ADVANCE, DISBURSEMENT, PENALTY,
 * INTEREST, RESERVE, LOAN FEE and SAVING — and money moves between them by a
 * request that someone later approves. Two of the seven (DISBURSEMENT and
 * SAVING) have no counterpart in our §5 chart at all, so folding them into
 * `chart_of_accounts` would have meant either inventing two system codes or
 * dropping two accounts. Its own table keeps the legacy set intact and exact.
 *
 * `balance` is stored rather than derived, which is the one place this module
 * departs from how the rest of the system works. The reason is evidential: the
 * seven legacy balances are known and sum to the printed total, but the
 * transfers that produced them are not — both transaction screens were captured
 * empty. A derived balance would have to start from zero and disagree with the
 * legacy system on day one. When the transfer history is captured this can
 * become a projection over `hq_account_transfers` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hq_accounts', function (Blueprint $table): void {
            $table->id();

            // Upper case in the legacy data, and stored as found — these names
            // are printed verbatim on the balance screen.
            $table->string('name', 120)->unique();

            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('hq_account_transfers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('from_account_id')->constrained('hq_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('to_account_id')->constrained('hq_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 18, 2);

            /*
             * "Charger" on both legacy screens, between Staff Name and the date.
             * Its values were never captured, so its meaning is inferred from
             * position only and it is deliberately typed loosely rather than
             * modelled as a fee amount we would then have to justify.
             */
            $table->string('charger', 120)->nullable();

            // The legacy screens print a staff name, not a link to a user, and
            // no staff list has been captured. Held as text until it can be
            // reconciled against real users.
            $table->string('staff_name', 120)->nullable();

            // Legacy status values are unknown — the column exists on both
            // screens but every captured row set was empty.
            $table->string('status', 40);

            $table->date('requested_on');
            $table->date('approved_on')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('requested_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_account_transfers');
        Schema::dropIfExists('hq_accounts');
    }
};
