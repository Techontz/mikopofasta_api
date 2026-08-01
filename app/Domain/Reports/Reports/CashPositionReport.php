<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Support\Money;

/**
 * `GET /reports/cash-position` — §7C of the reports document.
 *
 *   > Available cash · Obligations
 *
 * Two figures and their difference, which is the question Finance actually
 * asks before releasing anything: *can we pay what we have promised.*
 *
 * ## What counts as cash
 *
 * Every non-system asset account — the bank accounts and the per-branch teller
 * cash rows. System asset accounts are receivables (loans, staff advances):
 * real assets, and not money that can be spent this afternoon, which is the
 * distinction this report exists to draw. The Cashflow report treats cash the
 * same way, so the two agree on what the word means.
 *
 * ## What counts as an obligation
 *
 * Every liability: Staff Payable, the Staff Fund, and anything else owed. These
 * are claims on the cash rather than a schedule of when they fall due — the
 * system has no due dates for them — so the report says "obligations", not
 * "obligations due this month", and the reconciliation note is explicit that a
 * negative headroom is a claim on future collections rather than an
 * overdraft.
 */
final class CashPositionReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'cash-position';
    }

    public function title(): string
    {
        return 'Cash Position';
    }

    public function description(): string
    {
        return 'Cash actually held against what the company owes, and the headroom between them.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $trial = $this->sources->trialBalance($filters);

        $rows = [];
        $cash = Money::zero();
        $obligations = Money::zero();

        $systemCodes = SystemAccountCode::values();

        foreach ($trial['rows'] as $account) {
            $balance = Money::of((string) $account['balance']);

            if ($balance->isZero()) {
                continue;
            }

            $type = AccountType::from((string) $account['type']);
            $isSystem = in_array((string) $account['code'], $systemCodes, true);

            // Cash: a non-system asset — a bank account or a teller float.
            if ($type === AccountType::Asset && ! $isSystem) {
                $rows[] = [
                    'kind' => 'Cash',
                    'code' => $account['code'],
                    'account' => $account['name'],
                    'amount' => $balance->toDecimalString(),
                ];
                $cash = $cash->add($balance);

                continue;
            }

            if ($type === AccountType::Liability) {
                $rows[] = [
                    'kind' => 'Obligation',
                    'code' => $account['code'],
                    'account' => $account['name'],
                    'amount' => $balance->toDecimalString(),
                ];
                $obligations = $obligations->add($balance);
            }
        }

        // Cash first, then obligations, each descending — the biggest number in
        // each section is the one a reader looks for.
        usort($rows, static function (array $a, array $b): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'Cash' ? -1 : 1;
            }

            return bccomp((string) $b['amount'], (string) $a['amount'], 2);
        });

        $headroom = $cash->subtract($obligations);

        /*
         * Receivables are reported beside the position rather than inside it.
         * They are what the obligations will eventually be settled from, so a
         * reader looking at negative headroom needs to see them — but they are
         * not cash, and adding them in would be the exact error this report is
         * meant to prevent.
         */
        $receivables = Money::sum(
            collect($trial['rows'])
                ->filter(fn (array $r): bool => $r['type'] === AccountType::Asset->value
                    && in_array((string) $r['code'], $systemCodes, true))
                ->map(fn (array $r): Money => Money::of((string) $r['balance'])),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('kind', 'Kind'),
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::money('amount', 'Amount'),
            ],
            rows: $rows,
            totals: [
                'kind' => 'Headroom',
                'account' => 'Cash less obligations',
                'amount' => $headroom->toDecimalString(),
            ],
            summary: [
                ['label' => 'Available Cash', 'value' => $cash->toDecimalString()],
                ['label' => 'Obligations', 'value' => $obligations->toDecimalString()],
                ['label' => 'Headroom', 'value' => $headroom->toDecimalString()],
                ['label' => 'Receivables', 'value' => $receivables->toDecimalString()],
            ],
            emptyMessage: 'No cash or obligations have been posted.',
            reconciliation: 'Cash is every non-system asset account — the bank accounts and teller floats. Receivables (the loan book, staff advances) are real assets but are NOT cash and are reported separately, which is the distinction this report exists to draw. Obligations are the liability balances; the system holds no due dates for them, so this is a claim total rather than a maturity schedule. Negative headroom means obligations exceed cash on hand and will be met from collections, not that an account is overdrawn.',
        );
    }
}
