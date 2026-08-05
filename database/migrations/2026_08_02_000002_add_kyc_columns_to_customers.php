<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The KYC fields a microfinance customer record actually needs.
 *
 * WHY COLUMNS AND NOT JSON. `dynamic_form_data` already exists and is the right
 * home for whatever a *category* happens to ask — a boda-boda rider's
 * motorcycle registration, a shopkeeper's stall number. It is the wrong home
 * for identity and contact details that every customer has and that the
 * business needs to search, index and report on. A TIN inside a JSON blob
 * cannot be indexed, cannot be uniquely constrained, and cannot be found by a
 * teller typing it into a search box. These are first-class facts about a
 * person, so they get first-class columns.
 *
 * Everything is nullable. A customer registered today supplies what they have;
 * a microfinance institution's customers frequently have no email, no TIN and
 * no passport, and a NOT NULL column would either block their registration or
 * be satisfied with a placeholder — which is how a database fills up with
 * "N/A". The KYC evaluator decides completeness; the schema does not pretend to.
 *
 * Indexed where the business searches: the identity numbers, the phone and the
 * account numbers a customer is looked up by. Not indexed where it never
 * searches, because an index that is never read is a write cost with no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // ---------------------------------------------------------- personal
            $table->string('alternative_phone', 20)->nullable()->after('phone');
            $table->string('email', 150)->nullable()->after('alternative_phone');
            $table->string('nationality', 60)->nullable()->after('email');
            /*
             * `national_id_number` is deliberately NOT `nida_number`. The
             * latter is the NIDA registry's own identifier and stays reserved
             * for the day that integration lands, so a manually-entered ID card
             * number can never be mistaken for a verified NIDA record.
             */
            $table->string('national_id_number', 40)->nullable()->after('nationality');
            $table->string('tin_number', 30)->nullable()->after('national_id_number');
            $table->string('passport_number', 30)->nullable()->after('tin_number');

            // ----------------------------------------------------------- address
            // Below street in the geography cascade, and free text: no registry
            // enumerates villages, house numbers or landmarks.
            $table->string('village', 120)->nullable()->after('street_id');
            $table->string('house_number', 40)->nullable()->after('village');
            $table->string('postal_code', 20)->nullable()->after('house_number');
            $table->string('landmark', 180)->nullable()->after('postal_code');

            // -------------------------------------------------------- employment
            $table->string('occupation', 120)->nullable()->after('residence_type');
            $table->string('employer', 150)->nullable()->after('occupation');
            /*
             * Money as an integer of the minor unit, like every other amount in
             * this system. A float would introduce a rounding rule that
             * disagrees with the ledger's.
             */
            $table->unsignedBigInteger('monthly_income')->nullable()->after('employer');
            $table->string('employment_type', 40)->nullable()->after('monthly_income');

            // ---------------------------------------------------------- business
            $table->string('business_name', 150)->nullable()->after('employment_type');
            $table->string('business_type', 120)->nullable()->after('business_name');
            $table->string('business_address', 255)->nullable()->after('business_type');

            // --------------------------------------------------------- financial
            /*
             * Duplicated from `customer_bank_details` on purpose? No — that
             * table stays the record of a customer's bank account, and these
             * mirror the primary one so a search by account number is one query
             * and one index rather than a join. The registration wizard writes
             * both through the same action.
             */
            $table->string('bank_name', 100)->nullable()->after('business_address');
            $table->string('bank_branch', 100)->nullable()->after('bank_name');
            $table->string('account_name', 150)->nullable()->after('bank_branch');
            $table->string('account_number', 50)->nullable()->after('account_name');
            $table->string('mobile_money_provider', 60)->nullable()->after('account_number');
            $table->string('wallet_number', 30)->nullable()->after('mobile_money_provider');

            // ------------------------------------------------------------ system
            /*
             * Two photos, not one. `photo_path` (already present) is the KYC
             * liveness capture and is biometric data on the private disk. A
             * profile photo is a convenience the customer may replace at will.
             * Collapsing them would mean replacing an avatar silently
             * overwrites a KYC artefact.
             */
            $table->string('profile_photo', 255)->nullable()->after('photo_path');
            $table->string('face_photo', 255)->nullable()->after('profile_photo');
            /* How this record came to exist: manual today, `nida` later, and
               `import` for a migration run. Answers "which of these did a human
               type?" long after the fact. */
            $table->string('registration_source', 30)->nullable()->default('manual')->after('created_by');
            $table->string('created_device', 255)->nullable()->after('registration_source');
            $table->string('updated_device', 255)->nullable()->after('created_device');

            // ----------------------------------------------------------- indexes
            // Exactly the fields a teller types into the search box.
            $table->index('email');
            $table->index('national_id_number');
            $table->index('tin_number');
            $table->index('passport_number');
            $table->index('account_number');
            $table->index('wallet_number');
            $table->index('alternative_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->dropIndex(['national_id_number']);
            $table->dropIndex(['tin_number']);
            $table->dropIndex(['passport_number']);
            $table->dropIndex(['account_number']);
            $table->dropIndex(['wallet_number']);
            $table->dropIndex(['alternative_phone']);

            $table->dropColumn([
                'alternative_phone', 'email', 'nationality', 'national_id_number',
                'tin_number', 'passport_number',
                'village', 'house_number', 'postal_code', 'landmark',
                'occupation', 'employer', 'monthly_income', 'employment_type',
                'business_name', 'business_type', 'business_address',
                'bank_name', 'bank_branch', 'account_name', 'account_number',
                'mobile_money_provider', 'wallet_number',
                'profile_photo', 'face_photo',
                'registration_source', 'created_device', 'updated_device',
            ]);
        });
    }
};
