<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy main category codes (live admin/main_loan_category: ent = WATUMISHI, ser = WAJASILIAMALI) → customer type code.
     *
     * @var array<string, string>
     */
    private const LEGACY_CODES = ['ent' => 'WATUMISHI_WA_UMMA', 'ser' => 'WAJASIRIAMALI'];

    /**
     * Legacy main category names used when a row carries an unknown code.
     *
     * @var array<string, string>
     */
    private const LEGACY_NAMES = ['WATUMISHI' => 'WATUMISHI_WA_UMMA', 'WAJASILIAMALI' => 'WAJASIRIAMALI'];

    /**
     * Business model: CUSTOMER TYPE (customer_categories) → MAIN LOAN CATEGORY (main_categories, 1:1) → LOAN CATEGORY.
     *
     * Adds main_categories.customer_category_id (NOT NULL FK, unique per company). Existing rows are mapped per company by
     * code (ent → Mtumishi wa Umma, ser → Mjasiriamali/Mfanyabiashara; ids kept, so loan_categories and loans stay valid),
     * every other customer type gets its own main category, and each main category name is copied from its customer type.
     * Idempotent: a customer type is never given a second main category.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('main_categories', 'customer_category_id')) {
            Schema::table('main_categories', function (Blueprint $table) {
                $table->foreignId('customer_category_id')->nullable()->after('company_id')->constrained('customer_categories')->restrictOnDelete();
                $table->unique(['company_id', 'customer_category_id']);
            });
        }

        DB::transaction(function (): void {
            foreach (DB::table('companies')->pluck('id') as $companyId) {
                $this->linkCompany((int) $companyId);
            }
        });

        $unlinked = DB::table('main_categories')->whereNull('customer_category_id')->pluck('name', 'id');
        if ($unlinked->isNotEmpty()) {
            throw new RuntimeException('Main loan categories without a matching customer type: '.$unlinked->map(fn ($name, $id): string => "#{$id} {$name}")->implode(', ').'. Link them to a customer type before migrating.');
        }

        Schema::table('main_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_category_id')->nullable(false)->change();
        });
    }

    private function linkCompany(int $companyId): void
    {
        $types = DB::table('customer_categories')->where('company_id', $companyId)->whereNotNull('code')->get()->keyBy('code');

        foreach (DB::table('main_categories')->where('company_id', $companyId)->whereNull('customer_category_id')->orderBy('id')->get() as $main) {
            $code = self::LEGACY_CODES[$main->code] ?? self::LEGACY_NAMES[strtoupper(trim($main->name))] ?? $main->code;
            $type = $types->get($code) ?? $types->first(fn (object $candidate): bool => strcasecmp($candidate->name, $main->name) === 0);
            $taken = $type !== null && DB::table('main_categories')->where('company_id', $companyId)->where('customer_category_id', $type->id)->exists();

            if ($type !== null && ! $taken) {
                DB::table('main_categories')->where('id', $main->id)->update(['customer_category_id' => $type->id, 'name' => $type->name]);
            }
        }

        foreach ($types->whereNull('deleted_at') as $type) {
            $linked = DB::table('main_categories')->where('company_id', $companyId)->where('customer_category_id', $type->id)->first();

            if ($linked === null) {
                DB::table('main_categories')->insert([
                    'company_id' => $companyId,
                    'customer_category_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'is_enabled' => (bool) $type->is_active,
                ]);
            } elseif ($linked->name !== $type->name) {
                DB::table('main_categories')->where('id', $linked->id)->update(['name' => $type->name]);
            }
        }
    }

    /**
     * Drops the link; main categories created here that hold no loan category are removed and the legacy names restored.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('main_categories', 'customer_category_id')) {
            return;
        }

        DB::table('main_categories')
            ->whereNotIn('code', array_keys(self::LEGACY_CODES))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('loan_categories')->whereColumn('loan_categories.main_category_id', 'main_categories.id'))
            ->delete();
        DB::table('main_categories')->where('code', 'ent')->update(['name' => 'WATUMISHI']);
        DB::table('main_categories')->where('code', 'ser')->update(['name' => 'WAJASILIAMALI']);

        $companyIndexed = collect(Schema::getIndexes('main_categories'))->contains(fn (array $index): bool => $index['columns'] === ['company_id']);
        Schema::table('main_categories', function (Blueprint $table) use ($companyIndexed) {
            $table->dropForeign(['customer_category_id']);
            // MySQL may fold the company_id foreign key index into the composite unique index: give it its own index back first.
            if (! $companyIndexed) {
                $table->index('company_id', 'main_categories_company_id_foreign');
            }
        });
        Schema::table('main_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'customer_category_id']);
            $table->dropColumn('customer_category_id');
        });
    }
};
