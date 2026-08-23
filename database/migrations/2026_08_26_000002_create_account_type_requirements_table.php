<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each account type actually requires before a customer is complete.
 *
 * THE PROBLEM THIS SOLVES. Registration asked every customer for the same
 * things regardless of what they were opening. A savings account was made to
 * answer for its employer, its basic salary and its check number; a salaried
 * loan account could be created with none of them. Neither was checked, so
 * "registered" meant only that the form had been submitted — and the KYC
 * status derived from it said `incomplete` for reasons the officer could not
 * see and could not act on.
 *
 * The rule is data, exactly like `loan_approval_stages.requires_branch_zone`
 * and `issues_payment_reference` in Batch 2. Which questions an account type
 * asks is a business decision that changes without a deploy, and expressing it
 * as `if ($accountType->code === 'LOAN')` would hardcode today's list of
 * account types into the validator, the wizard and the KYC evaluator at once.
 *
 * ONE ROW CARRIES THE DEFAULT. `account_type_id` is nullable and unique, so
 * exactly one row may have no account type: that is the profile applied to a
 * customer who has not chosen one, and to any account type nobody has
 * configured yet. Without it, adding an account type from the admin screen
 * would produce customers governed by no rule at all — which in a KYC system
 * means governed by nothing.
 *
 * NIDA AND OTP ARE HERE TOO, AND THEY DEFAULT TO NOT REQUIRED. That is not a
 * shortcut around verification. Neither integration exists: there is no
 * registry to query and no SMS gateway configured, so a profile requiring them
 * would make every customer permanently incomplete and permanently ineligible
 * for the loan they came in for. Recording them as columns is what makes the
 * decision visible and reversible — the day either integration lands, the
 * institution turns it on here rather than waiting for a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_type_requirements', function (Blueprint $table): void {
            $table->id();

            /*
             * NULL is the default profile. `unique` therefore also guarantees
             * there is at most one of it — MySQL treats NULLs as distinct in a
             * unique index, so this is enforced by the resolver refusing to
             * create a second, and by the seeder's updateOrCreate. The FK is
             * `cascadeOnDelete`: a profile for an account type that no longer
             * exists is not a rule, it is a leak.
             */
            $table->foreignId('account_type_id')
                ->nullable()
                ->unique()
                ->constrained('account_types')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // ---- which registration steps this account type has to complete
            $table->boolean('requires_employment_details')->default(false);
            $table->boolean('requires_business_details')->default(false);
            $table->boolean('requires_bank_account')->default(false);
            $table->boolean('requires_card_details')->default(false);

            // ---- who must vouch for them
            /* A count rather than a flag, because "at least one" and "at least
               two" are both real policies and a boolean can only say one of
               them. Zero means the step is offered but never blocks. */
            $table->unsignedTinyInteger('min_guarantors')->default(0);
            $table->unsignedTinyInteger('min_next_of_kin')->default(0);

            // ---- what must be on the record
            $table->boolean('requires_customer_category')->default(false);
            $table->boolean('requires_marital_status')->default(false);
            $table->boolean('requires_address')->default(true);
            /* At least one of NIDA / voter / driving licence / passport /
               work ID — which one is the customer's to choose, so no single
               column can express it. See KycEvaluator::hasIdentityDocument. */
            $table->boolean('requires_identity_document')->default(true);

            // ---- verification steps
            $table->boolean('requires_face_verification')->default(true);
            /* Both off until the integrations exist — see the class note. */
            $table->boolean('requires_nida_verification')->default(false);
            $table->boolean('requires_otp_verification')->default(false);

            /*
             * Shown to the officer above the step, so the form can explain
             * itself instead of only refusing. Nullable: a profile that needs
             * no explanation should not be given a filler one.
             */
            $table->string('guidance', 255)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * The default profile, created here rather than in a seeder.
         *
         * `AccountTypeRequirementResolver` falls back to this row for every
         * customer who has not chosen an account type, which is every customer
         * mid-registration — so its absence is not a missing seed, it is a
         * registration screen that cannot answer what it requires. A migration
         * is the only place that runs on every environment, including
         * production, so this is where the invariant is established. The
         * seeder tunes the per-account-type rows on top of it.
         *
         * The values are the columns' own defaults, written out: address and
         * an identity document required, a face scan required, NIDA and OTP
         * not, nothing else demanded of a customer whose account type is not
         * yet known.
         */
        DB::table('account_type_requirements')->insert([
            'account_type_id' => null,
            'requires_employment_details' => false,
            'requires_business_details' => false,
            'requires_bank_account' => false,
            'requires_card_details' => false,
            'min_guarantors' => 0,
            'min_next_of_kin' => 0,
            'requires_customer_category' => false,
            'requires_marital_status' => false,
            'requires_address' => true,
            'requires_identity_document' => true,
            'requires_face_verification' => true,
            'requires_nida_verification' => false,
            'requires_otp_verification' => false,
            'guidance' => 'Baseline requirements. Choosing an account type may add to these.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_type_requirements');
    }
};
