<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer-facing payment reference — client decision D6 / meeting note N4.
 *
 *     "Reference number should be generated after credit officer approves."
 *
 * ## Two identifiers, because they answer two different questions
 *
 * `loans.loan_number` is the APPLICATION number. It exists from the moment
 * somebody applies, it identifies a file that may never become a loan, and the
 * whole approval chain is filed under it.
 *
 * `loans.payment_reference` is what the CUSTOMER quotes when they pay. It comes
 * into existence only when credit approves, because until then there is nothing
 * to pay towards — and handing a reference to somebody whose application is
 * still being reviewed invites payments against loans that are later rejected.
 *
 * Keeping both is not duplication. Collapsing them would mean either issuing a
 * payment reference for applications that get refused, or leaving the approval
 * chain with nothing to file itself under for its first three stages.
 *
 * ## Nullable, and why that is not a weakness
 *
 * Null means "not yet approved by credit", which is a true statement about most
 * loans in the table at any moment. The alternative — a placeholder — would be a
 * reference that looks quotable and matches nothing.
 *
 * Existing loans are deliberately NOT backfilled. A loan disbursed before this
 * deploys was matched on its application number and its customer was told that
 * number; minting a second identifier for it now would mean a reference nobody
 * has been given, while the one they were given still has to keep working. The
 * matcher therefore accepts both, permanently.
 *
 * ## Why unique is enforced here
 *
 * The reference is what the repayment matcher keys on. Two loans sharing one
 * would post a customer's money against somebody else's debt, and no amount of
 * care in the generator is worth as much as the database refusing outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('payment_reference', 40)->nullable()->unique()->after('loan_number');
            $table->timestamp('payment_reference_issued_at')->nullable()->after('payment_reference');
        });

        /*
         * WHICH stage issues the reference is data, not a code comparison.
         *
         * The chain is already configurable — stages are rows, and Batch 2 will
         * make routing vary per branch. A generator that tested for the string
         * 'HEAD_OFFICE_CREDIT' would break the moment somebody renamed the
         * stage, reordered it, or added a stage after it. The flag says "the
         * reference is issued when this stage clears" and survives all three.
         */
        Schema::table('loan_approval_stages', function (Blueprint $table): void {
            $table->boolean('issues_payment_reference')->default(false)->after('requires_mandate_before');
        });

        // The credit stage as seeded today. Written here as well as in the
        // seeder so an installation that has already run its seeders is
        // migrated into the correct state rather than left with no issuing
        // stage at all — which would mean no loan ever gets a reference.
        DB::table('loan_approval_stages')
            ->where('code', 'HEAD_OFFICE_CREDIT')
            ->update(['issues_payment_reference' => true]);
    }

    public function down(): void
    {
        Schema::table('loan_approval_stages', function (Blueprint $table): void {
            $table->dropColumn('issues_payment_reference');
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['payment_reference', 'payment_reference_issued_at']);
        });
    }
};
