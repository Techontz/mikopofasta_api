<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * STRUCTURE ONLY — NO ROWS. Which documents an institution accepts as proof of
 * identity is its own policy, not this application's to assume. A fresh
 * install starts with the table empty and the form says so.
 *
 * CONTRACT TYPES carry one rule: a type whose CODE is `TEMPORARY` requires an
 * expiry date, and anything else refuses one. The rule keys on the code rather
 * than the name so an administrator may rename or translate the label freely —
 * and it is inert until an administrator creates such a type, which is why an
 * empty table is a valid state rather than a broken one.
 *
 * Both use the shape the other lists use (2026_08_02, 2026_08_15), so both
 * arrive with the existing controller, policy, resource and admin screen.
 *
 * (Development and test databases get demonstration rows from
 * ReferenceDataSeeder, which production never runs.)
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

    }

    public function down(): void
    {
        Schema::dropIfExists('contract_types');
        Schema::dropIfExists('id_types');
    }
};
