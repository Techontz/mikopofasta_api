<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\SalaryAdvancePayment;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer salary advance (live "perifelar" loans): request → approve → repayment → done.
 *
 * Ledger:
 *  - approval: Dr Salary Advance Receivable (branch) / Cr PRINCIPAL A/C. An advance is lent out of the same Operation
 *    Principal every loan is funded from, so the money leaves HQ's lending pool and nothing else (advances approved before
 *    this rule drew on the HQ SALARY ADVANCE account; they stay as booked). The category fee is NOT income at approval (C2);
 *  - fee collection ({@see collectFee()}): only when the fee is actually collected, once — Dr LOAN FEE A/C (branch) / Cr FEE
 *    INCOME (branch). Advances approved before C2 posted the fee with the approval journal; that stays as booked;
 *  - repayment (spec §8): principal returns to the Operation Principal it was lent from and the profit goes to the interest
 *    pool as its own income — Dr PRINCIPAL A/C (principal portion) + Dr HQ Interest (profit portion) / Cr Receivable
 *    (principal) / Cr Salary Advance Income (profit). Repayments posted before this rule debited HQ Salary Advance;
 *    they are not rewritten;
 *  - deposit reversal ({@see reversePayment()}, approved through Reversal Requests): the deposit's journal is mirrored and
 *    the deposit marked reversed — never deleted — so the amount is owed again and a finished advance is active again;
 *  - removal of an approved advance: reversal of every entry above — the fee journal only exists (and is mirrored) when the fee
 *    was collected (Documents: no delete, reversal only).
 */
class SalaryAdvanceService
{
    public function __construct(private readonly Ledger $ledger) {}

    public function request(Customer $customer, SalaryAdvanceCategory $category, float $amount, ?Employee $employee = null): SalaryAdvance
    {
        if ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to) {
            throw ValidationException::withMessages([
                'loan_amount' => 'Loan amount must be between '.money($category->amount_from).' - '.money($category->amount_to),
            ]);
        }

        return SalaryAdvance::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'employee_id' => $employee?->id,
            'salary_advance_category_id' => $category->id,
            'amount' => $amount,
            'interest_rate' => $category->interest_rate,
            'total_payable' => round($amount * (1 + (float) $category->interest_rate / 100), 2),
            'fee' => $category->fee,
            'status' => 'pending',
        ]);
    }

    public function approve(SalaryAdvance $advance): void
    {
        DB::transaction(function () use ($advance): void {
            $locked = SalaryAdvance::lockForUpdate()->findOrFail($advance->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Salary advance is already approved']);
            }

            $locked->update(['status' => 'active', 'approved_at' => now()]);

            $this->ledger->journal($locked->company_id, 'SALARY ADVANCE LOAN', [
                ['account' => Account::SalaryAdvanceReceivable, 'branch' => $locked->branch_id, 'debit' => (float) $locked->amount],
                ['account' => Account::Principal, 'credit' => (float) $locked->amount],
            ], $locked, null, $locked->branch_id);

            $advance->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Record the ACTUAL collection of an approved advance's fee (C2): Dr LOAN FEE A/C / Cr FEE INCOME (branch), exactly once —
     * the advance row is locked, and an advance that is not approved, reversed, has no fee, or whose fee was already collected
     * (now or, legacy, at approval) is rejected with 422.
     *
     * @throws ValidationException
     */
    public function collectFee(SalaryAdvance $advance, Employee $employee, string $method = 'CASH', ?string $reference = null): SalaryAdvance
    {
        return DB::transaction(function () use ($advance, $employee, $method, $reference): SalaryAdvance {
            /** @var SalaryAdvance $locked */
            $locked = SalaryAdvance::lockForUpdate()->findOrFail($advance->id);

            $blocked = match (true) {
                $locked->reversed_at !== null || $locked->status === 'reversed' => 'Salary advance is reversed; its fee cannot be collected.',
                ! in_array($locked->status, ['active', 'done'], true) => 'The fee can only be collected on an approved salary advance.',
                (float) $locked->fee <= 0 => 'This salary advance has no fee to collect.',
                $locked->is_legacy_opening => 'This salary advance was carried over from the old system; its Charges were that system\'s business and is not income here.',
                $locked->fee_journal_entry_id !== null, $locked->feePostedAtApproval() => 'The fee of this salary advance has already been collected.',
                default => null,
            };
            if ($blocked !== null) {
                throw ValidationException::withMessages(['fee' => $blocked]);
            }

            $entry = $this->ledger->journal($locked->company_id, 'SALARY ADVANCE FEE', [
                ['account' => Account::LoanFee, 'branch' => $locked->branch_id, 'debit' => (float) $locked->fee],
                ['account' => Account::FeeIncome, 'branch' => $locked->branch_id, 'credit' => (float) $locked->fee],
            ], $locked, null, $locked->branch_id, $employee, TransactionType::SalaryAdvanceRepayment);

            $locked->update([
                'fee_collected_at' => now(),
                'fee_collected_by' => $employee->id,
                'fee_collection_method' => $method,
                'fee_collection_reference' => $reference,
                'fee_journal_entry_id' => $entry->id,
            ]);
            $advance->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Repayment of an active advance. The business rule "Principal → Penalty → Interest" is applied:
     * principal is recovered first, interest only after the principal is fully repaid (no penalty exists
     * on salary advances).
     */
    public function pay(SalaryAdvance $advance, float $amount, ?CarbonImmutable $date = null, ?Employee $employee = null): SalaryAdvancePayment
    {
        $date ??= CarbonImmutable::today();

        return DB::transaction(function () use ($advance, $amount, $date, $employee): SalaryAdvancePayment {
            $locked = SalaryAdvance::lockForUpdate()->findOrFail($advance->id);

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['amount' => 'Salary advance is not active']);
            }

            // An advance carried over from the old system counts what that system had already collected (opening_paid)
            // as repaid, principal first like every other repayment here.
            $paid = (float) $locked->opening_paid + (float) $locked->payments()->sum('amount');
            $remaining = max(0, round((float) $locked->total_payable - $paid, 2));

            if ($amount > $remaining + 0.001) {
                throw ValidationException::withMessages(['amount' => 'Amount is greater than remain amount ('.money($remaining).')']);
            }

            $principal = round(min($amount, max(0, (float) $locked->amount - $paid)), 2);
            $interest = round($amount - $principal, 2);

            $payment = $locked->payments()->create(['amount' => $amount, 'paid_on' => $date->toDateString()]);

            $this->ledger->journal($locked->company_id, 'SALARY ADVANCE DEPOSIT', [
                ['account' => Account::Principal, 'debit' => $principal],
                ['account' => Account::HqInterest, 'debit' => $interest],
                ['account' => Account::SalaryAdvanceReceivable, 'branch' => $locked->branch_id, 'credit' => $principal],
                // §9: salary advance profit is its own income category and carries no 20% reserve. Repayments posted
                // before this rule credited INTEREST INCOME and stay as they were.
                ['account' => Account::SalaryAdvanceIncome, 'branch' => $locked->branch_id, 'credit' => $interest],
            ], $payment, $date, $locked->branch_id, $employee);

            if ($amount >= $remaining - 0.001) {
                $locked->update(['status' => 'done']);
            }

            return $payment;
        });
    }

    /**
     * Why a deposit cannot be reversed now, or null. Deposits are reversed newest first: each one's principal/interest split
     * depends on what was paid before it.
     */
    public function paymentReversalBlocker(SalaryAdvancePayment $payment): ?string
    {
        $advance = $payment->salaryAdvance;

        if ($payment->reversed_at !== null) {
            return 'This deposit has already been reversed.';
        }
        if ($advance === null || $advance->reversed_at !== null || $advance->status === 'reversed') {
            return 'The salary advance of this deposit has been reversed; its deposits were reversed with it.';
        }

        $later = $advance->payments()
            ->whereKeyNot($payment->id)
            ->where(fn ($query) => $query->whereDate('paid_on', '>', $payment->paid_on->toDateString())
                ->orWhere(fn ($sameDay) => $sameDay->whereDate('paid_on', $payment->paid_on->toDateString())->where('id', '>', $payment->id)))
            ->exists();
        if ($later) {
            return 'A later deposit exists on this salary advance; reverse the newest deposit first.';
        }

        $entry = $payment->journalEntry;
        if ($entry === null) {
            return 'This deposit has no linked ledger posting; it needs a manual correction.';
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this deposit was already reversed directly in the ledger; it needs a manual correction.";
        }

        return app(LoanService::class)->distributedPeriodBlocker((int) $advance->company_id, CarbonImmutable::parse($entry->entry_date), 'salary advance deposit');
    }

    /**
     * Reverse one deposit (run when its reversal request is approved): the journal is mirrored, the deposit is kept and
     * marked reversed, and an advance the deposit had finished is active again.
     *
     * @throws ValidationException
     */
    public function reversePayment(SalaryAdvancePayment $payment, string $reason, Employee $employee): SalaryAdvancePayment
    {
        return DB::transaction(function () use ($payment, $reason, $employee): SalaryAdvancePayment {
            $advance = SalaryAdvance::whereKey($payment->salary_advance_id)->lockForUpdate()->firstOrFail();
            $payment = SalaryAdvancePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->setRelation('salaryAdvance', $advance);

            if (($blocker = $this->paymentReversalBlocker($payment)) !== null) {
                throw ValidationException::withMessages(['reason' => $blocker]);
            }

            $entry = $payment->journalEntry;
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);
            $reversal = $this->ledger->reverse($entry->loadMissing('lines'), 'SALARY ADVANCE DEPOSIT REVERSED: '.$reason);

            $payment->update([
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ]);
            $statusBefore = $advance->status;
            if ($advance->status === 'done') {
                $advance->update(['status' => 'active']);
            }

            AuditLog::create([
                'company_id' => $advance->company_id,
                'employee_id' => $employee->id,
                'action' => 'SalaryAdvancePayment.reversed',
                'auditable_type' => $payment->getMorphClass(),
                'auditable_id' => $payment->id,
                'before' => ['reversed_at' => null, 'advance_status' => $statusBefore],
                'after' => ['reversed_at' => $payment->reversed_at?->toIso8601String(), 'reversal_journal_entry_id' => $reversal->id, 'advance_status' => $advance->status],
                'context' => ['reason' => $reason, 'salary_advance_id' => $advance->id, 'amount' => (float) $payment->amount, 'journal_reference' => $entry->reference, 'reversal_reference' => $reversal->reference],
                'ip_address' => request()?->ip(),
            ]);

            return $payment;
        });
    }

    /**
     * Remove a salary advance. A pending request has no money effect and is deleted; an approved
     * advance is kept and marked reversed, and its approval and repayment entries are reversed.
     */
    public function remove(SalaryAdvance $advance, ?string $reason, Employee $employee): void
    {
        if ($advance->reversed_at !== null) {
            throw ValidationException::withMessages(['status' => 'Salary advance is already reversed']);
        }

        if ($advance->is_legacy_opening) {
            throw ValidationException::withMessages(['status' => 'This salary advance was carried over from the old system. Roll back its import (Legacy Imports) instead of reversing it here.']);
        }

        if ($advance->status === 'pending') {
            $advance->delete();

            return;
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please enter the reason for reversal']);
        }

        DB::transaction(function () use ($advance, $reason, $employee): void {
            $entries = JournalEntry::query()
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal')
                ->where(function ($query) use ($advance): void {
                    $query->where(fn ($inner) => $inner->where('source_type', $advance->getMorphClass())->where('source_id', $advance->id))
                        ->orWhere(fn ($inner) => $inner->where('source_type', (new SalaryAdvancePayment)->getMorphClass())->whereIn('source_id', $advance->payments()->pluck('id')));
                })
                ->with('lines')
                ->get();

            foreach ($entries as $entry) {
                $this->ledger->reverse($entry, (string) $reason);
            }

            $advance->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversed_by' => $employee->id,
            ]);
        });
    }
}
