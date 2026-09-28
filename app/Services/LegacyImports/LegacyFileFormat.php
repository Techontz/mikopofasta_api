<?php

namespace App\Services\LegacyImports;

use App\Models\LegacyImport;
use RuntimeException;

/**
 * The three files the old system hands over, and the columns each must have.
 *
 * The headers follow the old system's own screens, so an exported file can be corrected and uploaded back unchanged. Two
 * are tidied and the old forms still accepted: the misspelt "Carger" is written as "Charges" ({@see self::ALIASES}), and
 * the combined "Date Alert" column of the Active Salary Advance list is written as separate Date and Alert columns
 * ({@see self::OLD_LAYOUTS}). A file whose headers do not match is refused outright
 * rather than guessed at, because a column read into the wrong field would move a customer's balance.
 */
final class LegacyFileFormat
{
    /**
     * Active Salary Advance: the columns of the Active Salary Advance list. Alert (NEW / OLD) is shown for reading only;
     * the import works it out again from the Date.
     */
    public const SALARY_ADVANCE = [
        'No.', 'Customer Name', 'Branch Name', 'Loan Amount', 'Interest', 'Principal + Interest',
        'Paid Amount', 'Remain Amount', 'Status', 'Charges', 'Date', 'Alert',
    ];

    /**
     * Earlier column layouts still accepted on import: the old system's Active Salary Advance list had one "Date Alert"
     * column (e.g. "2026-08-01 old").
     *
     * @var array<string, list<list<string>>>
     */
    public const OLD_LAYOUTS = [
        LegacyImport::MODULE_SALARY_ADVANCE => [[
            'No.', 'Customer Name', 'Branch Name', 'Loan Amount', 'Interest', 'Principal + Interest',
            'Paid Amount', 'Remain Amount', 'Status', 'Charges', 'Date Alert',
        ]],
    ];

    /**
     * Penalty List: the columns of the Penalty List.
     */
    public const PENALTY = [
        'No.', 'Customer Name', 'Branch Name', 'Loan Amount', 'Penalty Amount', 'Date', 'Accounting',
    ];

    /**
     * Loan File: the columns of the File report, with one column per month January to September.
     */
    public const LOAN = [
        'No.', 'Branch Name', 'Customer Name', 'Phone Number', 'Loan Amount', 'Duration Type / Number',
        'Collection', 'Paid Amount', 'Remain Amount', 'Withdrawal Date', 'Loan Status',
        'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September',
    ];

    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September'];

    /**
     * Columns the exception file adds after the module's own, so a corrected exception file can be uploaded straight back:
     * they are recognised and ignored.
     */
    public const EXCEPTION_COLUMNS = ['Import Row', 'Import Status', 'Import Reason'];

    /**
     * The old system's screens end with an Action column (their buttons). It holds no data, so it is never exported, and
     * a file that still has it as its last column is accepted: the column is ignored.
     */
    public const SCREEN_ONLY_COLUMN = 'Action';

    /**
     * Other spellings a column is accepted under: the old system printed "Carger", and imports saved before the rename keep
     * their cells under that name.
     */
    public const ALIASES = ['Charges' => ['Carger', 'Charger'], 'Date' => ['Date Alert']];

    /**
     * How many rows from the top are searched for the header row (a printout often carries a title line above it).
     */
    private const HEADER_SEARCH_ROWS = 15;

    /**
     * The columns of a module, in order.
     *
     * @return list<string>
     */
    public static function headers(string $module): array
    {
        return match ($module) {
            LegacyImport::MODULE_SALARY_ADVANCE => self::SALARY_ADVANCE,
            LegacyImport::MODULE_PENALTY => self::PENALTY,
            LegacyImport::MODULE_LOAN => self::LOAN,
            default => throw new RuntimeException("Unknown legacy import module: {$module}."),
        };
    }

    /**
     * What the file is called on screen and in the refusal message.
     */
    public static function label(string $module): string
    {
        return match ($module) {
            LegacyImport::MODULE_SALARY_ADVANCE => 'Active Salary Advance',
            LegacyImport::MODULE_PENALTY => 'Penalty List',
            LegacyImport::MODULE_LOAN => 'Loan File',
            default => $module,
        };
    }

    /**
     * Whether the uploaded header row is the module's, ignoring case, spacing and surrounding punctuation so a file
     * that has been through a spreadsheet still passes.
     *
     * @param  list<string>  $uploaded
     */
    public static function matches(string $module, array $uploaded): bool
    {
        return self::layout($module, $uploaded) !== null;
    }

    /**
     * The column layout an uploaded header row follows — the current one or an accepted earlier one — or null.
     *
     * @param  list<string>  $uploaded
     * @return list<string>|null
     */
    public static function layout(string $module, array $uploaded): ?array
    {
        $cells = array_map(self::canonical(...), self::significant($uploaded));
        foreach ([self::headers($module), ...(self::OLD_LAYOUTS[$module] ?? [])] as $layout) {
            if ($cells === array_map(self::canonical(...), $layout)) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * The index of the header row among the first rows of a file, or null when none of them is the module's header.
     *
     * @param  list<list<string>>  $rows
     */
    public static function locateHeader(string $module, array $rows): ?int
    {
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            if (self::matches($module, $row)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The header row as it counts: without trailing empty cells, the exception file's own columns and the old system's
     * trailing Action column.
     *
     * @param  list<string>  $uploaded
     * @return list<string>
     */
    private static function significant(array $uploaded): array
    {
        $cells = array_values($uploaded);
        while ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        $extra = array_map(self::normalise(...), self::EXCEPTION_COLUMNS);
        $tail = array_map(self::normalise(...), array_slice($cells, -count($extra)));
        if ($tail === $extra) {
            $cells = array_slice($cells, 0, -count($extra));
        }
        if ($cells !== [] && self::normalise((string) end($cells)) === self::normalise(self::SCREEN_ONLY_COLUMN)) {
            array_pop($cells);
        }

        return $cells;
    }

    /**
     * Why a header row was refused, naming the first column that is wrong so the file can be corrected.
     *
     * @param  list<string>  $uploaded
     */
    public static function mismatchReason(string $module, array $uploaded): string
    {
        $expected = self::headers($module);
        $label = self::label($module);
        $uploaded = self::significant($uploaded);
        if (count($uploaded) !== count($expected)) {
            return sprintf('Invalid file format for %s Import. The file has %d columns; %d are expected: %s.', $label, count($uploaded), count($expected), implode(', ', $expected));
        }

        foreach ($expected as $index => $header) {
            if (self::canonical($uploaded[$index] ?? '') !== self::canonical($header)) {
                return sprintf('Invalid file format for %s Import. Column %d is "%s"; "%s" is expected.', $label, $index + 1, $uploaded[$index] ?? '', $header);
            }
        }

        return sprintf('Invalid file format for %s Import.', $label);
    }

    /**
     * A saved row's cell of a column, found under the column's name or any of its old spellings.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function cell(array $raw, string $header): string
    {
        foreach ([$header, ...(self::ALIASES[$header] ?? [])] as $name) {
            if (array_key_exists($name, $raw)) {
                return (string) $raw[$name];
            }
        }

        return '';
    }

    /**
     * A header as it is compared: normalised, with an old spelling replaced by the column's name.
     */
    private static function canonical(string $header): string
    {
        $normalised = self::normalise($header);
        foreach (self::ALIASES as $name => $aliases) {
            if (in_array($normalised, array_map(self::normalise(...), $aliases), true)) {
                return self::normalise($name);
            }
        }

        return $normalised;
    }

    private static function normalise(string $header): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace(['_', '.'], [' ', ''], $header))));
    }
}
