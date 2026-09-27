<?php

namespace App\Services\LegacyImports;

use RuntimeException;
use ZipArchive;

/**
 * Writes rows into a one-sheet Excel (.xlsx) workbook, the format every spreadsheet opens alike: Excel on Windows and
 * Mac, Numbers, Google Sheets and the phone apps. A CSV depends on each program's guess of delimiter and encoding.
 *
 * The first row is the header (bold, frozen). A cell that is a plain amount (no leading zero, at most 10 digits before
 * the point) is written as a number so it can be summed; everything else — names, dates, phone numbers — is text, so a
 * phone number keeps its leading zero and a date stays exactly as {@see SpreadsheetReader} reads it back.
 */
final class SpreadsheetWriter
{
    /**
     * @param  list<list<string>>  $rows
     */
    public static function xlsx(array $rows, string $sheetName = 'Sheet1'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel file could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($rows));
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private static function sheet(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $column => $value) {
                $widths[$column] = max($widths[$column] ?? 8, min(50, mb_strlen($value) + 2));
            }
        }

        $cols = '';
        foreach ($widths as $column => $width) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $column + 1, $width);
        }

        $data = '';
        foreach (array_values($rows) as $index => $row) {
            $number = $index + 1;
            $data .= '<row r="'.$number.'">';
            foreach (array_values($row) as $column => $value) {
                $reference = self::columnLetters($column).$number;
                $style = $index === 0 ? ' s="1"' : '';
                if ($value === '') {
                    continue;
                }
                $data .= $index > 0 && preg_match('/^-?(0|[1-9]\d{0,9})(\.\d+)?$/', $value)
                    ? '<c r="'.$reference.'"'.$style.'><v>'.$value.'</v></c>'
                    : '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>';
            }
            $data .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols === '' ? '' : '<cols>'.$cols.'</cols>')
            .'<sheetData>'.$data.'</sheetData>'
            .'</worksheet>';
    }

    /**
     * 0 → "A", 26 → "AA".
     */
    private static function columnLetters(int $index): string
    {
        $letters = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * Excel refuses a sheet name over 31 characters or holding : \ / ? * [ ].
     */
    private static function sheetName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $name)) ?: 'Sheet1', 0, 31);
    }

    private static function escape(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
