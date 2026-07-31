<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\JournalSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits `transfer` to `journal_entries.source_type`.
 *
 * The column is a database ENUM, so adding a case to the PHP enum is not
 * enough — MySQL truncates anything the column does not list, and every float
 * posting failed with "Data truncated for column 'source_type'". The two
 * definitions have to move together.
 *
 * Built from JournalSourceType::values() rather than a hand-written list, so
 * the column can never again drift from the enum it mirrors.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setSourceTypes(JournalSourceType::values());
    }

    public function down(): void
    {
        $this->setSourceTypes(
            array_values(array_filter(
                JournalSourceType::values(),
                static fn (string $v): bool => $v !== JournalSourceType::Transfer->value,
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
