<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\JournalSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits the four Phase 1 source types to `journal_entries.source_type`.
 *
 * `reserve_appropriation`, `reserve_utilisation`, `write_off` and `recovery`.
 * The column is a database ENUM, so adding cases to the PHP enum is not enough
 * — MySQL truncates anything the column does not list, and the close would fail
 * with "Data truncated for column 'source_type'". The same lesson as the
 * `transfer` migration, and the same remedy.
 *
 * Rebuilt from JournalSourceType::values() rather than a hand-written list, so
 * the column cannot drift from the enum it mirrors. That also means this
 * migration is idempotent in effect: running it against a database that already
 * has the values simply restates them.
 */
return new class extends Migration
{
    /**
     * The cases this migration introduces. Named explicitly so `down()` can
     * remove exactly these and leave anything a later migration adds alone.
     */
    private const array ADDED = [
        'reserve_appropriation',
        'reserve_utilisation',
        'write_off',
        'recovery',
    ];

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
