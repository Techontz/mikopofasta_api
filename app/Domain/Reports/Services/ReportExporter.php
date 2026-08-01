<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use RuntimeException;
use ZipArchive;

/**
 * A computed report as a file — CSV, Excel or PDF.
 *
 * ## Why these are written here rather than pulled in
 *
 * PhpSpreadsheet and Dompdf are the obvious choices and both were tried;
 * neither could be installed, because this environment can reach Packagist's
 * metadata but not GitHub, where the archives actually live. So the two formats
 * are written directly.
 *
 * That turned out to be a smaller cost than it sounds. An `.xlsx` is a ZIP of
 * five XML parts, and PHP ships `ZipArchive`; a PDF of a table is a handful of
 * objects and one content stream in a format that has not changed since 1993.
 * What is given up is styling depth and font embedding — neither of which a
 * report export needs — and what is gained is that the whole path is legible
 * and has no supply chain.
 *
 * If the libraries become installable later, the three `render*` methods are
 * the entire surface to replace; nothing else in the module knows how a file
 * is made.
 *
 * ## What is exported
 *
 * The rows the caller is looking at, after their filters, search and sort —
 * exporting the unfiltered report would hand somebody a file that does not
 * match the screen they exported it from. Totals ride along as a final row,
 * because a spreadsheet without them invites the reader to sum it themselves
 * and get a different answer.
 */
final class ReportExporter
{
    /** The formats `GET /reports/{slug}/export` accepts. */
    public const array FORMATS = ['csv', 'xlsx', 'pdf'];

    public function filename(Report $report, string $format, ReportFilters $filters): string
    {
        $parts = array_filter([
            $report->slug(),
            $filters->period,
            $filters->from !== null ? 'from-'.$filters->from : null,
            $filters->to !== null ? 'to-'.$filters->to : null,
            $filters->branchId !== null ? 'branch-'.$filters->branchId : null,
        ]);

        return implode('-', $parts).'.'.$format;
    }

    public function contentType(string $format): string
    {
        return match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'text/csv; charset=UTF-8',
        };
    }

    public function render(Report $report, ReportResult $result, string $format, ReportFilters $filters): string
    {
        return match ($format) {
            'xlsx' => $this->renderXlsx($report, $result),
            'pdf' => $this->renderPdf($report, $result, $filters),
            default => $this->renderCsv($result),
        };
    }

    // -----------------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------------

    private function renderCsv(ReportResult $result): string
    {
        $lines = [$this->csvRow(array_map(
            static fn (ReportColumn $c): string => $c->label,
            $result->columns,
        ))];

        foreach ($result->rows as $row) {
            $lines[] = $this->csvRow($this->cells($result->columns, $row));
        }

        if ($result->totals !== null) {
            $lines[] = $this->csvRow($this->cells($result->columns, $result->totals));
        }

        /*
         * A UTF-8 BOM. Without it Excel on Windows reads the file as the system
         * codepage and mangles every non-ASCII character — which in a Tanzanian
         * customer list means a good number of names. The frontend's own CSV
         * button has carried this for the same reason.
         */
        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /** @param list<string> $cells */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(static function (string $value): string {
            return preg_match('/[",\r\n]/', $value) === 1
                ? '"'.str_replace('"', '""', $value).'"'
                : $value;
        }, $cells));
    }

    // -----------------------------------------------------------------------
    // XLSX — a ZIP of SpreadsheetML parts
    // -----------------------------------------------------------------------

    private function renderXlsx(Report $report, ReportResult $result): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-').'.xlsx';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a temporary workbook for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRels());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook($report));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheet($result));
        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read the generated workbook.');
        }

        return $contents;
    }

    private function xlsxContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function xlsxRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function xlsxWorkbook(Report $report): string
    {
        // Excel refuses a sheet name over 31 characters or containing []:*?/\.
        $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $report->title()) ?? 'Report', 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->xml($name).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function xlsxWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Three styles: plain, bold (the header and the totals row), and a money
     * format so a spreadsheet shows 1,234.56 rather than 1234.56.
     */
    private function xlsxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function xlsxSheet(ReportResult $result): string
    {
        $rows = [];
        $r = 1;

        $rows[] = $this->xlsxRow($r++, array_map(
            static fn (ReportColumn $c): array => ['value' => $c->label, 'numeric' => false, 'bold' => true],
            $result->columns,
        ));

        foreach ($result->rows as $row) {
            $rows[] = $this->xlsxRow($r++, $this->xlsxCells($result->columns, $row, false));
        }

        if ($result->totals !== null) {
            $rows[] = $this->xlsxRow($r++, $this->xlsxCells($result->columns, $result->totals, true));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param list<ReportColumn> $columns
     * @param array<string, mixed> $row
     * @return list<array{value: string, numeric: bool, bold: bool}>
     */
    private function xlsxCells(array $columns, array $row, bool $bold): array
    {
        $cells = [];

        foreach ($columns as $column) {
            $raw = $row[$column->key] ?? '';
            $text = is_scalar($raw) ? (string) $raw : '';

            /*
             * Written as a number only when the column says it is one AND the
             * value actually parses. Reports use an em dash for "nothing here",
             * and writing that into a numeric cell makes Excel show #VALUE! for
             * a row that is perfectly fine.
             */
            $numeric = ($column->money || $column->align === 'right')
                && $text !== ''
                && is_numeric($text);

            $cells[] = ['value' => $text, 'numeric' => $numeric, 'bold' => $bold];
        }

        return $cells;
    }

    /** @param list<array{value: string, numeric: bool, bold: bool}> $cells */
    private function xlsxRow(int $rowNumber, array $cells): string
    {
        $xml = '<row r="'.$rowNumber.'">';

        foreach ($cells as $index => $cell) {
            $ref = $this->columnLetter($index).$rowNumber;
            $style = match (true) {
                $cell['numeric'] && $cell['bold'] => 3,
                $cell['numeric'] => 2,
                $cell['bold'] => 1,
                default => 0,
            };

            if ($cell['numeric']) {
                $xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.$this->xml($cell['value']).'</v></c>';

                continue;
            }

            // Inline strings rather than a shared-strings part: one fewer file
            // to keep consistent, and a report has no repetition worth pooling.
            $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                .$this->xml($cell['value']).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + $i % 26).$letter;
        }

        return $letter;
    }

    // -----------------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------------

    /**
     * A landscape A4 table.
     *
     * Landscape because a report is wide: eleven columns on portrait A4 leaves
     * about fifty points each, which is not enough for a money figure.
     *
     * Helvetica, which is one of the fourteen fonts every PDF reader is
     * required to have — so nothing is embedded and the file stays small. The
     * trade is that the text must be Latin-1; anything else is transliterated
     * rather than written as a byte the reader will render as a different glyph.
     */
    private function renderPdf(Report $report, ReportResult $result, ReportFilters $filters): string
    {
        $pageWidth = 842.0;   // A4 landscape, in points
        $pageHeight = 595.0;
        $margin = 28.0;
        $rowHeight = 14.0;

        $columns = $result->columns;
        $count = max(1, count($columns));
        $columnWidth = ($pageWidth - 2 * $margin) / $count;

        $bodyRows = $result->rows;

        if ($result->totals !== null) {
            $bodyRows[] = $result->totals;
        }

        // How many rows fit under the title block, per page.
        $firstPageRows = (int) floor(($pageHeight - $margin - 96 - $margin) / $rowHeight);
        $laterPageRows = (int) floor(($pageHeight - $margin - 40 - $margin) / $rowHeight);

        $pages = $this->paginateForPdf($bodyRows, max(1, $firstPageRows), max(1, $laterPageRows));

        $contents = [];

        foreach ($pages as $index => $pageRows) {
            $contents[] = $this->pdfPageContent(
                $report,
                $filters,
                $columns,
                $pageRows,
                $index === 0,
                $index + 1,
                count($pages),
                $margin,
                $pageHeight,
                $columnWidth,
                $rowHeight,
            );
        }

        return $this->pdfDocument($contents, $pageWidth, $pageHeight);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private function paginateForPdf(array $rows, int $first, int $later): array
    {
        if ($rows === []) {
            return [[]];
        }

        $pages = [array_slice($rows, 0, $first)];
        $rest = array_slice($rows, $first);

        foreach (array_chunk($rest, $later) as $chunk) {
            $pages[] = $chunk;
        }

        return $pages;
    }

    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function pdfPageContent(
        Report $report,
        ReportFilters $filters,
        array $columns,
        array $rows,
        bool $isFirst,
        int $pageNumber,
        int $pageCount,
        float $margin,
        float $pageHeight,
        float $columnWidth,
        float $rowHeight,
    ): string {
        $y = $pageHeight - $margin;
        $out = [];

        if ($isFirst) {
            $out[] = $this->pdfText($margin, $y - 16, 16, $report->title(), true);
            $out[] = $this->pdfText($margin, $y - 32, 9, $report->description());

            $applied = $filters->applied();
            $out[] = $this->pdfText(
                $margin,
                $y - 46,
                9,
                $applied === []
                    ? 'Filters: none'
                    : 'Filters: '.implode('  ', array_map(
                        static fn (string $k, string $v): string => $k.'='.$v,
                        array_keys($applied),
                        array_values($applied),
                    )),
            );

            $y -= 74;
        } else {
            $out[] = $this->pdfText($margin, $y - 12, 10, $report->title().' (continued)', true);
            $y -= 32;
        }

        // Header row.
        foreach ($columns as $i => $column) {
            $out[] = $this->pdfText($margin + $i * $columnWidth, $y, 8, $column->label, true);
        }

        $y -= 4;
        $out[] = $this->pdfLine($margin, $y, $margin + count($columns) * $columnWidth, $y);
        $y -= $rowHeight;

        foreach ($rows as $row) {
            foreach ($columns as $i => $column) {
                $value = $row[$column->key] ?? '';
                $out[] = $this->pdfText(
                    $margin + $i * $columnWidth,
                    $y,
                    8,
                    $this->truncate(is_scalar($value) ? (string) $value : '', $columnWidth),
                );
            }

            $y -= $rowHeight;
        }

        $out[] = $this->pdfText(
            $margin,
            $margin - 8,
            7,
            sprintf('Page %d of %d — generated %s', $pageNumber, $pageCount, date('Y-m-d H:i')),
        );

        return implode("\n", $out);
    }

    private function pdfText(float $x, float $y, float $size, string $text, bool $bold = false): string
    {
        return sprintf(
            'BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET',
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            $this->pdfString($text),
        );
    }

    private function pdfLine(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf('0.6 w %.1f %.1f m %.1f %.1f l S', $x1, $y1, $x2, $y2);
    }

    /**
     * Escapes a string for a PDF literal and drops anything Helvetica's
     * WinAnsi encoding cannot represent.
     *
     * The em dash reports use for "nothing here" is transliterated rather than
     * dropped, because a blank cell and a cell that says "no figure" mean
     * different things to a reader.
     */
    private function pdfString(string $text): string
    {
        $text = str_replace(['—', '–', '·', '’', '“', '”'], ['-', '-', '.', "'", '"', '"'], $text);
        $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($latin === false) {
            $latin = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $latin);
    }

    /** Roughly how many characters fit in a column at 8pt Helvetica. */
    private function truncate(string $text, float $columnWidth): string
    {
        $max = max(4, (int) floor($columnWidth / 4.2));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'.' : $text;
    }

    /** @param list<string> $contents */
    private function pdfDocument(array $contents, float $width, float $height): string
    {
        $pageCount = count($contents);

        // 1 catalog, 2 pages, 3..(2+n) page objects, then contents, then fonts.
        $firstPageObj = 3;
        $firstContentObj = $firstPageObj + $pageCount;
        $fontRegular = $firstContentObj + $pageCount;
        $fontBold = $fontRegular + 1;

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = implode(' ', array_map(
            static fn (int $i): string => ($i + $firstPageObj).' 0 R',
            range(0, $pageCount - 1),
        ));
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, $pageCount);

        foreach ($contents as $i => $content) {
            $objects[$firstPageObj + $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $width,
                $height,
                $fontRegular,
                $fontBold,
                $firstContentObj + $i,
            );

            $objects[$firstContentObj + $i] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content) + 1,
                $content,
            );
        }

        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));

        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            $max + 1,
            $xrefOffset,
        );

        return $pdf;
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<ReportColumn> $columns
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function cells(array $columns, array $row): array
    {
        return array_map(static function (ReportColumn $column) use ($row): string {
            $value = $row[$column->key] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $columns);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
