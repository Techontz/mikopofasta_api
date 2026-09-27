<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Share holders get separate first / middle / last names and a passport photo; capital entries get an
     * uploaded receipt file. The original single `name` column is kept (nullable) as the legacy full name,
     * so no existing data is lost, and is kept in sync with the three parts by the model.
     */
    public function up(): void
    {
        Schema::table('share_holders', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('company_id');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('passport_photo')->nullable()->after('date_of_birth');
            $table->string('name')->nullable()->change();
        });

        Schema::table('capitals', function (Blueprint $table) {
            $table->string('receipt_file')->nullable()->after('cheque_number');
            $table->string('receipt_file_name')->nullable()->after('receipt_file');
        });

        DB::table('share_holders')->whereNull('first_name')->orderBy('id')->each(function (object $holder): void {
            DB::table('share_holders')->where('id', $holder->id)->update(self::splitName((string) $holder->name));
        });
    }

    /**
     * Legacy full names: one word → first name only; two words → first + last; three or more words → first,
     * last = final word, middle = the words in between. Nothing is invented when a part is absent.
     *
     * @return array{first_name: string|null, middle_name: string|null, last_name: string|null}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'first_name' => $parts[0] ?? null,
            'middle_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null,
            'last_name' => count($parts) > 1 ? $parts[count($parts) - 1] : null,
        ];
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('share_holders')->whereNull('name')->orderBy('id')->each(function (object $holder): void {
            DB::table('share_holders')->where('id', $holder->id)->update([
                'name' => trim(implode(' ', array_filter([$holder->first_name, $holder->middle_name, $holder->last_name]))),
            ]);
        });

        Schema::table('capitals', function (Blueprint $table) {
            $table->dropColumn(['receipt_file', 'receipt_file_name']);
        });

        Schema::table('share_holders', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'passport_photo']);
            $table->string('name')->nullable(false)->change();
        });
    }
};
