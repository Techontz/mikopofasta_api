<?php

namespace App\Services\LegacyImports;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads an uploaded CSV or Excel (.xlsx) file into rows of trimmed text cells, exactly as they stand in the file.
 *
 * Nothing is interpreted here: amounts, dates and names stay text for {@see LegacyRowReader} to read and, when they cannot
 * be read, to report by row and column. Of an .xlsx workbook only the first sheet is read; a date typed into Excel arrives
 * as its serial number (e.g. 46255), which the row reader turns back into a date.
 */
final class SpreadsheetReader
{
    /**
     * @return list<list<string>>
     */
    public static function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => self::xlsx($path),
            'csv', 'txt' => self::csv($path),
            default => throw new RuntimeException('Upload a CSV (.csv) or Excel (.xlsx) file. An old .xls file must first be saved as .xlsx or .csv.'),
        };
    }

    /**
     * @return list<list<string>>
     */
    private static function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn (?string $cell): string => trim((string) $cell), $cells);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Excel file could not be opened. Save it again as .xlsx or .csv and upload it.');
        }

        try {
            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach (self::xml($xml)->si as $item) {
                    $shared[] = self::text($item);
                }
            }

            $sheet = $zip->getFromName(self::firstSheet($zip));
            if ($sheet === false) {
                throw new RuntimeException('The Excel file has no worksheet to read.');
            }

            $rows = [];
            foreach (self::xml($sheet)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $index = self::columnIndex((string) $cell['r']) ?? count($cells);
                    $type = (string) $cell['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $cell->v] ?? '',
                        'inlineStr' => self::text($cell->is),
                        default => (string) $cell->v,
                    };
                    $cells[$index] = trim($value);
                }
                $line = [];
                if ($cells !== []) {
                    for ($column = 0, $last = max(array_keys($cells)); $column <= $last; $column++) {
                        $line[] = $cells[$column] ?? '';
                    }
                }
                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * The path of the workbook's first sheet, following the workbook relationships (it is not always sheet1.xml).
     */
    private static function firstSheet(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relations === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $first = self::xml($workbook)->sheets->sheet[0] ?? null;
        $id = $first?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? null;
        foreach (self::xml($relations)->Relationship as $relation) {
            if ((string) $relation['Id'] === (string) $id) {
                $target = ltrim((string) $relation['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('The Excel file is damaged and could not be read.');
        }

        return $xml;
    }

    /**
     * A string item: plain text, or rich text split into runs.
     */
    private static function text(?SimpleXMLElement $item): string
    {
        if ($item === null) {
            return '';
        }
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * "C12" → 2 (zero-based column of a cell reference).
     */
    private static function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)\d*$/', strtoupper($reference), $match)) {
            return null;
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
