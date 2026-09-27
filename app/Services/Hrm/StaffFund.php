<?php

namespace App\Services\Hrm;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffSalaryAdvance;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff Fund (ACCOUNT OVERVIEW §15, STAFF COMMISSION §12, handwritten Finance note: the share of
 * salaries that would go to NSSF goes to the funds account and is lent to staff as loans and
 * salary advances).
 *
 * Ledger model:
 *  - STAFF FUND A/C (asset, Account::StaffFundCash) holds the fund's money;
 *  - STAFF FUND (liability, Account::StaffFund) is what the fund owes its members (per employee)
 *    plus income earned inside the fund (loan interest and fees, no employee).
 *
 *  Contribution (payroll):  Dr Staff Payable      Cr Staff Fund(emp);  Dr Staff Fund A/C  Cr paying account
 *  Staff loan disbursement: Dr Staff Loan         Cr Staff Fund A/C
 *  Salary advance (fund):   Dr Staff Advance      Cr Staff Fund A/C
 *  Repayment:               Dr Staff Fund A/C     Cr Staff Loan / Staff Advance (interest → Cr Staff Fund)
 *  Benefit payment (§27):   Dr Staff Fund(emp)    Cr Staff Fund A/C   — only when Finance pays an approved claim
 *
 * Spec §25–26, as ruled by the product owner (2026-09-17): the 20 % withheld from basic salary is the only contribution. It
 * is part of the salary expense, not an extra company expense, and it enters the STAFF FUND A/C as real cash, so an
 * employee's benefit record is exactly what the fund received for them.
 */
class StaffFund
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly SegregationOfDuties $duties,
    ) {}

    public function cashBalance(int $companyId): float
    {
        return $this->ledger->balance($companyId, Account::StaffFundCash);
    }

    public function memberBalance(int $companyId, int $employeeId): float
    {
        return $this->ledger->balance($companyId, Account::StaffFund, employee: $employeeId);
    }

    /**
     * Spec §26 benefit record of one employee: the contributions the fund actually received for them.
     *
     * @return array{staff_contribution: float, total_benefit_record: float}
     */
    public function benefitRecord(int $companyId, int $employeeId): array
    {
        $staff = $this->memberBalance($companyId, $employeeId);

        return ['staff_contribution' => $staff, 'total_benefit_record' => $staff];
    }

    /**
     * Fail when the fund holds less than the amount about to leave it.
     */
    public function assertAvailable(int $companyId, float $amount, string $field = 'amount'): void
    {
        $available = $this->cashBalance($companyId);

        if ($amount > $available + 0.001) {
            throw ValidationException::withMessages([$field => 'Staff Fund balance is not enough ('.number_format($available).' available)']);
        }
    }

    /**
     * Benefit entitlement of an employee for a new claim (spec §27): the recorded benefit (contributions the fund holds for them)
     * less claims already prepared, under review or approved but not yet paid.
     *
     * @return array{benefit_record: float, open_claims: float, claimable: float}
     */
    public function entitlement(int $companyId, int $employeeId, ?int $exceptClaimId = null): array
    {
        $record = $this->memberBalance($companyId, $employeeId);
        $open = round((float) StaffFundWithdrawal::where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereIn('status', StaffFundWithdrawal::OPEN_STATUSES)
            ->when($exceptClaimId !== null, fn ($query) => $query->whereKeyNot($exceptClaimId))
            ->sum('amount'), 2);

        return ['benefit_record' => $record, 'open_claims' => $open, 'claimable' => round(max(0.0, $record - $open), 2)];
    }

    /**
     * Spec §27 step 1–2: HR prepares a benefit claim; the system records the employee's benefit entitlement at that moment. The
     * claim cannot exceed what is still claimable. Available fund CASH is not checked here — only at payment.
     *
     * @throws ValidationException
     */
    public function prepareClaim(Employee $employee, float $amount, string $reason, Employee $hr): StaffFundWithdrawal
    {
        $companyId = (int) $employee->company_id;
        $amount = round($amount, 2);

        return DB::transaction(function () use ($employee, $amount, $reason, $hr, $companyId): StaffFundWithdrawal {
            Employee::whereKey($employee->id)->lockForUpdate()->first();
            $entitlement = $this->entitlement($companyId, $employee->id);

            if ($amount > $entitlement['claimable'] + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Amount is more than the claimable staff fund benefit of '.$employee->full_name.' ('.number_format($entitlement['claimable']).')']);
            }

            return StaffFundWithdrawal::create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'amount' => $amount,
                'entitlement' => $entitlement['benefit_record'],
                'reason' => $reason,
                'status' => StaffFundWithdrawal::STATUS_PREPARED,
                'recorded_by' => $hr->id,
                'prepared_by' => $hr->id,
                'prepared_at' => now(),
            ]);
        });
    }

    /**
     * Spec §27 step 3: Finance takes the prepared claim into review.
     *
     * @throws ValidationException
     */
    public function reviewClaim(StaffFundWithdrawal $claim, Employee $finance): void
    {
        $this->transitionClaim($claim, $finance, [StaffFundWithdrawal::STATUS_PREPARED], 'reviewed', fn (StaffFundWithdrawal $locked) => $locked->update([
            'status' => StaffFundWithdrawal::STATUS_FINANCE_REVIEW, 'reviewed_by' => $finance->id, 'reviewed_at' => now(),
        ]));
    }

    /**
     * Spec §27 step 4: Finance approves the claim (a prepared claim is reviewed in the same step). No money moves.
     *
     * @throws ValidationException
     */
    public function approveClaim(StaffFundWithdrawal $claim, Employee $finance): void
    {
        $this->transitionClaim($claim, $finance, [StaffFundWithdrawal::STATUS_PREPARED, StaffFundWithdrawal::STATUS_FINANCE_REVIEW], 'approved', fn (StaffFundWithdrawal $locked) => $locked->update([
            'status' => StaffFundWithdrawal::STATUS_APPROVED,
            'reviewed_by' => $locked->reviewed_by ?? $finance->id,
            'reviewed_at' => $locked->reviewed_at ?? now(),
            'approved_by' => $finance->id,
            'approved_at' => now(),
        ]));
    }

    /**
     * Finance rejects an unpaid claim with a reason; its amount is claimable again.
     *
     * @throws ValidationException
     */
    public function rejectClaim(StaffFundWithdrawal $claim, Employee $finance, string $reason): void
    {
        $this->transitionClaim($claim, $finance, StaffFundWithdrawal::OPEN_STATUSES, 'rejected', fn (StaffFundWithdrawal $locked) => $locked->update([
            'status' => StaffFundWithdrawal::STATUS_REJECTED, 'rejected_by' => $finance->id, 'rejected_at' => now(), 'rejection_reason' => $reason,
        ]));
    }

    /**
     * Spec §27 steps 5–7: Finance pays an approved claim from the single STAFF FUND A/C. The fund cash decreases by the amount
     * paid and the employee's benefit record by the same amount: Dr STAFF FUND (employee) / Cr STAFF FUND A/C. Fails when the
     * employee's benefit record or the fund's available cash is less than the claim.
     *
     * @throws ValidationException
     */
    public function payClaim(StaffFundWithdrawal $claim, Employee $finance): void
    {
        $this->transitionClaim($claim, $finance, [StaffFundWithdrawal::STATUS_APPROVED], 'paid', function (StaffFundWithdrawal $locked) use ($finance): void {
            $companyId = (int) $locked->company_id;
            $amount = round((float) $locked->amount, 2);
            $record = $this->memberBalance($companyId, (int) $locked->employee_id);

            if ($amount > $record + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Amount is more than the staff fund benefit record of '.$locked->employee?->full_name.' ('.number_format($record).')']);
            }
            $this->assertAvailable($companyId, $amount);

            $entry = $this->ledger->journal($companyId, "Staff benefit payment - {$locked->employee?->full_name}", [
                ['account' => Account::StaffFund, 'employee' => $locked->employee_id, 'debit' => $amount],
                ['account' => Account::StaffFundCash, 'credit' => $amount],
            ], $locked, employee: $finance);

            $locked->update(['status' => StaffFundWithdrawal::STATUS_PAID, 'paid_by' => $finance->id, 'paid_at' => now(), 'journal_entry_id' => $entry->id]);
        });
    }

    /**
     * Lock the claim, check its status, enforce rule 6 (the HR preparer and the claimant cannot review, approve, reject or pay
     * it — the Super Admin may) and apply the change in one transaction. Status changes are audited by the model.
     *
     * @param  list<string>  $from
     * @param  callable(StaffFundWithdrawal): mixed  $apply
     *
     * @throws ValidationException
     */
    private function transitionClaim(StaffFundWithdrawal $claim, Employee $actor, array $from, string $verb, callable $apply): void
    {
        $this->duties->assertCanApprove([$claim->prepared_by, $claim->employee_id], $actor, 'staff benefit claim', workflow: ApprovalPolicy::PAYROLL);

        DB::transaction(function () use ($claim, $from, $verb, $apply): void {
            $locked = StaffFundWithdrawal::whereKey($claim->id)->with('employee')->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'This staff benefit claim is '.$locked->statusLabel().' and cannot be '.$verb.'.']);
            }

            $apply($locked);
        });
    }

    /**
     * Fund report (OVERVIEW ALL REPORT "Staff Fund Report": contributions, loans issued, advances issued, balance).
     *
     * Members carry the spec §26 benefit record: `contributions` / `balance` are the employee's own fund money, and
     * `company_contribution` the company's obligation (never counted in the fund `balance`).
     *
     * @return array{balance: float, liability: float, contributions: float, company_contributions_owed: float, total_benefit_record: float, withdrawals: float, loans_issued: float, advances_issued: float, repayments: float, income: float, opening_balance: float, statement: list<array<string, mixed>>, members: list<array<string, mixed>>}
     */
    public function report(int $companyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $cashAccount = $this->ledger->account($companyId, Account::StaffFundCash);

        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $cashAccount->id)
            ->when($from, fn ($query) => $query->whereDate('journal_entries.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->whereDate('journal_entries.entry_date', '<=', $to->toDateString()))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->get(['journal_lines.debit', 'journal_lines.credit', 'journal_entries.entry_date', 'journal_entries.reference', 'journal_entries.description']);

        $opening = $from ? $this->ledger->balance($companyId, Account::StaffFundCash, until: $from->subDay()) : 0.0;
        $running = $opening;
        $statement = $lines->map(function ($line) use (&$running): array {
            $running = round($running + (float) $line->debit - (float) $line->credit, 2);

            return [
                'date' => substr((string) $line->entry_date, 0, 10),
                'reference' => $line->reference,
                'description' => $line->description,
                'inflow' => (float) $line->debit,
                'outflow' => (float) $line->credit,
                'balance' => $running,
            ];
        })->all();

        $memberLines = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.key', Account::StaffFund->value)
            ->whereNotNull('accounts.employee_id')
            ->groupBy('accounts.employee_id')
            ->selectRaw('accounts.employee_id, SUM(journal_lines.credit) AS credits, SUM(journal_lines.debit) AS debits')
            ->get();

        $memberLines = $memberLines->keyBy(fn ($row): int => (int) $row->employee_id);
        $employeeIds = $memberLines->keys()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $employees = Employee::whereIn('id', $employeeIds)->with('branch')->get()->keyBy('id');
        $members = $employeeIds->map(function (int $employeeId) use ($memberLines, $employees): array {
            $row = $memberLines->get($employeeId);
            $balance = round((float) ($row->credits ?? 0) - (float) ($row->debits ?? 0), 2);

            return [
                'employee_id' => $employeeId,
                'employee' => $employees->get($employeeId)?->full_name,
                'branch' => $employees->get($employeeId)?->branch?->name,
                'contributions' => round((float) ($row->credits ?? 0), 2),
                'withdrawals' => round((float) ($row->debits ?? 0), 2),
                'balance' => $balance,
                'staff_contribution' => $balance,
                'total_benefit_record' => $balance,
            ];
        })->values()->all();

        $dated = fn ($query, string $column) => $query
            ->when($from, fn ($inner) => $inner->whereDate($column, '>=', $from->toDateString()))
            ->when($to, fn ($inner) => $inner->whereDate($column, '<=', $to->toDateString()));

        $liability = $this->ledger->balance($companyId, Account::StaffFund, allBranches: true);
        $memberTotal = round((float) collect($members)->sum('balance'), 2);

        return [
            'balance' => $this->cashBalance($companyId),
            'liability' => $liability,
            'contributions' => round((float) collect($members)->sum('contributions'), 2),
            'total_benefit_record' => round((float) collect($members)->sum('total_benefit_record'), 2),
            'withdrawals' => round((float) $dated(StaffFundWithdrawal::where('company_id', $companyId)->where('status', StaffFundWithdrawal::STATUS_PAID), 'paid_at')->sum('amount'), 2),
            'loans_issued' => round((float) $dated(StaffLoan::where('company_id', $companyId)->whereNotNull('disbursed_at'), 'disbursed_at')->sum('amount_approved'), 2),
            'advances_issued' => round((float) $dated(StaffSalaryAdvance::where('company_id', $companyId)->whereNotNull('disbursed_at')->where('source_account', Account::StaffFundCash->value), 'disbursed_at')->sum('amount'), 2),
            'repayments' => round((float) $lines->filter(fn ($line): bool => str_contains(strtolower((string) $line->description), 'repayment') || str_contains(strtolower((string) $line->description), 'salary payment'))->sum('debit'), 2),
            'income' => round($liability - $memberTotal, 2),
            'opening_balance' => $opening,
            'statement' => $statement,
            'members' => $members,
        ];
    }
}
