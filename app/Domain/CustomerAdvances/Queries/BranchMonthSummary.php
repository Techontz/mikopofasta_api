<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Queries;

use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Branch List popup — what each branch collected in one month.
 *
 * ## Why the popup changed shape
 *
 * It used to print inception-to-date balances. The client's instruction is that
 * it should read as a month:
 *
 * > "KWENYE Branch List — September 2026 ITAREKODI KIASI KILICHOLIPWA KWA
 * > UJUMLA NA ITAKUWA INAONEKANA HAPO HADI MWISHO MWA MWEZI NDIPO TAARIFA
 * > NYINGINE ZA MWEZI MPYA ZITAKUWA ZINAJIREKODI"
 *
 * — it records the total collected, that figure stands until the month ends,
 * and then the new month starts recording its own. So every column here is
 * money that arrived between the first and the last day of the month named in
 * the title, and nothing carries over. The history does not disappear: last
 * month is one `month` parameter away, and every underlying event is in the
 * ledger and in Reports.
 *
 * ## How a column is counted
 *
 * Credits less debits on the accounts behind that column, over entries that
 * represent money coming IN. The source-type filter is what makes that
 * distinction: 1200 Loan Receivable is debited by every disbursement and
 * credited by every repayment, so a plain net movement would report a busy
 * lending month as negative collections.
 *
 * Reversals are counted as the thing they reverse — `COALESCE(orig.source_type,
 * e.source_type)`. A reversal carries source type `reversal`, which belongs to
 * no bucket; left as itself it would be silently dropped and a reversed
 * collection would stay in the month's total for ever.
 *
 * The branch dimension is read from the LINE, not from the account. System
 * accounts are company-wide rows with `branch_id` null — grouping by the
 * account's branch would put every shilling of income in one unnamed bucket.
 *
 * ## The Salary Advance column
 *
 * Read from the advance register rather than from an account, because there is
 * no Salary Advance account to read. The client is explicit: "there is no TZS
 * 1,000,000 sitting in a Salary Advance Account… Salary Advance = dashboard
 * summary + transaction history, NOT a separate cash account."
 *
 * It is therefore a SUMMARY OF MONEY ALREADY COUNTED: an advance payment's
 * capital is in the Principal column and its profit is in Interest and Loan
 * fee. The response says so in `salaryAdvanceIsSummary` so the dialog can print
 * the caveat rather than leaving someone to add the row up and find it does not
 * foot.
 */
final class BranchMonthSummary
{
    /**
     * Entries that represent money arriving, or the close appropriating it.
     *
     * Period-closing entries are deliberately absent: the close sweeps income
     * into Profit by DEBITING 2000, 2100 and 2200, and counting that would
     * report every closed month as having collected nothing.
     *
     * @var list<string>
     */
    private const INFLOW_SOURCES = [
        'repayment',
        'suspense_resolution',
        'advance_consumption',
        'customer_advance_payment',
        'reserve_appropriation',
    ];

    /**
     * Which account codes feed which column.
     *
     * Principal carries both receivables: a loan's capital and an advance's
     * capital are the same money returning to the same operational pot, and the
     * client's rule puts them in the same place — "sehemu ya mtaji itarudi
     * Principal Operation".
     *
     * @var array<string, list<string>>
     */
    private const BUCKETS = [
        'principal' => ['1200', '1250'],
        'interest' => ['2000'],
        'loanFee' => ['2100'],
        'penalty' => ['2200'],
        'reserve' => ['3000'],
    ];

    /**
     * One row per branch, plus the month it covers.
     *
     * @return array{
     *     month: string,
     *     monthLabel: string,
     *     from: string,
     *     to: string,
     *     rows: list<array<string, string>>,
     *     salaryAdvanceIsSummary: bool
     * }
     */
    public function for(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $ledger = $this->ledgerTotals($from, $to);
        $advances = $this->advanceTotals($from, $to);

        $rows = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Branch $branch) use ($ledger, $advances): array {
                $id = (int) $branch->getKey();
                $ledgerRow = $ledger[$id] ?? [];
                $advanceRow = $advances[$id] ?? [];

                $figure = static fn (array $source, string $key): string => ($source[$key] ?? Money::zero())
                    ->toDecimalString();

                return [
                    'branchId' => (string) $id,
                    'branchName' => $branch->name,
                    'principal' => $figure($ledgerRow, 'principal'),
                    'interest' => $figure($ledgerRow, 'interest'),
                    'loanFee' => $figure($ledgerRow, 'loanFee'),
                    'penalty' => $figure($ledgerRow, 'penalty'),
                    'reserve' => $figure($ledgerRow, 'reserve'),
                    'salaryAdvancePaid' => $figure($advanceRow, 'paid'),
                    'salaryAdvanceIssued' => $figure($advanceRow, 'issued'),
                    'salaryAdvanceProfit' => $figure($advanceRow, 'profit'),
                ];
            })
            ->values()
            ->all();

        return [
            'month' => $from->format('Y-m'),
            'monthLabel' => $from->format('F Y'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'salaryAdvanceIsSummary' => true,
        ];
    }

    /**
     * The account codes this summary reads, for anyone checking the mapping.
     *
     * @return array<string, list<string>>
     */
    public static function buckets(): array
    {
        return self::BUCKETS;
    }

    /**
     * Kept honest against the enum: a renamed source type must break here
     * rather than silently empty a column.
     */
    public static function assertSourcesExist(): void
    {
        foreach (self::INFLOW_SOURCES as $source) {
            JournalSourceType::from($source);
        }

        SystemAccountCode::from('1250');
    }

    /**
     * Collections by branch and bucket, for the month.
     *
     * @return array<int, array<string, Money>>
     */
    private function ledgerTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $codeToBucket = [];

        foreach (self::BUCKETS as $bucket => $codes) {
            foreach ($codes as $code) {
                $codeToBucket[$code] = $bucket;
            }
        }

        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('journal_entries as orig', 'orig.id', '=', 'e.reversed_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.code', array_keys($codeToBucket))
            ->whereNotNull('l.branch_id')
            /*
             * Raw rather than whereIn on a DB::raw column: the builder binds a
             * raw expression as a value on some drivers, which silently matches
             * nothing and empties every column.
             */
            ->whereRaw(
                'COALESCE(orig.source_type, e.source_type) IN ('
                .implode(',', array_fill(0, count(self::INFLOW_SOURCES), '?'))
                .')',
                self::INFLOW_SOURCES,
            )
            ->groupBy('l.branch_id', 'a.code')
            ->selectRaw(
                'l.branch_id AS branch_id, a.code AS code,'
                .' SUM(l.credit_amount) AS credit_total, SUM(l.debit_amount) AS debit_total',
            )
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $bucket = $codeToBucket[(string) $row->code] ?? null;

            if ($bucket === null) {
                continue;
            }

            $branchId = (int) $row->branch_id;

            $net = Money::of((string) $row->credit_total)->subtract(Money::of((string) $row->debit_total));

            $totals[$branchId][$bucket] = ($totals[$branchId][$bucket] ?? Money::zero())->add($net);
        }

        return $totals;
    }

    /**
     * Salary advance issued and collected by branch, for the month.
     *
     * Two separate reads rather than one join: an advance issued in September
     * and paid in October belongs to September's issued figure and October's
     * paid figure, and a join on the advance would have to pick one date.
     *
     * @return array<int, array<string, Money>>
     */
    private function advanceTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];

        $paid = DB::table('customer_advance_payments')
            // Reversed collections went back; the history keeps them, the summary does not.
            ->whereNull('reversed_at')
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->selectRaw(
                'branch_id,'
                .' SUM(amount) AS paid_total,'
                .' SUM(interest_portion + fee_portion) AS profit_total',
            )
            ->get();

        foreach ($paid as $row) {
            $branchId = (int) $row->branch_id;
            $totals[$branchId]['paid'] = Money::of((string) $row->paid_total);
            $totals[$branchId]['profit'] = Money::of((string) $row->profit_total);
        }

        $issued = DB::table('customer_advances')
            ->whereNull('deleted_at')
            ->whereBetween('disbursed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(amount) AS issued_total')
            ->get();

        foreach ($issued as $row) {
            $totals[(int) $row->branch_id]['issued'] = Money::of((string) $row->issued_total);
        }

        return $totals;
    }
}
