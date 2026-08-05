<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\JournalSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits `advance_consumption` to `journal_entries.source_type`.
 *
 * The column is a database ENUM, so adding the case to the PHP enum is not
 * enough — MySQL truncates anything the column does not list, and the first
 * advance applied at a due date would fail with "Data truncated for column
 * 'source_type'". Third time this lesson has been learned in this schema; same
 * remedy as the `transfer` and Phase 1 migrations.
 *
 * Rebuilt from JournalSourceType::values() so the column cannot drift from the
 * enum it mirrors.
 */
return new class extends Migration
{
    private const array ADDED = ['advance_consumption'];

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
