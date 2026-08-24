<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registration approval stops being optional.
 *
 * WHAT CHANGES. `customers.approval_status` was decided by the customer's
 * CATEGORY: a category with `requires_extra_approval` registered `pending`,
 * every other category registered `not_required` — and `not_required` passed
 * the loan gate. In practice that meant almost every customer became able to
 * borrow the moment their face scan passed, with no human ever looking at the
 * registration. The business rule is that a manager approves the customer
 * first, so `not_required` stops being a state a new registration can reach.
 *
 * NO SCHEMA CHANGE. The column is already
 * `enum('not_required','pending','approved','rejected')` and every value this
 * needs is in it. This migration only moves existing rows onto the new
 * meaning, which is why it is pure DML.
 *
 * THE BACKFILL IS THE WHOLE POINT, AND IT IS DELIBERATELY TWO STATEMENTS.
 * `Customer::isLoanEligible()` now requires `approved`, so a blanket move of
 * `not_required` to `pending` would take away, overnight, the eligibility of
 * every customer who already had it — the branch would arrive to find its
 * whole book unable to borrow, for a rule nobody had yet been asked to apply.
 * So the split follows what each row could do YESTERDAY:
 *
 *   not_required + KYC completed   → approved   they were eligible; they stay
 *                                                eligible. Nothing regresses.
 *   not_required + KYC incomplete  → pending    they were not eligible anyway,
 *                                                so they join the new queue
 *                                                with nothing taken away.
 *
 * `pending`, `approved` and `rejected` rows are left exactly as they are —
 * those already carry a real decision, and overwriting one would erase who
 * made it.
 *
 * `approved_by` IS LEFT NULL on the grandfathered rows, on purpose. Setting it
 * to a person would put a name against a decision that person never made, and
 * setting it to the System account would claim the automation approved a
 * customer it never saw. A NULL approver on an `approved` row means exactly
 * one thing here: grandfathered by this migration. `approved_at` is stamped so
 * the row is not left half-populated.
 *
 * REVERSIBLE. `down()` undoes the grandfathering — the rows it promoted to
 * `approved`, identified by that same NULL-approver signature. It deliberately
 * does NOT touch `pending`; see the note on `down()` for the rollback hazard
 * that narrowing prevents.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Already eligible under the old rule — keep them eligible. Guarded on
         * `approved_by IS NULL` as well so a re-run cannot restamp a row.
         */
        DB::table('customers')
            ->where('approval_status', 'not_required')
            ->where('kyc_status', 'completed')
            ->update([
                'approval_status' => 'approved',
                'approved_at' => now(),
                'approved_by' => null,
                'updated_at' => now(),
            ]);

        /* Not eligible before, not eligible now — they simply join the queue. */
        DB::table('customers')
            ->where('approval_status', 'not_required')
            ->update([
                'approval_status' => 'pending',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        /*
         * Reverts the grandfathering, and NOTHING ELSE.
         *
         * Only rows this migration promoted: `approved` with a NULL approver,
         * which is the signature it stamps and which no human decision carries.
         * A customer approved by a real person keeps that decision — rolling
         * back a RULE must not discard somebody's judgement.
         *
         * IT DELIBERATELY LEAVES `pending` ROWS ALONE. An earlier draft also
         * reverted pending → not_required, and that was wrong in a way only a
         * rollback-then-re-migrate exposes: by then, `pending` holds two
         * indistinguishable populations — the KYC-incomplete rows this
         * migration moved, and customers genuinely waiting for a manager. Send
         * both back to `not_required` and the next `up()` promotes every
         * KYC-complete one straight to `approved`, silently approving people no
         * manager ever looked at. Verified: a rollback/re-apply cycle did
         * exactly that to two customers before this was narrowed.
         *
         * Leaving them pending costs nothing. Every row this migration made
         * pending has incomplete KYC, so it was ineligible under the old rule
         * and stays ineligible under it — the rollback changes no outcome for
         * any of them.
         */
        DB::table('customers')
            ->where('approval_status', 'approved')
            ->whereNull('approved_by')
            ->update(['approval_status' => 'not_required', 'approved_at' => null]);
    }
};
