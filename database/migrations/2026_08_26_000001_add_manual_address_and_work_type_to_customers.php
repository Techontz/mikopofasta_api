<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ward and street become typed text; work type joins employment type as one.
 *
 * WHY THE DROPDOWNS HAD TO GO. `wards` and `streets` are reference tables
 * covering the country, and they are not complete — they never can be. A rural
 * customer's street frequently has no entry, and the registration form's
 * cascade made that unrecoverable: with no ward row there is no street list,
 * and the officer's only options were to pick a neighbouring ward (a wrong
 * address, recorded as if verified) or abandon the registration. Region and
 * district are different: there are 31 and about 190 of them, the lists are
 * complete, and picking from them is what keeps the address searchable.
 *
 * So the hierarchy splits at the point where our data stops being authoritative:
 *
 *     region   → chosen from `regions`      (region_id,   authoritative)
 *     district → chosen from `districts`    (district_id, authoritative)
 *     ward     → typed                      (ward_name)
 *     street   → typed                      (street_name)
 *
 * NOTHING IS DROPPED. `ward_id` and `street_id` stay, still nullable, still
 * constrained. Forty-two customers already carry them and their addresses must
 * keep resolving; a record that picked a real ward row is better evidence than
 * one that typed the same word, and the profile still reads the relation when
 * it is there. New registrations write the name columns, old ones keep both,
 * and the backfill below means every existing record can be read from the name
 * columns alone — so display code needs one path, not two.
 *
 * `work_type` mirrors `employment_type`, which has been a plain string column
 * since the 2026_08_02 KYC migration. Both were also being collected as
 * master-data ids by the legacy form, which is the contradiction this settles:
 * an occupation is not a controlled vocabulary. "Fundi wa pikipiki" is a real
 * answer and no list will ever contain it. The `*_id` columns stay for the
 * records that already reference a list entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('ward_name', 120)->nullable()->after('ward_id');
            $table->string('street_name', 120)->nullable()->after('street_id');

            /* Beside `employment_type`, not beside `work_type_id`: the two
               free-text answers belong together, and reading the column list
               is how the next person learns they are a pair. */
            $table->string('work_type', 120)->nullable()->after('employment_type');

            /*
             * Widened from 40. It was sized when this column held a code from
             * a fixed list; it is now what the officer types, and "Government
             * employee on contract" is a legitimate answer that a 40-character
             * column would silently truncate. 120 matches `work_type` and
             * `occupation`, which are the same kind of answer.
             */
            $table->string('employment_type', 120)->nullable()->change();
        });

        /*
         * Existing addresses, copied down from the tables they point at.
         *
         * An UPDATE … JOIN rather than a chunked loop: it is two joins over 42
         * rows today and would still be one statement at a million. Only rows
         * that actually carry an id are touched, so a customer with no ward
         * keeps a null name rather than an empty string.
         */
        DB::statement(<<<'SQL'
            UPDATE customers
              JOIN wards ON wards.id = customers.ward_id
               SET customers.ward_name = wards.name
             WHERE customers.ward_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE customers
              JOIN streets ON streets.id = customers.street_id
               SET customers.street_name = streets.name
             WHERE customers.street_id IS NOT NULL
        SQL);

        /*
         * The same treatment for the two lookup ids the form is dropping.
         * A customer whose work type was "Permanent" by id now also says so in
         * words, so the profile reads one column whichever way it was captured.
         */
        DB::statement(<<<'SQL'
            UPDATE customers
              JOIN work_types ON work_types.id = customers.work_type_id
               SET customers.work_type = work_types.name
             WHERE customers.work_type_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE customers
              JOIN employment_types ON employment_types.id = customers.employment_type_id
               SET customers.employment_type = employment_types.name
             WHERE customers.employment_type_id IS NOT NULL
               AND customers.employment_type IS NULL
        SQL);

        /* Searchable, like every other scalar the teller might be handed. See
           Customer::scopeSearch, which now covers both. */
        Schema::table('customers', function (Blueprint $table): void {
            $table->index('ward_name');
            $table->index('street_name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['ward_name']);
            $table->dropIndex(['street_name']);
            $table->dropColumn(['ward_name', 'street_name', 'work_type']);
        });
    }
};
