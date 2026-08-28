<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things: a date that grandfathers existing customers, and the separation
 * of a private employer from a government sector.
 *
 * ── THE CUTOFF ────────────────────────────────────────────────────────────
 *
 * `requires_category_documents` alone is all-or-nothing, and all-or-nothing is
 * unusable here: `customer_documents` holds no rows, so switching it on makes
 * fifteen of the sixteen loan-eligible customers ineligible in the same
 * instant. Nobody collected those files because nothing ever asked for them.
 *
 * `category_documents_enforced_from` makes the switch a DATE rather than a
 * verdict, read against the customer's own `created_at`:
 *
 *   flag false                → nothing blocks. Today's behaviour.
 *   flag true,  cutoff NULL   → blocks for everyone, existing customers too.
 *   flag true,  cutoff set    → blocks only customers registered on or after
 *                               that date. Everyone already on the book keeps
 *                               what they have.
 *
 * The customer's real registration date, never a list of ids: a hardcoded
 * exemption list is a decision nobody can audit six months later, and it says
 * nothing about the customer registered tomorrow.
 *
 * Ships with the flag false and the cutoff null, so this migration changes no
 * customer's eligibility. Both are set from Administration when the business
 * decides.
 *
 * ── PRIVATE EMPLOYERS ARE NOT SECTORS ─────────────────────────────────────
 *
 * A public servant serves an employing BODY with cadres inside it — TAMISEMI,
 * then Teachers or Nurses. A private-sector employee works for a COMPANY, and
 * a company has no cadres. Filing both in `sectors` would put Kagera Sugar in
 * the same list a public servant picks their ministry from, and would imply a
 * second level that private employment does not have.
 *
 * So `employers` is its own admin-managed list, and `customer_categories`
 * gains `requires_employer` beside `requires_sector` — a category asks for one
 * or the other, and PRIVATE_SECTOR is switched over here. Nothing is seeded
 * into `employers`: which companies a branch lends against is the
 * institution's to decide, not this migration's to guess.
 *
 * `customers.employer` (free text) stays and is untouched. It holds what was
 * typed before this list existed, exactly as `work_type` stayed when
 * `work_type_id` arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->date('category_documents_enforced_from')
                ->nullable()
                ->after('requires_category_documents');
        });

        Schema::create('employers', function (Blueprint $table): void {
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

        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->boolean('requires_employer')->default(false)->after('requires_sector');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('employer_id')->nullable()->after('employer')
                ->constrained('employers')->nullOnDelete();
        });

        /*
         * A private-sector employee names their COMPANY, not a ministry. This
         * moves PRIVATE_SECTOR off the sector list and onto the employer list;
         * the contract and salary blocks it already asked for are unchanged.
         *
         * `sector_id` is left on any customer already carrying one rather than
         * nulled: it is what the branch recorded, and a migration is not the
         * place to decide it was wrong.
         */
        DB::table('customer_categories')->where('code', 'PRIVATE_SECTOR')->update([
            'requires_sector' => false,
            'requires_employer' => true,
        ]);
    }

    public function down(): void
    {
        DB::table('customer_categories')->where('code', 'PRIVATE_SECTOR')->update([
            'requires_sector' => true,
            'requires_employer' => false,
        ]);

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employer_id');
        });

        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropColumn('requires_employer');
        });

        Schema::dropIfExists('employers');

        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->dropColumn('category_documents_enforced_from');
        });
    }
};
