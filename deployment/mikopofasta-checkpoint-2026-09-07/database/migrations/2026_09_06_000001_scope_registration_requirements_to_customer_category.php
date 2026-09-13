<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the requirement profile a second dimension: the customer type.
 *
 * ## The problem
 *
 * Requirements were keyed on `account_type_id` alone — on what the customer is
 * OPENING (Default / Loan / Savings). But most of what the columns describe is
 * about who the customer IS: whether they have an employer and a salary, or a
 * business; which documents they must produce; whether anybody must vouch for
 * them. Those answers belong to the customer type.
 *
 * So the institution had two places to say what a customer must supply, keyed
 * on different things and unable to agree:
 *
 *   account_type_requirements  → employment, business, bank, guarantors, ...
 *   customer_categories        → required_documents, dynamic_form_schema,
 *                                requires_sector / contract / salary
 *
 * A business customer opening a loan account was asked for employment details
 * because the LOAN account type demanded them, and there was no way for the
 * customer type to say otherwise. That is the collision this migration starts
 * to close.
 *
 * ## What it does
 *
 * One table keeps one meaning — a requirement profile — and gains a scope:
 *
 *   customer_category_id = NULL, account_type_id = NULL   the default profile
 *   account_type_id  set                                  per account type
 *   customer_category_id set                              per customer type
 *
 * A profile is resolved most-specific-first (customer type, then account type,
 * then default) and composed FIELD BY FIELD. That is why every requirement
 * column becomes nullable: on a scoped row NULL means "I do not have an
 * opinion, ask the next profile down", and a non-null value overrides. Without
 * that, configuring a customer type would mean restating every flag, and
 * forgetting one would silently relax a KYC rule.
 *
 * ## Existing data is not touched
 *
 * Every existing row keeps its values, and every existing value is non-null —
 * so the default row and the per-account-type rows resolve exactly as they did
 * before this ran. Nothing changes until somebody creates a customer-type
 * profile from the admin screen.
 *
 * ## Rollback
 *
 * Safe, with one caveat that is enforced rather than hoped for: `down()` drops
 * the customer-type rows first, because restoring NOT NULL on a column that a
 * scoped row left NULL would fail. Dropping them is correct — they cannot be
 * expressed in the old shape at all.
 */
return new class extends Migration
{
    /**
     * The requirement columns, which all become nullable so a scoped profile
     * can decline to answer. Kept as one list so `up` and `down` cannot drift.
     */
    private const array FLAGS = [
        'requires_employment_details',
        'requires_business_details',
        'requires_bank_account',
        'requires_card_details',
        'requires_customer_category',
        'requires_marital_status',
        'requires_address',
        'requires_identity_document',
        'requires_face_verification',
        'requires_nida_verification',
        'requires_otp_verification',
        'requires_category_documents',
    ];

    private const array COUNTS = ['min_guarantors', 'min_next_of_kin'];

    public function up(): void
    {
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->foreignId('customer_category_id')
                ->nullable()
                ->after('account_type_id')
                ->constrained('customer_categories')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });

        /*
         * The old index was `unique(account_type_id)`, which allowed exactly
         * one row per account type and — because MySQL treats NULLs as
         * distinct — did not by itself hold the default row to one. That is
         * unchanged here; the resolver and the seeder are what keep the
         * default single, as the original migration's note says.
         *
         * The composite lets one account type and one customer type each have
         * a profile without colliding.
         *
         * ORDER MATTERS, and getting it wrong fails the migration. There is a
         * foreign key on `account_type_id`, and InnoDB requires an index to
         * support it — the single unique was that index. Dropping it first
         * therefore fails with errno 150. Creating the composite first gives
         * the constraint a new home (`account_type_id` is its leading column),
         * and only then can the old one go.
         */
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->unique(['account_type_id', 'customer_category_id'], 'atr_scope_unique');
        });

        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->dropUnique('account_type_requirements_account_type_id_unique');
        });

        Schema::table('account_type_requirements', function (Blueprint $table): void {
            foreach (self::FLAGS as $flag) {
                $table->boolean($flag)->nullable()->default(null)->change();
            }

            foreach (self::COUNTS as $count) {
                $table->unsignedTinyInteger($count)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        /*
         * Customer-type profiles cannot exist in the old shape, and any NULL
         * they hold would block the NOT NULL restore below. They go first.
         */
        DB::table('account_type_requirements')->whereNotNull('customer_category_id')->delete();

        Schema::table('account_type_requirements', function (Blueprint $table): void {
            foreach (self::FLAGS as $flag) {
                $table->boolean($flag)->default(false)->nullable(false)->change();
            }

            foreach (self::COUNTS as $count) {
                $table->unsignedTinyInteger($count)->default(0)->nullable(false)->change();
            }
        });

        /* The mirror of the note in `up`: the composite is now what supports
           the foreign key, so the single unique is restored before it goes. */
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->unique('account_type_id');
        });

        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->dropUnique('atr_scope_unique');
            $table->dropConstrainedForeignId('customer_category_id');
        });
    }
};
