<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reference lists the five customer types ask from, in the depths they
 * actually have.
 *
 * WHAT WAS MISSING. Registration asks a Public Servant for their ministry,
 * then the department inside it, then the cadre inside that — three levels.
 * The only parented list this application had was sector → sector category,
 * which is two, and there was exactly one of it. So three of the five customer
 * types could not be expressed as configuration at all: a "Cheo" dropdown
 * could either offer every cadre in government, or be narrowed by a department
 * the model had nowhere to put.
 *
 * A TABLE PER LEVEL, AND A SEPARATE CHAIN PER SECTOR. Public bodies and
 * private companies are not one list with a flag: a ministry has departments
 * with cadres inside them, a company has its own, and merging them would offer
 * a public servant a sugar mill to serve in — the same reasoning that already
 * keeps `sectors` and `employers` apart. So:
 *
 *   government_bodies  -> government_departments -> government_cadres
 *   private_sectors    -> private_employers                             (company)
 *                      -> private_departments    -> private_cadres      (role)
 *   business_sectors   -> business_types
 *   colleges           -> courses
 *   pension_funds                                                       (flat)
 *
 * `private_employers` and `private_departments` are siblings under a sector,
 * not parent and child, because the source asks for the company and the
 * department independently — a Bank's "Rasilimali Watu" is the same department
 * whichever bank it is.
 *
 * THE SAME SHAPE AS EVERY OTHER LIST (see 2026_08_02 and 2026_08_30), so all
 * of these ride on the existing MasterDataModel, controller, policy, resource
 * and Administration screens. A new ministry is a data change, never a
 * deployment.
 *
 * STRUCTURE ONLY — NO ROWS, exactly as the sector tables do it. What an
 * institution lends against is its own to decide. CustomerTypeReferenceSeeder
 * loads the register that ships with the project for development and demo
 * databases.
 */
return new class extends Migration
{
    /**
     * The columns every one of these lists has — see MasterDataModel.
     *
     * Index names are given explicitly. Laravel derives them from the table
     * and every column, and `government_departments` + `government_body_id` +
     * `is_active` + `sort_order` is past MySQL's 64-character identifier
     * limit, which fails the migration rather than truncating.
     */
    private function lookup(Blueprint $table, string $name, ?string $parent = null, ?string $parentTable = null): void
    {
        $table->id();

        if ($parent !== null) {
            /* Restrict, never cascade: a parent is soft-deleted and its
               children must survive to keep readable the customers already
               filed under them. */
            $table->foreignId($parent)->constrained($parentTable)->restrictOnDelete();
        }

        $table->string('code', 60);
        $table->string('name', 190);
        $table->string('description', 255)->nullable();
        $table->unsignedSmallInteger('sort_order')->nullable();
        $table->boolean('is_active')->default(true);
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->softDeletes();

        if ($parent === null) {
            $table->unique('code', "{$name}_code_unq");
            $table->index(['is_active', 'sort_order'], "{$name}_active_idx");
        } else {
            /* Unique WITHIN the parent: two ministries may each have an
               "Utawala" department and they are not the same department. */
            $table->unique([$parent, 'code'], "{$name}_parent_code_unq");
            $table->index([$parent, 'is_active', 'sort_order'], "{$name}_parent_active_idx");
        }
    }

    public function up(): void
    {
        // ---- Mtumishi wa Umma, and Mstaafu (Umma), which reads the same three
        Schema::create('government_bodies', fn (Blueprint $t) => $this->lookup($t, 'gov_body'));
        Schema::create('government_departments', fn (Blueprint $t) => $this->lookup($t, 'gov_dept', 'government_body_id', 'government_bodies'));
        Schema::create('government_cadres', fn (Blueprint $t) => $this->lookup($t, 'gov_cadre', 'government_department_id', 'government_departments'));

        // ---- Sekta Binafsi
        Schema::create('private_sectors', fn (Blueprint $t) => $this->lookup($t, 'priv_sector'));
        Schema::create('private_employers', fn (Blueprint $t) => $this->lookup($t, 'priv_employer', 'private_sector_id', 'private_sectors'));
        Schema::create('private_departments', fn (Blueprint $t) => $this->lookup($t, 'priv_dept', 'private_sector_id', 'private_sectors'));
        Schema::create('private_cadres', fn (Blueprint $t) => $this->lookup($t, 'priv_cadre', 'private_department_id', 'private_departments'));

        // ---- Mjasiriamali/Mfanyabiashara
        Schema::create('business_sectors', fn (Blueprint $t) => $this->lookup($t, 'biz_sector'));
        Schema::create('business_types', fn (Blueprint $t) => $this->lookup($t, 'biz_type', 'business_sector_id', 'business_sectors'));

        // ---- Mwanafunzi wa Chuo
        Schema::create('colleges', fn (Blueprint $t) => $this->lookup($t, 'college'));
        Schema::create('courses', fn (Blueprint $t) => $this->lookup($t, 'course', 'college_id', 'colleges'));

        // ---- Mstaafu (Umma)
        Schema::create('pension_funds', fn (Blueprint $t) => $this->lookup($t, 'pension_fund'));
    }

    public function down(): void
    {
        foreach ([
            'pension_funds', 'courses', 'colleges', 'business_types', 'business_sectors',
            'private_cadres', 'private_departments', 'private_employers', 'private_sectors',
            'government_cadres', 'government_departments', 'government_bodies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
