<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more admin-managed lists: which identity document, and on what terms
 * somebody is employed.
 *
 * ID TYPES replace six independent columns. Registration held
 * `nida_number`, `voter_id_number`, `driver_licence_number`,
 * `passport_number`, `work_id_number` and `tin_number` side by side, and asked
 * the officer to find the right box. Across the 45 customers on file, two of
 * those six columns were ever used: `nida_number` on 44 and `voter_id_number`
 * on 2. The other four have never held a value. A type plus a number says the
 * same thing in two fields instead of six, and — the reason it matters — it
 * makes "which document did we see?" answerable, which six sparse columns
 * never did.
 *
 * The six codes seeded below are not invented. They are exactly the six fields
 * the wizard already offered, so nothing a branch could previously record
 * becomes unrecordable.
 *
 * CONTRACT TYPES are Permanent and Temporary, and the distinction carries a
 * rule: a temporary contract has an expiry date and a permanent one does not.
 * That rule needs the code to be knowable, which is why `code` is what the
 * validator matches on rather than the name an administrator may translate.
 *
 * Both use the shape the other lists use (2026_08_02, 2026_08_15), so both
 * arrive with the existing controller, policy, resource and admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['id_types', 'contract_types'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'sort_order']);
            });
        }

        $now = now();

        $insert = static function (string $table, array $rows) use ($now): void {
            DB::table($table)->insert(array_map(
                static fn (array $r): array => [
                    'code' => $r[0],
                    'name' => $r[1],
                    'description' => $r[2],
                    'sort_order' => $r[3],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows,
            ));
        };

        /* NIDA first because it is what 44 of the 45 customers on file
           produced — these lists sort by frequency, not alphabetically. */
        $insert('id_types', [
            ['NIDA', 'National ID (NIDA)', 'Tanzanian national identity card.', 10],
            ['VOTER_ID', 'Voter ID', 'National Electoral Commission voter card.', 20],
            ['DRIVER_LICENCE', "Driver's Licence", 'Tanzanian driving licence.', 30],
            ['PASSPORT', 'Passport', 'Tanzanian or foreign passport.', 40],
            ['WORK_ID', 'Work ID', 'Employer-issued identity card.', 50],
            ['TIN', 'TIN', 'Taxpayer identification number.', 60],
        ]);

        $insert('contract_types', [
            ['PERMANENT', 'Permanent', 'No end date. An expiry date is not collected.', 10],
            ['TEMPORARY', 'Temporary', 'Fixed term. An expiry date is required.', 20],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_types');
        Schema::dropIfExists('id_types');
    }
};
