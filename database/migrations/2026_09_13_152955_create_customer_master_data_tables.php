<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flat master-data lists used by customer registration (CUSTOMER_MODULE_IMPLEMENTATION.md §1.3).
     *
     * @var list<string>
     */
    public const FLAT = [
        'banks', 'mobile_money_providers', 'marital_statuses', 'id_types', 'document_types',
        'government_bodies', 'private_sectors', 'business_sectors', 'colleges', 'pension_funds',
    ];

    /**
     * Parented master-data lists: table => [parent table, parent column].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PARENTED = [
        'government_departments' => ['government_bodies', 'government_body_id'],
        'government_cadres' => ['government_departments', 'government_department_id'],
        'private_employers' => ['private_sectors', 'private_sector_id'],
        'private_departments' => ['private_sectors', 'private_sector_id'],
        'private_cadres' => ['private_departments', 'private_department_id'],
        'business_types' => ['business_sectors', 'business_sector_id'],
        'courses' => ['colleges', 'college_id'],
    ];

    /**
     * Master data is shared reference data (not company-scoped), like `regions`.
     * Geography gains districts and wards under the existing regions table.
     */
    public function up(): void
    {
        foreach (self::FLAT as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->string('code', 80)->unique();
                $blueprint->string('name');
                $blueprint->text('description')->nullable();
                $blueprint->unsignedInteger('sort_order')->default(0);
                $blueprint->boolean('is_active')->default(true);
                $blueprint->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
                $blueprint->timestamps();
                $blueprint->softDeletes();
            });
        }

        foreach (self::PARENTED as $table => [$parentTable, $parentColumn]) {
            Schema::create($table, function (Blueprint $blueprint) use ($parentTable, $parentColumn) {
                $blueprint->id();
                $blueprint->foreignId($parentColumn)->constrained($parentTable)->cascadeOnDelete();
                $blueprint->string('code', 80);
                $blueprint->string('name');
                $blueprint->text('description')->nullable();
                $blueprint->unsignedInteger('sort_order')->default(0);
                $blueprint->boolean('is_active')->default(true);
                $blueprint->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
                $blueprint->timestamps();
                $blueprint->softDeletes();
                $blueprint->unique([$parentColumn, 'code']);
            });
        }

        Schema::table('regions', function (Blueprint $table) {
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['region_id', 'name']);
        });

        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['district_id', 'name']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_head_office')->default(false)->after('type');
        });

        DB::table('branches')->whereRaw('LOWER(TRIM(name)) = ?', ['head office'])->update(['is_head_office' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_head_office');
        });

        Schema::dropIfExists('wards');
        Schema::dropIfExists('districts');

        Schema::table('regions', function (Blueprint $table) {
            $table->dropTimestamps();
        });

        foreach (array_reverse(array_keys(self::PARENTED)) as $table) {
            Schema::dropIfExists($table);
        }
        foreach (array_reverse(self::FLAT) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
