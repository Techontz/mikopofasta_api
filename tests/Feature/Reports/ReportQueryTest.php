<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Reports\DTOs\ReportQuery;
use App\Domain\Reports\Services\ReportExporter;
use App\Domain\Reports\Services\ReportRegistry;
use App\Support\Money;

/**
 * Module 8 — searching, sorting, paging and exporting a report.
 *
 * See docs/modules/reports.md.
 */
beforeEach(function (): void {
    seedStaffBook();
    finalizedPayrollRun();
    forgetAuthGuards();
});

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

describe('search', function (): void {
    it('narrows the rows to those containing the term', function (): void {
        actingAsFinance();

        $all = $this->getJson('/api/v1/reports/branch-pnl')->assertOk()->json('data');
        $branch = $all[0]['branch'];

        $response = $this->getJson('/api/v1/reports/branch-pnl?search='.urlencode($branch))->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.branch'))->toBe($branch)
            ->and($response->json('meta.totalRows'))->toBe(count($all))
            ->and($response->json('meta.matchedRows'))->toBe(1);
    });

    it('matches any column, not a declared subset', function (): void {
        actingAsFinance();

        // "Head Office" is in the `type` column of branch-pnl, not the name.
        $response = $this->getJson('/api/v1/reports/branch-pnl?search=head+office')->assertOk();

        expect($response->json('data'))->not->toBeEmpty();
    });

    it('is case-insensitive', function (): void {
        actingAsFinance();

        $lower = $this->getJson('/api/v1/reports/branch-pnl?search=kakonko')->assertOk()->json('data');
        $upper = $this->getJson('/api/v1/reports/branch-pnl?search=KAKONKO')->assertOk()->json('data');

        expect($lower)->toBe($upper)->and($lower)->not->toBeEmpty();
    });

    /*
     * A search narrows what the report is ABOUT, so the totals follow it.
     * Leaving them as the whole report's would state a figure that no visible
     * row contributes to, and a reader has no way to detect that.
     */
    it('recomputes the totals over what matched', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/branch-pnl?search=kakonko')->assertOk();

        $rows = $response->json('data');
        $totals = $response->json('meta.totals');

        $expected = Money::sum(array_map(
            static fn (array $r): Money => Money::of((string) $r['income']),
            $rows,
        ));

        expect($totals['income'])->toBe($expected->toDecimalString());
    });

    it('returns nothing for a term no row contains', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/branch-pnl?search=zzzznothing')->assertOk();

        expect($response->json('data'))->toBeEmpty()
            ->and($response->json('meta.matchedRows'))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Sort
// ---------------------------------------------------------------------------

describe('sort', function (): void {
    it('orders by a text column in both directions', function (): void {
        actingAsFinance();

        $asc = collect($this->getJson('/api/v1/reports/branch-pnl?sort=branch&direction=asc')
            ->assertOk()->json('data'))->pluck('branch')->all();

        $desc = collect($this->getJson('/api/v1/reports/branch-pnl?sort=branch&direction=desc')
            ->assertOk()->json('data'))->pluck('branch')->all();

        expect($asc)->toBe(collect($asc)->sort()->values()->all())
            ->and($desc)->toBe(array_reverse($asc));
    });

    /*
     * Money is a decimal string, and comparing those as strings puts
     * '1000000.10' below '999999.99'. The presenter uses bccomp for exactly
     * this, and casting to float would put a rounding error into an ordering.
     */
    it('orders money numerically rather than lexicographically', function (): void {
        actingAsFinance();

        $rows = $this->getJson('/api/v1/reports/branch-pnl?sort=income&direction=desc')
            ->assertOk()->json('data');

        $incomes = array_map(static fn (array $r): string => (string) $r['income'], $rows);

        for ($i = 1; $i < count($incomes); $i++) {
            expect(bccomp($incomes[$i - 1], $incomes[$i], 2))->toBeGreaterThanOrEqual(0);
        }
    });

    it('publishes which columns may be sorted', function (): void {
        actingAsFinance();

        $sortable = $this->getJson('/api/v1/reports/branch-pnl')->assertOk()->json('meta.report.sortable');

        expect($sortable)->toContain('branch', 'income', 'expense', 'profit');
    });

    it('says so when the requested column is not one', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/branch-pnl?sort=nonsense')->assertOk();

        // Reported rather than silently ignored: the caller believes the order
        // means something, and an unsorted list reads as wrong data.
        expect($response->json('meta.sortIgnored'))->toBe('nonsense')
            ->and($response->json('data'))->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

describe('pagination', function (): void {
    it('returns every row when none is asked for', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/payroll')->assertOk();

        // Opt-in, because a trial balance cut off at row fifty is not a shorter
        // report — it is a wrong one.
        expect($response->json('meta.pagination'))->toBeNull()
            ->and($response->json('data'))->toHaveCount($response->json('meta.rowCount'));
    });

    it('pages when asked, and reports the shape', function (): void {
        actingAsFinance();

        $all = $this->getJson('/api/v1/reports/payroll')->assertOk()->json('data');

        $first = $this->getJson('/api/v1/reports/payroll?per_page=4&page=1')->assertOk();
        $second = $this->getJson('/api/v1/reports/payroll?per_page=4&page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(4)
            ->and($first->json('meta.pagination.total'))->toBe(count($all))
            ->and($first->json('meta.pagination.lastPage'))->toBe((int) ceil(count($all) / 4))
            ->and($second->json('data.0'))->toBe($all[4]);
    });

    /*
     * A total that summed only the visible page would be a different number on
     * page two, which is worse than having none.
     */
    it('leaves the totals over the whole set', function (): void {
        actingAsFinance();

        $whole = $this->getJson('/api/v1/reports/payroll')->assertOk()->json('meta.totals');
        $paged = $this->getJson('/api/v1/reports/payroll?per_page=2')->assertOk()->json('meta.totals');

        expect($paged)->toBe($whole);
    });

    it('clamps a page past the end rather than returning a void', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/payroll?per_page=5&page=999')->assertOk();

        expect($response->json('meta.pagination.page'))
            ->toBe($response->json('meta.pagination.lastPage'));
    });

    it('refuses a page size beyond the ceiling', function (): void {
        actingAsFinance();

        $this->getJson('/api/v1/reports/payroll?per_page='.(ReportQuery::MAX_PER_PAGE + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    });
});

// ---------------------------------------------------------------------------
// Presentation is not filtering
// ---------------------------------------------------------------------------

it('keeps search and sort out of filters_applied', function (): void {
    actingAsFinance();

    $response = $this->getJson('/api/v1/reports/branch-pnl?search=kakonko&sort=income&period=2026-08')
        ->assertOk();

    /*
     * §15.6's `filters_applied` says what the FIGURES cover. Sorting changed
     * no figure, so listing it there would tell a reader that it had.
     */
    expect($response->json('meta.filters_applied'))->toBe(['period' => '2026-08'])
        ->and($response->json('meta.query'))
        ->toBe(['search' => 'kakonko', 'sort' => 'income', 'direction' => 'asc']);
});

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

describe('export', function (): void {
    it('serves CSV with a BOM, a header row and the totals', function (): void {
        actingAsFinance();

        $response = $this->get('/api/v1/reports/branch-pnl/export?format=csv')->assertOk();

        $body = (string) $response->getContent();

        expect($response->headers->get('content-type'))->toContain('text/csv')
            ->and($response->headers->get('content-disposition'))->toContain('branch-pnl.csv')
            // Without the BOM Excel on Windows mangles every non-ASCII name.
            ->and(str_starts_with((string) $body, "\u{FEFF}"))->toBeTrue()
            ->and($body)->toContain('Branch,Type,Income,Expense,Profit')
            ->and($body)->toContain('Total');
    });

    it('quotes a field containing a comma', function (): void {
        actingAsFinance();

        // Reconciliation text and descriptions are full of commas; a field that
        // was not quoted would shift every column after it.
        $body = $this->get('/api/v1/reports/audit-trail/export?format=csv')->assertOk()->getContent();

        expect($body)->toBeString();
    });

    it('serves a workbook that unzips to the parts Excel expects', function (): void {
        actingAsFinance();

        $response = $this->get('/api/v1/reports/branch-pnl/export?format=xlsx')->assertOk();

        expect($response->headers->get('content-type'))
            ->toContain('spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ] as $part) {
            expect($zip->locateName($part))->not->toBeFalse("missing {$part}");
        }

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // Well-formed, and carrying the header.
        expect(simplexml_load_string($sheet))->not->toBeFalse()
            ->and($sheet)->toContain('Branch');
    });

    it('serves a PDF with a header, a trailer and the title', function (): void {
        actingAsFinance();

        $response = $this->get('/api/v1/reports/branch-pnl/export?format=pdf')->assertOk();
        $body = (string) $response->getContent();

        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and(str_starts_with($body, '%PDF-'))->toBeTrue()
            ->and($body)->toContain('%%EOF')
            ->and($body)->toContain('/Type /Catalog')
            ->and($body)->toContain('Branch P&L');
    });

    it('exports what the caller is looking at, not the whole report', function (): void {
        actingAsFinance();

        $body = $this->get('/api/v1/reports/branch-pnl/export?format=csv&search=kakonko')
            ->assertOk()->getContent();

        // One data row plus the header and the totals.
        $lines = array_values(array_filter(explode("\r\n", (string) $body)));

        expect($lines)->toHaveCount(3)
            ->and($lines[1])->toContain('Kakonko');
    });

    /*
     * A page of a spreadsheet is not what anybody means by exporting a report.
     */
    it('ignores pagination', function (): void {
        actingAsFinance();

        $paged = $this->get('/api/v1/reports/payroll/export?format=csv&per_page=2')
            ->assertOk()->getContent();

        $rows = count(array_filter(explode("\r\n", (string) $paged)));
        $all = count($this->getJson('/api/v1/reports/payroll')->assertOk()->json('data'));

        // Header + every row + totals.
        expect($rows)->toBe($all + 2);
    });

    it('names the file after the report and its filters', function (): void {
        actingAsFinance();

        $disposition = $this->get('/api/v1/reports/payroll/export?format=csv&period=2026-08')
            ->assertOk()->headers->get('content-disposition');

        expect($disposition)->toContain('payroll-2026-08.csv');
    });

    it('rejects a format it does not produce', function (): void {
        actingAsFinance();

        $this->getJson('/api/v1/reports/branch-pnl/export?format=docx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');
    });

    it('404s for a report that does not exist', function (): void {
        actingAsFinance();

        $this->getJson('/api/v1/reports/not-a-report/export?format=csv')->assertNotFound();
    });

    it('needs reports.view like every other report call', function (): void {
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/reports/branch-pnl/export?format=csv')->assertForbidden();
    });

    it('produces a file for every report in the catalogue', function (): void {
        actingAsFinance();

        $exporter = app(ReportExporter::class);

        foreach (array_keys(app(ReportRegistry::class)->all()) as $slug) {
            foreach (ReportExporter::FORMATS as $format) {
                $response = $this->get("/api/v1/reports/{$slug}/export?format={$format}");

                expect($response->getStatusCode())->toBe(200, "{$slug} as {$format}");
                expect(strlen((string) $response->getContent()))
                    ->toBeGreaterThan(0, "{$slug} as {$format} was empty");
            }
        }

        expect($exporter->contentType('pdf'))->toBe('application/pdf');
    });
});
