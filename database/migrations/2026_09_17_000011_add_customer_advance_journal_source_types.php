<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\JournalSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits the two customer-advance cases to `journal_entries.source_type`.
 *
 * Same remedy as the `transfer`, Phase 1 and `advance_consumption` migrations:
 * the column is a database ENUM, so adding the case to the PHP enum alone would
 * have MySQL truncate the value and the first advance issued would fail with
 * "Data truncated for column 'source_type'".
 */
return new class extends Migration
{
    private const array ADDED = ['customer_advance_issue', 'customer_advance_payment'];

    public function up(): void
    {
        $this->setSourceTypes(JournalSourceType::values());
    }

    public function down(): void
    {
        $this->setSourceTypes(
            array_values(array_filter(
                JournalSourceType::values(),
                static fn (string $v): bool => ! in_array($v, self::ADDED, true),
            )),
        );
    }

    /** @param list<string> $values */
    private function setSourceTypes(array $values): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'".addslashes($v)."'", $values));

        DB::statement("ALTER TABLE `journal_entries` MODIFY COLUMN `source_type` ENUM({$list}) NOT NULL");
    }
};
