<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\AccountMovement;
use App\Domain\Accounting\DTOs\PeriodResult;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Exceptions\PeriodException;
use App\Domain\Accounting\Services\PeriodResultCalculator;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\AccountingPeriod;
use App\Models\PeriodBranchResult;
use App\Models\ReserveSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The month-end close — Decision Register D1, and §8's "Month End Process".
 *
 * Two postings, in this order, both dated the last day of the period:
 *
 *   1. **Recognise profit.** Every income account is debited by what it earned
 *      and every expense account credited by what it cost, with Profit taking
 *      the other side. The client's rule that "mwezi unapokuja inaanza upya" —
 *      the new month starts afresh — is this sweep. It is why a closed period's
 *      income accounts read zero afterwards, and why every P&L-shaped read
 *      excludes closing entries (JournalSourceType::periodClosing).
 *
 *   2. **Appropriate reserve.** D1: reserve is "calculated from realised profit
 *      during the accounting closing process", not taken from each repayment.
 *      The client's words are plainer still — "kwenye hiyo faida reserve
 *      inatolewa kwanza maana ndo inalinda mtaji": from that profit the reserve
 *      comes out first, because it protects the capital.
 *
 * ## Why the reserve is appropriated per branch but held company-wide
 *
 * The debit carries the branch that earned the profit; the credit does not.
 * That is D1 stated in double entry: the profit came from a branch, and the
 * reserve does not belong to it — "Reserve belongs to Headquarters /
 * Administration. Branches cannot directly use Reserve funds." A branch reading
 * its own ledger sees its profit reduced and no reserve it could spend.
 *
 * The per-branch figures are kept on `period_branch_results` because §11's
 * commission pool is computed from them, and after the sweep they could not be
 * recovered from the income accounts.
 *
 * ## Why a loss appropriates nothing
 *
 * Reserve protects capital out of earnings. A branch that lost money has no
 * earnings to protect it from, and taking a percentage of a negative would
 * credit the branch a reserve it never earned.
 */
final class ClosePeriodAction
{
    public function __construct(
        private readonly PeriodResultCalculator $results,
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(string $period, User $actor, ?string $notes = null): AccountingPeriod
    {
        $this->assertClosable($period);

        $result = $this->results->forPeriod($period);

        if ($result->isEmpty()) {
            throw PeriodException::empty($period);
        }

        $reserveRate = Percentage::of((string) ReserveSetting::singleton()->percentage);

        return DB::transaction(function () use ($period, $result, $reserveRate, $actor, $notes): AccountingPeriod {
            $profitEntry = $this->postProfitRecognition($result, $actor);

            /*
             * Per branch, so the reserve tracks the profit that produced it.
             * Computed before the entry is built because a branch in loss
             * contributes no line at all — an entry of only zero-value lines
             * would fail LedgerService's two-line minimum.
             */
            $appropriations = [];

            foreach ($result->branchIds() as $branchId) {
                $branchProfit = $result->profit($branchId, allBranches: false);

                $appropriations[] = [
                    'branchId' => $branchId,
                    'income' => $result->incomeTotal($branchId, allBranches: false),
                    'expense' => $result->expenseTotal($branchId, allBranches: false),
                    'profit' => $branchProfit,
                    'reserve' => $branchProfit->isPositive()
                        ? $branchProfit->percentage($reserveRate)
                        : Money::zero(),
                ];
            }

            $reserveTotal = Money::sum(array_map(
                static fn (array $a): Money => $a['reserve'],
                $appropriations,
            ));

            $reserveEntry = $reserveTotal->isPositive()
                ? $this->postReserveAppropriation($appropriations, $reserveTotal, $result, $actor)
                : null;

            $accountingPeriod = AccountingPeriod::query()->updateOrCreate(
                ['period' => $period],
                [
                    'status' => PeriodStatus::Closed,
                    'income_total' => $result->incomeTotal()->toDecimalString(),
                    'expense_total' => $result->expenseTotal()->toDecimalString(),
                    'realised_profit' => $result->profit()->toDecimalString(),
                    'reserve_percentage' => $reserveRate->toDecimalString(),
                    'reserve_appropriated' => $reserveTotal->toDecimalString(),
                    'profit_journal_entry_id' => $profitEntry->getKey(),
                    'reserve_journal_entry_id' => $reserveEntry?->getKey(),
                    'closed_by' => $actor->getKey(),
                    'closed_at' => Date::now(),
                    'notes' => $notes,
                ],
            );

            foreach ($appropriations as $appropriation) {
                // The unbranched bucket has no branch row to write; its profit
                // is in the period total and belongs to no branch's commission.
                if ($appropriation['branchId'] === null) {
                    continue;
                }

                PeriodBranchResult::query()->updateOrCreate(
                    [
                        'accounting_period_id' => $accountingPeriod->getKey(),
                        'branch_id' => $appropriation['branchId'],
                    ],
                    [
                        'income_total' => $appropriation['income']->toDecimalString(),
                        'expense_total' => $appropriation['expense']->toDecimalString(),
                        'realised_profit' => $appropriation['profit']->toDecimalString(),
                        'reserve_appropriated' => $appropriation['reserve']->toDecimalString(),
                    ],
                );
            }

            $this->audit->log(
                AuditAction::PeriodClosed,
                $accountingPeriod,
                after: [
                    'period' => $period,
                    'realised_profit' => $accountingPeriod->realised_profit,
                    'reserve_percentage' => $accountingPeriod->reserve_percentage,
                    'reserve_appropriated' => $accountingPeriod->reserve_appropriated,
                    'profit_journal_entry_id' => $profitEntry->getKey(),
                    'reserve_journal_entry_id' => $reserveEntry?->getKey(),
                ],
                actor: $actor,
            );

            return $accountingPeriod->load('branchResults.branch');
        });
    }

    /**
     * Sweeps income and expense into Profit — §8's `Dr Income · Cr Profit`.
     *
     * One line per (account, branch) so the sweep lands in exactly the
     * sub-ledgers the trading did, and the Profit side carries the branch too:
     * a branch's equity contribution is as real as its income was.
     */
    private function postProfitRecognition(PeriodResult $result, User $actor): \App\Models\JournalEntry
    {
        $profitAccountId = $this->accounts->systemId(SystemAccountCode::Profit);

        $lines = [];

        foreach ($result->movements as $movement) {
            $lines[] = $this->sweepLine($movement);
            $lines[] = $this->profitLine($movement, $profitAccountId);
        }

        return $this->ledger->post(
            sprintf('Month-end close %s — profit recognition', $result->period),
            JournalSourceType::MonthEndProfit,
            null,
            $lines,
            $actor,
            $result->end,
        );
    }

    /**
     * Closes one account out.
     *
     * Income is credit-normal, so clearing it is a debit; expense is
     * debit-normal, so clearing it is a credit. A negative net — a refunded fee,
     * or an expense credited back — reverses the side, which is why this is a
     * signed test rather than a fixed direction per account type.
     */
    private function sweepLine(AccountMovement $movement): JournalLine
    {
        $amount = $movement->net;

        if ($movement->isIncome()) {
            return $amount->isNegative()
                ? JournalLine::credit($movement->accountId, $amount->multiply(-1), $movement->branchId)
                : JournalLine::debit($movement->accountId, $amount, $movement->branchId);
        }

        return $amount->isNegative()
            ? JournalLine::debit($movement->accountId, $amount->multiply(-1), $movement->branchId)
            : JournalLine::credit($movement->accountId, $amount, $movement->branchId);
    }

    /** The Profit side of one swept account — always the mirror of the sweep. */
    private function profitLine(AccountMovement $movement, int $profitAccountId): JournalLine
    {
        $amount = $movement->net;
        $positive = ! $amount->isNegative();
        $magnitude = $positive ? $amount : $amount->multiply(-1);

        // Income increases profit (a credit); expense reduces it (a debit).
        $creditsProfit = $movement->isIncome() === $positive;

        return $creditsProfit
            ? JournalLine::credit($profitAccountId, $magnitude, $movement->branchId)
            : JournalLine::debit($profitAccountId, $magnitude, $movement->branchId);
    }

    /**
     * `Dr Profit · Cr Reserve` — D1's appropriation.
     *
     * The credit carries no branch: the fund is company-wide and belongs to
     * Headquarters. The debits do, so each branch's retained profit is reduced
     * by exactly what its own earnings contributed.
     *
     * @param list<array{branchId: int|null, income: Money, expense: Money, profit: Money, reserve: Money}> $appropriations
     */
    private function postReserveAppropriation(
        array $appropriations,
        Money $reserveTotal,
        PeriodResult $result,
        User $actor,
    ): \App\Models\JournalEntry {
        $lines = [];

        foreach ($appropriations as $appropriation) {
            if (! $appropriation['reserve']->isPositive()) {
                continue;
            }

            $lines[] = JournalLine::debit(
                $this->accounts->systemId(SystemAccountCode::Profit),
                $appropriation['reserve'],
                $appropriation['branchId'],
            );
        }

        $lines[] = JournalLine::credit(
            $this->accounts->systemId(SystemAccountCode::Reserve),
            $reserveTotal,
        );

        return $this->ledger->post(
            sprintf('Month-end close %s — reserve appropriation', $result->period),
            JournalSourceType::ReserveAppropriation,
            null,
            $lines,
            $actor,
            $result->end,
        );
    }

    /**
     * The three preconditions, checked before anything is computed.
     */
    private function assertClosable(string $period): void
    {
        if (AccountingPeriod::isClosed($period)) {
            throw PeriodException::alreadyClosed($period);
        }

        [, $end] = PeriodResultCalculator::bounds($period);

        if ($end->isFuture()) {
            throw PeriodException::notEnded($period);
        }

        /*
         * The prior period must be closed — but only if it had anything to
         * close. A business that started trading in March cannot be asked to
         * close February first, and walking back one month at a time would
         * stop at the first empty month forever.
         */
        $prior = PeriodResultCalculator::previous($period);

        if (! AccountingPeriod::isClosed($prior) && ! $this->results->forPeriod($prior)->isEmpty()) {
            throw PeriodException::priorPeriodOpen($period, $prior);
        }
    }
}
