<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer record learns which ID it holds, where the customer serves, on
 * what contract, and what they actually take home.
 *
 * ADDITIVE AND NULLABLE, every column. The 45 customers already on file have
 * none of this and cannot be made to, so a NOT NULL would have made every one
 * of them un-editable the moment it shipped. Nothing is dropped or re-typed.
 *
 * THE SIX LEGACY ID COLUMNS STAY. `nida_number`, `voter_id_number`,
 * `driver_licence_number`, `passport_number`, `work_id_number` and
 * `tin_number` are backfilled INTO the new pair and then left exactly where
 * they are. Dropping them would destroy the only copy of two customers' voter
 * numbers to save two sparse columns, and `nida_number` in particular is read
 * by the NIDA verification path and by KycEvaluator's identity check. The new
 * pair becomes what registration writes; the old columns remain what the rest
 * of the system already reads, until a later pass migrates those readers.
 *
 * BACKFILL PRECEDENCE. NIDA, then voter, then licence, then passport, then
 * work ID, then TIN — most-authoritative first, and the same order the list is
 * seeded in. A customer holding two documents is recorded under the stronger
 * one, which is the one a branch would have asked for.
 *
 * TAKE-HOME SALARY IS DELIBERATELY NOT ADDED HERE. The requirement asks for
 * one, and `customers.take_home` already is one — a `bigint unsigned` from the
 * legacy registration form, fillable, cast, published by CustomerResource as
 * `takeHome`, written by RegisterCustomerAction, and read by KycEvaluator's
 * income check. Two customers already have a value in it. Adding
 * `take_home_salary` beside it would have given the same fact two columns and
 * left the evaluator reading whichever one the last screen happened to write.
 * The field is surfaced in the wizard instead; the column was already correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            /* Identity: one type, one number. */
            $table->foreignId('id_type_id')->nullable()->after('nida_number')
                ->constrained('id_types')->nullOnDelete();
            $table->string('id_number', 60)->nullable()->after('id_type_id');

            /* Where they serve, in two levels. */
            $table->foreignId('sector_id')->nullable()->after('employment_type_id')
                ->constrained('sectors')->nullOnDelete();
            $table->foreignId('sector_category_id')->nullable()->after('sector_id')
                ->constrained('sector_categories')->nullOnDelete();

            /* On what terms. The expiry is meaningful only for a fixed term;
               the rule that requires it lives in the form request, because a
               CHECK constraint here could not be relaxed without a migration. */
            $table->foreignId('contract_type_id')->nullable()->after('sector_category_id')
                ->constrained('contract_types')->nullOnDelete();
            $table->date('contract_expiry_date')->nullable()->after('contract_type_id');
        });

        $this->backfillIdentity();
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('id_type_id');
            $table->dropConstrainedForeignId('sector_id');
            $table->dropConstrainedForeignId('sector_category_id');
            $table->dropConstrainedForeignId('contract_type_id');
            $table->dropColumn(['id_number', 'contract_expiry_date']);
        });
    }

    /**
     * Copies each customer's strongest existing ID into the new pair.
     *
     * One UPDATE per type, weakest first, so a stronger document overwrites a
     * weaker one on the second pass. Running it the other way would need a
     * CASE expression per column and produce the same answer less legibly.
     */
    private function backfillIdentity(): void
    {
        $map = [
            'tin_number' => 'TIN',
            'work_id_number' => 'WORK_ID',
            'passport_number' => 'PASSPORT',
            'driver_licence_number' => 'DRIVER_LICENCE',
            'voter_id_number' => 'VOTER_ID',
            'nida_number' => 'NIDA',
        ];

        foreach ($map as $column => $code) {
            if (! Schema::hasColumn('customers', $column)) {
                continue;
            }

            $typeId = DB::table('id_types')->where('code', $code)->value('id');

            if ($typeId === null) {
                continue;
            }

            DB::table('customers')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->update([
                    'id_type_id' => $typeId,
                    'id_number' => DB::raw("`{$column}`"),
                ]);
        }
    }
};
