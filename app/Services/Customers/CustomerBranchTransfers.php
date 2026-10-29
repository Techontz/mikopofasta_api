<?php

namespace App\Services\Customers;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchTransfer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Saving;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer branch transfer (enhancement §2.3). The customer keeps one profile; approval, in one transaction:
 *
 * - moves the customer, every loan not yet ended (in the pipeline or being repaid) and their unpaid penalties to the new
 *   branch, so the new branch collects, follows up and reports them from now on;
 * - reclassifies what the old branch's ledger still carries for them — outstanding principal (LOAN RECEIVABLE), accrued
 *   unpaid penalties (PENALTY RECEIVABLE) and the savings balance (SAVINGS DEPOSITS / HQ SAVING) — with one entry,
 *   Dr new branch / Cr old branch, so later repayments credit the branch that holds the receivable;
 * - leaves history untouched: repayments, payments, paid penalties, savings rows and journal entries keep the old branch,
 *   so what was collected while the customer belonged there stays that branch's.
 *
 * Ended loans (closed, rejected, cancelled, written off) stay with the old branch. A loan whose money is moving
 * (awaiting disbursement, failed, escalated or in suspense) blocks the transfer until it is settled.
 */
class CustomerBranchTransfers
{
    /**
     * @var list<LoanStatus>
     */
    public const BLOCKING = [LoanStatus::AwaitingDisbursement, LoanStatus::DisbursementFailed, LoanStatus::Escalated, LoanStatus::DisbursementSuspense];

    public function __construct(
        private Ledger $ledger,
        private LoanService $loans,
        private SegregationOfDuties $duties,
        private CustomerRegistrar $registrar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function request(Customer $customer, int $toBranchId, string $reason, Employee $actor): CustomerBranchTransfer
    {
        return DB::transaction(function () use ($customer, $toBranchId, $reason, $actor): CustomerBranchTransfer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $this->assertMovable($customer, $toBranchId);

            if (CustomerBranchTransfer::where('customer_id', $customer->id)->where('status', CustomerBranchTransfer::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['customer' => 'A branch transfer for this customer is already waiting for approval.']);
            }

            $transfer = CustomerBranchTransfer::create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'from_branch_id' => $customer->branch_id,
                'to_branch_id' => $toBranchId,
                'reason' => trim($reason),
                'status' => CustomerBranchTransfer::STATUS_PENDING,
                'requested_by' => $actor->id,
            ]);
            $this->registrar->audit($customer, 'Customer.branch_transfer_requested', [
                'transfer_id' => $transfer->id, 'to_branch_id' => $toBranchId, 'reason' => $transfer->reason,
            ], ['branch_id' => $customer->branch_id], $actor);

            return $transfer;
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(CustomerBranchTransfer $transfer, Employee $approver, ?string $note = null): CustomerBranchTransfer
    {
        $this->duties->assertCanApprove($transfer->requested_by, $approver);

        return DB::transaction(function () use ($transfer, $approver, $note): CustomerBranchTransfer {
            $transfer = $this->lockPending($transfer);
            $customer = Customer::query()->lockForUpdate()->findOrFail($transfer->customer_id);
            $from = (int) $transfer->from_branch_id;
            $to = (int) $transfer->to_branch_id;

            if ((int) $customer->branch_id !== $from) {
                throw ValidationException::withMessages(['customer' => 'The customer has changed branch since this transfer was requested. Reject it and request a new one.']);
            }
            $this->assertMovable($customer, $to);

            $loans = Loan::query()->where('customer_id', $customer->id)->where('branch_id', $from)
                ->status(...LoanStatus::inPipeline(), ...LoanStatus::repayable())
                ->lockForUpdate()->get();

            $principal = 0.0;
            $accruedPenalties = 0.0;
            foreach ($loans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::repayable(), true)) as $loan) {
                $principal += $this->loans->outstanding($loan)['principal'];
                $accruedPenalties += (float) Penalty::where('loan_id', $loan->id)->where('is_waived', false)->whereNotNull('accrual_journal_entry_id')
                    ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) v')->value('v');
            }
            $savings = round((float) Saving::where('customer_id', $customer->id)->where('branch_id', $from)->whereNull('reversed_at')
                ->sum(DB::raw("CASE WHEN type = 'deposit' THEN amount ELSE -amount END")), 2);
            $principal = round($principal, 2);
            $accruedPenalties = round($accruedPenalties, 2);

            $penaltyIds = Penalty::query()->whereIn('loan_id', $loans->pluck('id'))->where('is_waived', false)
                ->whereColumn('paid_amount', '<', 'amount')->pluck('id');

            $entry = null;
            if ($principal > 0 || $accruedPenalties > 0 || $savings > 0) {
                $entry = $this->ledger->journal($customer->company_id, "CUSTOMER BRANCH TRANSFER {$customer->customer_number}", [
                    ['account' => Account::LoanReceivable, 'branch' => $to, 'debit' => $principal],
                    ['account' => Account::LoanReceivable, 'branch' => $from, 'credit' => $principal],
                    ['account' => Account::PenaltyReceivable, 'branch' => $to, 'debit' => $accruedPenalties],
                    ['account' => Account::PenaltyReceivable, 'branch' => $from, 'credit' => $accruedPenalties],
                    ['account' => Account::HqSaving, 'branch' => $to, 'debit' => max(0.0, $savings)],
                    ['account' => Account::HqSaving, 'branch' => $from, 'credit' => max(0.0, $savings)],
                    ['account' => Account::SavingsDeposits, 'branch' => $from, 'debit' => max(0.0, $savings)],
                    ['account' => Account::SavingsDeposits, 'branch' => $to, 'credit' => max(0.0, $savings)],
                ], $transfer, null, $to, $approver, TransactionType::InternalTransfer);
            }

            Loan::query()->whereKey($loans->pluck('id'))->update(['branch_id' => $to]);
            Penalty::query()->whereKey($penaltyIds)->update(['branch_id' => $to]);
            $customer->forceFill(['branch_id' => $to])->save();

            $transfer->forceFill([
                'status' => CustomerBranchTransfer::STATUS_APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_reason' => filled($note) ? trim((string) $note) : null,
                'journal_entry_id' => $entry?->id,
                'moved' => [
                    'loan_ids' => $loans->pluck('id')->all(),
                    'penalty_ids' => $penaltyIds->all(),
                    'principal' => $principal,
                    'accrued_penalties' => $accruedPenalties,
                    'savings' => $savings,
                ],
            ])->save();

            $this->registrar->audit($customer, 'Customer.branch_transferred', [
                'transfer_id' => $transfer->id,
                'branch_id' => $to,
                'requested_by' => $transfer->requested_by,
                'reason' => $transfer->reason,
                'moved' => $transfer->moved,
                'journal_entry_id' => $entry?->id,
            ], ['branch_id' => $from], $approver);

            return $transfer;
        });
    }

    public function reject(CustomerBranchTransfer $transfer, Employee $approver, string $reason): CustomerBranchTransfer
    {
        $this->duties->assertCanApprove($transfer->requested_by, $approver);

        return DB::transaction(function () use ($transfer, $approver, $reason): CustomerBranchTransfer {
            $transfer = $this->lockPending($transfer);
            $transfer->forceFill([
                'status' => CustomerBranchTransfer::STATUS_REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_reason' => trim($reason),
            ])->save();

            $customer = Customer::withTrashed()->findOrFail($transfer->customer_id);
            $this->registrar->audit($customer, 'Customer.branch_transfer_rejected', ['transfer_id' => $transfer->id, 'reason' => $transfer->decision_reason], [], $approver);

            return $transfer;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertMovable(Customer $customer, int $toBranchId): void
    {
        $branch = Branch::query()->where('company_id', $customer->company_id)->find($toBranchId);
        if ($branch === null) {
            throw ValidationException::withMessages(['to_branch_id' => 'Choose a branch of this company.']);
        }
        if ((int) $customer->branch_id === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'The customer already belongs to this branch.']);
        }
        if ($customer->trashed()) {
            throw ValidationException::withMessages(['customer' => 'A deleted customer cannot be transferred.']);
        }
        if (Loan::where('customer_id', $customer->id)->status(...self::BLOCKING)->exists()) {
            throw ValidationException::withMessages(['customer' => 'A loan of this customer is being disbursed. Finish or cancel the disbursement before transferring the customer.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(CustomerBranchTransfer $transfer): CustomerBranchTransfer
    {
        $locked = CustomerBranchTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
        if ($locked->status !== CustomerBranchTransfer::STATUS_PENDING) {
            throw ValidationException::withMessages(['transfer' => 'This branch transfer was already decided.']);
        }

        return $locked;
    }
}
