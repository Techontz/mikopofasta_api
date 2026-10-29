<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanDiscount;
use App\Models\LoanTransaction;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan Discount / Waiver (enhancement §6), e.g. outstanding TZS 1,500,000, the customer pays 1,450,000 and 50,000 is
 * waived. A request changes nothing; only its approval, by another authorised user, reduces the debt:
 *
 * - a `discount` loan transaction (method DISCOUNT) settles the waived amount — interest first, then insurance, then
 *   principal — and the instalments it covers, exactly like a repayment would, but it is never cash: every cash report,
 *   collection total and "paid" figure counts deposits only;
 * - the principal waived leaves the branch LOAN RECEIVABLE: Dr LOAN DISCOUNT / WAIVER EXPENSE / Cr LOAN RECEIVABLE.
 *   Interest and insurance are recognised only when collected (cash basis), so waiving them needs no entry.
 *
 * Penalties are not part of a discount: they keep their own waiver (Penalty → waive). Approval is serialised by row locks,
 * so a request is applied once at most.
 */
class LoanDiscounts
{
    public function __construct(
        private Ledger $ledger,
        private LoanService $loans,
        private SegregationOfDuties $duties,
    ) {}

    /**
     * What a discount may cover: the outstanding interest, insurance and principal (not penalties).
     */
    public function waivable(Loan $loan): float
    {
        $outstanding = $this->loans->outstanding($loan);

        return round($outstanding['interest'] + $outstanding['insurance'] + $outstanding['principal'], 2);
    }

    /**
     * @throws ValidationException
     */
    public function request(Loan $loan, float $amount, float $actualPayment, string $reason, Employee $actor): LoanDiscount
    {
        return DB::transaction(function () use ($loan, $amount, $actualPayment, $reason, $actor): LoanDiscount {
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);
            $this->assertDiscountable($loan, $amount);

            if (LoanDiscount::where('loan_id', $loan->id)->where('status', LoanDiscount::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['loan' => 'A discount request for this loan is already waiting for approval.']);
            }

            $discount = LoanDiscount::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'original_debt' => $this->loans->outstanding($loan)['total'],
                'actual_payment' => round($actualPayment, 2),
                'requested_amount' => round($amount, 2),
                'reason' => trim($reason),
                'status' => LoanDiscount::STATUS_PENDING,
                'requested_by' => $actor->id,
            ]);
            $this->audit($discount, 'LoanDiscount.requested', $actor, ['requested_amount' => (float) $discount->requested_amount, 'reason' => $discount->reason]);

            return $discount;
        });
    }

    /**
     * Approve the request (the requested amount, or less). Applies the discount and posts it once.
     *
     * @throws ValidationException
     */
    public function approve(LoanDiscount $discount, Employee $approver, ?float $approvedAmount = null, ?string $note = null): LoanDiscount
    {
        $this->duties->assertCanApprove($discount->requested_by, $approver);

        return DB::transaction(function () use ($discount, $approver, $approvedAmount, $note): LoanDiscount {
            $discount = $this->lockPending($discount);
            $loan = Loan::lockForUpdate()->findOrFail($discount->loan_id);
            $amount = round($approvedAmount ?? (float) $discount->requested_amount, 2);

            if ($amount > (float) $discount->requested_amount + 0.001) {
                throw ValidationException::withMessages(['approved_amount' => 'The approved amount cannot be more than the amount requested.']);
            }
            $this->assertDiscountable($loan, $amount, 'approved_amount');

            $outstanding = $this->loans->outstanding($loan);
            $interest = min($amount, $outstanding['interest']);
            $insurance = min($amount - $interest, $outstanding['insurance']);
            $principal = round($amount - $interest - $insurance, 2);
            $today = CarbonImmutable::today();

            $transaction = LoanTransaction::create([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'employee_id' => $approver->id,
                'type' => LoanTransaction::TYPE_DISCOUNT,
                'description' => 'LOAN DISCOUNT / WAIVER',
                'method' => 'DISCOUNT',
                'amount' => $amount,
                'principal' => $principal,
                'penalty' => 0,
                'interest' => round($interest, 2),
                'insurance' => round($insurance, 2),
                'reserve' => 0,
                'transaction_date' => $today->toDateString(),
            ]);

            $entry = null;
            if ($principal > 0) {
                $entry = $this->ledger->journal($loan->company_id, 'LOAN DISCOUNT / WAIVER '.$loan->loan_number, [
                    ['account' => Account::LoanDiscountExpense, 'branch' => $loan->branch_id, 'debit' => $principal],
                    ['account' => Account::LoanReceivable, 'branch' => $loan->branch_id, 'credit' => $principal],
                ], $transaction, $today, $loan->branch_id, $approver, TransactionType::Adjustment);
                $transaction->forceFill(['journal_entry_id' => $entry->id])->save();
            }

            $this->loans->allocateToSchedules($loan, $amount);
            if ($this->loans->outstanding($loan->fresh())['total'] <= 0.5) {
                $this->loans->close($loan->fresh(), $today);
            }

            $discount->forceFill([
                'status' => LoanDiscount::STATUS_APPROVED,
                'approved_amount' => $amount,
                'principal' => $principal,
                'interest' => round($interest, 2),
                'insurance' => round($insurance, 2),
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim((string) $note) : null,
                'loan_transaction_id' => $transaction->id,
                'journal_entry_id' => $entry?->id,
            ])->save();
            $this->audit($discount, 'LoanDiscount.approved', $approver, [
                'approved_amount' => $amount, 'principal' => $principal, 'interest' => round($interest, 2), 'insurance' => round($insurance, 2),
                'loan_transaction_id' => $transaction->id, 'journal_entry_id' => $entry?->id,
            ]);

            return $discount;
        });
    }

    public function reject(LoanDiscount $discount, Employee $approver, string $reason): LoanDiscount
    {
        $this->duties->assertCanApprove($discount->requested_by, $approver);

        return DB::transaction(function () use ($discount, $approver, $reason): LoanDiscount {
            $discount = $this->lockPending($discount);
            $discount->forceFill([
                'status' => LoanDiscount::STATUS_REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => trim($reason),
            ])->save();
            $this->audit($discount, 'LoanDiscount.rejected', $approver, ['reason' => $discount->decision_note]);

            return $discount;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertDiscountable(Loan $loan, float $amount, string $field = 'amount'): void
    {
        if (! in_array($loan->status, LoanStatus::repayable(), true)) {
            throw ValidationException::withMessages(['loan' => 'Only an issued loan that is still being repaid can be discounted.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages([$field => 'The discount must be more than zero.']);
        }
        $waivable = $this->waivable($loan);
        if ($amount > $waivable + 0.001) {
            throw ValidationException::withMessages([$field => 'The discount cannot be more than the outstanding principal, interest and insurance (TZS '.number_format($waivable, 2).'). Penalties are waived separately.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(LoanDiscount $discount): LoanDiscount
    {
        $locked = LoanDiscount::lockForUpdate()->findOrFail($discount->id);
        if ($locked->status !== LoanDiscount::STATUS_PENDING) {
            throw ValidationException::withMessages(['discount' => 'This discount request was already decided.']);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(LoanDiscount $discount, string $action, Employee $actor, array $after): void
    {
        AuditLog::create([
            'company_id' => $discount->company_id,
            'employee_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $discount->getMorphClass(),
            'auditable_id' => $discount->id,
            'after' => $after + ['loan_id' => $discount->loan_id],
            'ip_address' => request()?->ip(),
        ]);
    }
}
