<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Standard Step 2 fields no longer asked of Mtumishi wa Umma: Basic Salary / Take Home already give the income and
     * Wizara / Idara / Kituo cha Kazi already give the place of employment.
     *
     * @var list<string>
     */
    private const MTUMISHI_OMITTED = ['place_of_employment', 'monthly_income'];

    private const VIBARUA = 'Vibarua';

    /**
     * Existing customer types are updated in place (merged, so other configuration an administrator changed is kept).
     */
    public function up(): void
    {
        DB::table('customer_categories')->where('code', 'WATUMISHI_WA_UMMA')->get(['id', 'omitted_standard_fields'])
            ->each(function (object $row): void {
                $omitted = (array) json_decode((string) $row->omitted_standard_fields, true);
                $merged = array_values(array_unique([...$omitted, ...self::MTUMISHI_OMITTED]));

                DB::table('customer_categories')->where('id', $row->id)->update(['omitted_standard_fields' => json_encode($merged)]);
            });

        $this->mapContractOptions(fn (array $options): array => in_array(self::VIBARUA, $options, true) ? $options : [...$options, self::VIBARUA]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('customer_categories')->where('code', 'WATUMISHI_WA_UMMA')->get(['id', 'omitted_standard_fields'])
            ->each(function (object $row): void {
                $omitted = array_values(array_diff((array) json_decode((string) $row->omitted_standard_fields, true), self::MTUMISHI_OMITTED));

                DB::table('customer_categories')->where('id', $row->id)->update(['omitted_standard_fields' => json_encode($omitted)]);
            });

        $this->mapContractOptions(fn (array $options): array => array_values(array_diff($options, [self::VIBARUA])));
    }

    /**
     * @param  callable(list<string>): list<string>  $map
     */
    private function mapContractOptions(callable $map): void
    {
        DB::table('customer_categories')->where('code', 'SEKTA_BINAFSI')->get(['id', 'dynamic_form_schema'])
            ->each(function (object $row) use ($map): void {
                $schema = json_decode((string) $row->dynamic_form_schema, true);
                if (! is_array($schema)) {
                    return;
                }

                foreach ($schema as $index => $field) {
                    if (is_array($field) && ($field['key'] ?? null) === 'sb_aina_mkataba' && is_array($field['options'] ?? null)) {
                        $schema[$index]['options'] = $map($field['options']);
                    }
                }

                DB::table('customer_categories')->where('id', $row->id)->update(['dynamic_form_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE)]);
            });
    }
};
