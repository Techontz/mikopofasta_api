<?php

namespace Tests\Unit\LegacyImports;

use App\Enums\Duration;
use App\Models\LegacyImport;
use App\Services\LegacyImports\LegacyFileFormat;
use App\Services\LegacyImports\LegacyRowReader;
use App\Services\LegacyImports\SpreadsheetReader;
use App\Services\LegacyImports\SpreadsheetWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class LegacyFileReadingTest extends TestCase
{
    public function test_an_excel_workbook_is_read_cell_by_cell_including_shared_strings_and_gaps(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Penalty" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/data.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>No.</t></si><si><t>Customer Name</t></si><si><r><t>JANE </t></r><r><t>SMITH</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2"><v>1</v></c><c r="B2" t="s"><v>2</v></c><c r="D2"><v>46255</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $rows = SpreadsheetReader::read($path, 'penalty.xlsx');
        unlink($path);

        $this->assertSame([['No.', 'Customer Name'], ['1', 'JANE SMITH', '', '46255']], $rows);
        $this->assertSame('2026-08-21', LegacyRowReader::date('46255')?->toDateString(), 'An Excel date serial is read back as the date.');
    }

    public function test_a_csv_title_line_above_the_header_is_skipped_and_the_exception_columns_are_accepted(): void
    {
        $rows = [['PENALTY LIST - KARIAKOO'], [...LegacyFileFormat::PENALTY, ...LegacyFileFormat::EXCEPTION_COLUMNS, '']];

        $this->assertSame(1, LegacyFileFormat::locateHeader(LegacyImport::MODULE_PENALTY, $rows));
        $this->assertNull(LegacyFileFormat::locateHeader(LegacyImport::MODULE_SALARY_ADVANCE, $rows));
    }

    public function test_a_written_workbook_reads_back_cell_for_cell(): void
    {
        $rows = [['No.', 'Customer Name', 'Phone Number', 'Loan Amount', 'Date'], ['1', 'JOHN & SONS <LTD>', '0712345678', '1200000.50', '2026-08-20'], ['2', '', '255712345678', '-5000', '']];
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, SpreadsheetWriter::xlsx($rows, 'Loan File: Kariakoo'));

        $read = SpreadsheetReader::read($path, 'loan.xlsx');
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertSame([$rows[0], $rows[1], ['2', '', '255712345678', '-5000']], $read);
        $this->assertStringContainsString('<c r="D2"><v>1200000.50</v></c>', $sheet, 'An amount is a number, so it can be summed.');
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">255712345678</t>', $sheet, 'A phone number stays text.');
    }

    public function test_the_old_systems_trailing_action_column_is_accepted_but_never_expected(): void
    {
        $this->assertNotContains('Action', LegacyFileFormat::SALARY_ADVANCE);
        $this->assertNotContains('Action', LegacyFileFormat::PENALTY);
        $this->assertTrue(LegacyFileFormat::matches(LegacyImport::MODULE_SALARY_ADVANCE, [...LegacyFileFormat::SALARY_ADVANCE, 'Action']));
        $this->assertTrue(LegacyFileFormat::matches(LegacyImport::MODULE_PENALTY, [...LegacyFileFormat::PENALTY, 'Action', ...LegacyFileFormat::EXCEPTION_COLUMNS]));
        $this->assertTrue(LegacyFileFormat::matches(LegacyImport::MODULE_PENALTY, LegacyFileFormat::PENALTY));
    }

    public function test_the_old_systems_carger_column_is_written_as_charges_and_still_accepted(): void
    {
        $this->assertContains('Charges', LegacyFileFormat::SALARY_ADVANCE);
        $this->assertNotContains('Carger', LegacyFileFormat::SALARY_ADVANCE);

        $old = array_map(fn (string $header): string => $header === 'Charges' ? 'Carger' : $header, LegacyFileFormat::SALARY_ADVANCE);
        $this->assertTrue(LegacyFileFormat::matches(LegacyImport::MODULE_SALARY_ADVANCE, [...$old, 'Action']));
        $this->assertSame('5000', LegacyFileFormat::cell(['Carger' => '5000'], 'Charges'), 'An import saved before the rename keeps its value.');
        $this->assertSame('7000', LegacyFileFormat::cell(['Charges' => '7000'], 'Charges'));
    }

    public function test_the_old_combined_date_alert_column_is_still_accepted(): void
    {
        $old = ['No.', 'Customer Name', 'Branch Name', 'Loan Amount', 'Interest', 'Principal + Interest', 'Paid Amount', 'Remain Amount', 'Status', 'Carger', 'Date Alert', 'Action'];

        $this->assertSame(LegacyFileFormat::OLD_LAYOUTS[LegacyImport::MODULE_SALARY_ADVANCE][0], LegacyFileFormat::layout(LegacyImport::MODULE_SALARY_ADVANCE, $old));
        $this->assertSame(LegacyFileFormat::SALARY_ADVANCE, LegacyFileFormat::layout(LegacyImport::MODULE_SALARY_ADVANCE, LegacyFileFormat::SALARY_ADVANCE));
        $this->assertSame('2026-08-01 old', LegacyFileFormat::cell(['Date Alert' => '2026-08-01 old'], 'Date'));

        $read = LegacyRowReader::read(LegacyImport::MODULE_SALARY_ADVANCE, ['1', 'JOHN SMITH', 'Kariakoo', '300000', '30000', '330000', '230000', '100000', 'Active', '5000', '2026-08-01 old', '']);
        $this->assertSame([], $read['errors']);
        $this->assertSame([], $read['warnings'], 'The alert after the date is not mistaken for part of the date.');
    }

    public function test_values_are_read_as_printed(): void
    {
        $errors = [];
        $this->assertSame(1200000.0, LegacyRowReader::amount('1,200,000', 'Loan Amount', $errors));
        $this->assertSame(1200000.0, LegacyRowReader::amount('TSh 1,200,000/=', 'Loan Amount', $errors));
        $this->assertSame(-5000.0, LegacyRowReader::amount('(5,000)', 'Penalty Amount', $errors));
        $this->assertNull(LegacyRowReader::amount('', 'Collection', $errors));
        $this->assertSame([], $errors);
        $this->assertNull(LegacyRowReader::amount('abc', 'Remain Amount', $errors, label: 'Invalid Remain Amount'));
        $this->assertSame(['Invalid Remain Amount: "abc" is not an amount.'], $errors);

        $this->assertSame('2026-08-20', LegacyRowReader::date('20/08/2026')?->toDateString());
        $this->assertSame('2026-08-01', LegacyRowReader::date('1/8/2026')?->toDateString());
        $this->assertSame('2026-08-20', LegacyRowReader::date('2026-08-20 10:15:00')?->toDateString());
        $this->assertSame('2026-08-20', LegacyRowReader::date('20 Aug 2026')?->toDateString());
        $this->assertNull(LegacyRowReader::date('31/02/2026'));

        $this->assertSame('255712345678', LegacyRowReader::phone('0712 345 678'));
        $this->assertSame('255712345678', LegacyRowReader::phone('+255712345678'));
        $this->assertNull(LegacyRowReader::phone('2557597828266'));

        $this->assertSame([Duration::Monthly, 6], LegacyRowReader::duration('Monthly / 6'));
        $this->assertSame([Duration::Weekly, 12], LegacyRowReader::duration('12 WEEKLY'));
        $this->assertSame([Duration::Daily, 30], LegacyRowReader::duration('Daily-30'));
        $this->assertSame([null, null], LegacyRowReader::duration('6'));
    }
}
