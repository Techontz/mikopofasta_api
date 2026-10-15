<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * On MariaDB, and on MySQL with explicit_defaults_for_timestamp off, the first NOT NULL TIMESTAMP column of a table is created
 * with ON UPDATE CURRENT_TIMESTAMP: any later update of the row (e.g. linking a dividend payment to its journal entry) silently
 * replaced the event's own time — when a dividend was paid, a face scanned, a loan assessed, shares transacted. Every such
 * column (other than updated_at) keeps its value and its default, without the automatic change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = DB::select("SELECT table_name AS t, column_name AS c, is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type = 'timestamp' AND LOWER(extra) LIKE '%on update%' AND column_name <> 'updated_at'");

        foreach ($columns as $column) {
            $null = $column->n === 'YES' ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE `{$column->t}` MODIFY `{$column->c}` TIMESTAMP {$null} DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // Restoring the automatic change would bring the bug back; nothing to undo.
    }
};
