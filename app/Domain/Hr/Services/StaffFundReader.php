<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Models\StaffAdvance;
use App\Models\StaffLoan;
use App\Models\StaffProfile;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The Staff Fund and the per-employee ledger views — §12 and §2B.
 *
 * ## §2B, and why there are no extra tables
 *
 * The HR document says that registering an employee creates four accounts:
 *
 *   > System creates: → Staff Control Account (Ledger) → Staff Loan Account
 *   > → Staff Advance Account → Staff Deductions Account
 *
 * Spec §11 resolves how: *"no new physical tables needed, staff_profile_id
 * becomes a filterable dimension on journal_entry_lines (Staff Control / Staff
 * Loan / Staff Advance / Staff Deductions are views, not tables)"*.
 *
 * This class is those views. The dimension has been on `journal_entry_lines`
 * since Phase 6 and constrained since §2.9's migration; nothing read it back
 * per employee until now, so the document's promise — *"hakuna pesa ya staff
 * inayoenda nje ya mfumo, full audit trail"* — was true of the data and
 * invisible in the application.
 *
 * Creating four real accounts per employee would have been the alternative, and
 * a worse one: a hundred staff would mean four hundred chart-of-accounts rows
 * that every report, every trial balance and every account picker would have to
 * scroll past, to hold information the dimension already carries exactly.
 *
 * ## §12, the fund itself
 *
 * *"Internal revolving fund ya wafanyakazi"* — built from a percentage of every
 * salary, lent out as advances and loans, and repaid back into itself. Its
 * balance is `7000 Staff Fund`, and every movement in and out of it is one of
 * the four postings this class reports.
 */
final class StaffFundReader
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * What the fund holds.
     *
     * Read from the ledger rather than summed from contributions, because the
     * ledger is what actually happened: a contribution that was posted and an
     * advance that went out are both in it, and a figure derived from payroll
     * rows alone would ignore the money already lent.
     */
    public function balance(): Money
    {
        return $this->accounts->system(SystemAccountCode::StaffFund)
            ->load('balances')
            ->cachedBalance();
    }

    /**
     * The fund's position, itemised — HRM → Staff Fund, and the Staff Fund
     * Balance report §17 asks for.
     *
     * @return array{
     *     balance: Money,
     *     contributions: Money,
     *     advancesOutstanding: Money,
     *     loansOutstanding: Money,
     *     lentOut: Money,
     *     memberCount: int
     * }
     */
    public function position(): array
    {
        $advances = $this->outstandingAdvances();
        $loans = $this->outstandingLoans();

        return [
            'balance' => $this->balance(),

            /*
             * Everything ever contributed, from the ledger's credit side on
             * 7000 attributable to payroll. Reported beside the balance rather
             * than instead of it: the two differ by what is currently lent out
             * and what advance charges have earned, and seeing both is how
             * anyone checks that the fund is doing what §12 says.
             */
            'contributions' => $this->totalContributions(),

            'advancesOutstanding' => $advances,
            'loansOutstanding' => $loans,
            'lentOut' => $advances->add($loans),

            'memberCount' => StaffProfile::query()->count(),
        ];
    }

    /**
     * One employee's four §2B views, from the `staff_profile_id` dimension.
     *
     * Each is a signed net: debits less credits for an asset, credits less
     * debits for a liability, so a positive figure always means what a reader
     * expects — money owed *to* the employee on the control account, money owed
     * *by* them on loan and advance.
     *
     * @return array<string, array{code: string, name: string, balance: Money}>
     */
    public function statementFor(StaffProfile $staff): array
    {
        $views = [
            'control' => SystemAccountCode::StaffPayable,
            'loan' => SystemAccountCode::StaffLoanReceivable,
            'advance' => SystemAccountCode::StaffAdvanceReceivable,
            'deductions' => SystemAccountCode::StaffFund,
        ];

        $statement = [];

        foreach ($views as $key => $code) {
            $account = $this->accounts->system($code);

            $row = DB::table('journal_entry_lines')
                ->where('account_id', $account->getKey())
                ->where('staff_profile_id', $staff->getKey())
                ->selectRaw('COALESCE(SUM(debit_amount), 0) AS d, COALESCE(SUM(credit_amount), 0) AS c')
                ->first();

            $debits = Money::of((string) ($row->d ?? '0'));
            $credits = Money::of((string) ($row->c ?? '0'));

            $statement[$key] = [
                'code' => $code->value,
                'name' => $code->accountName(),
                // Asset accounts read debit-positive, liabilities credit-positive.
                'balance' => $code->type()->isDebitNormal()
                    ? $debits->subtract($credits)
                    : $credits->subtract($debits),
            ];
        }

        return $statement;
    }

    /** Principal still owed on advances that have been disbursed. */
    private function outstandingAdvances(): Money
    {
        $advances = StaffAdvance::query()
            ->where('status', StaffAdvanceStatus::Disbursed)
            ->get();

        return Money::sum($advances->map(static function (StaffAdvance $a): Money {
            $remaining = $a->amountMoney()->subtract($a->recoveredMoney()->min($a->amountMoney()));

            return $remaining->isNegative() ? Money::zero() : $remaining;
        })->all());
    }

    /** Principal still owed on active staff loans. */
    private function outstandingLoans(): Money
    {
        $loans = StaffLoan::query()
            ->where('status', StaffLoanStatus::Active)
            ->get();

        return Money::sum($loans->map(static function (StaffLoan $l): Money {
            $remaining = $l->amountMoney()->subtract($l->recoveredMoney());

            return $remaining->isNegative() ? Money::zero() : $remaining;
        })->all());
    }

    /**
     * Every credit ever posted to 7000 — what the staff have put in, plus what
     * the fund's own lending has earned it.
     */
    private function totalContributions(): Money
    {
        $accountId = $this->accounts->systemId(SystemAccountCode::StaffFund);

        $total = DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->sum('credit_amount');

        return Money::of((string) ($total ?: '0'));
    }
}
