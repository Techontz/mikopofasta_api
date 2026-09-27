<?php

namespace App\Services;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Services\Approvals\ReserveProtection;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Float movements (live Capital → Float pages).
 *
 * Rule 6 (segregation of duties): every float — company → branch, branch → branch, account → account — is initiated as
 * PENDING (no journal, no balance movement) and posted only when a different authorised user approves it
 * ({@see SegregationOfDuties}). Approval runs inside one transaction with the company and transfer rows locked, so the
 * source balance is checked against committed postings and a double approval posts once. Reversals: {@see TransferReversal}.
 */
class FloatService
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Staff roles that may request, approve or reject a float (user decision 2026-09-17); others with float.manage only view. */
    public const TRANSFER_ROLES = ['super_admin', 'admin'];

    public const TRANSFER_MESSAGE = 'Only Super Admin or Admin can transfer float.';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * Whether the employee may request, approve or reject a float: a Super Admin or Admin staff login (never a shareholder
     * portal account), on top of float.manage.
     */
    public static function canTransfer(Employee $employee): bool
    {
        return in_array($employee->role?->key, self::TRANSFER_ROLES, true) && ! $employee->isShareholderAccount();
    }

    /**
     * Where a company → HQ float may be taken from: the COMPANY ACCOUNT, a company bank account or the Investment
     * RESERVE A/C. Never a fixed asset — assets are not money.
     *
     * @return list<Account>
     */
    public static function hqFloatSources(): array
    {
        return [Account::Company, Account::Bank, Account::InvestmentReserve];
    }

    /**
     * Request a company money account → HQ PRINCIPAL A/C float (pending approval). The company funds HQ only: branches hold
     * no lending money, HQ/Finance approves and disburses loans, so there is no company → branch float.
     */
    public function requestCompanyToHq(int $companyId, Account $from, ?int $bankAccountId, float $amount, Employee $requester): FloatTransfer
    {
        $amount = round($this->ensurePositive($amount, 'amount'), 2);

        if (! in_array($from, self::hqFloatSources(), true)) {
            throw ValidationException::withMessages(['from_account' => 'Select the Company A/C, a bank account or the Investment RESERVE A/C']);
        }
        if ($from === Account::Bank && $bankAccountId === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Select the company bank account the money leaves']);
        }
        $bankAccountId = $from === Account::Bank ? $bankAccountId : null;
        $this->ensureBalance($companyId, $from, null, $amount, 'amount', $bankAccountId);

        return $this->createPending($companyId, $requester, [
            'type' => 'company_to_hq',
            'from_account' => $from->value,
            'bank_account_id' => $bankAccountId,
            'to_account' => Account::Principal->value,
            'amount' => $amount,
        ]);
    }

    /**
     * Approve a pending float and post it: Dr the receiving account / Cr the sending account, provided the sender holds the
     * amount. The approver must not be the requester unless self-approval is explicitly granted.
     */
    public function approve(FloatTransfer $transfer, ?Employee $approver = null): FloatTransfer
    {
        return DB::transaction(function () use ($transfer, $approver): FloatTransfer {
            $this->lockCompany((int) $transfer->company_id);
            $locked = FloatTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['transfer' => 'Transaction is already processed']);
            }
            if ($approver !== null) {
                $this->duties->assertCanApprove($locked->requested_by, $approver, 'float', workflow: ApprovalPolicy::FLOATS);
            }

            $from = Account::from($locked->from_account);
            $to = Account::from($locked->to_account);
            ReserveProtection::assertNotReserveSource($from, 'transfer');

            $amount = (float) $locked->amount;
            $source = match (true) {
                $from === Account::Bank => ['account' => $from, 'bank' => $locked->bank_account_id],
                in_array($from, [Account::Company, Account::InvestmentReserve], true) => ['account' => $from],
                default => ['account' => $from, 'branch' => $locked->from_branch_id],
            };
            $this->ensureBalance((int) $locked->company_id, $from, $source['branch'] ?? null, $amount, 'transfer', $source['bank'] ?? null);

            $entry = $this->ledger->transfer(
                $locked->company_id,
                $source,
                ['account' => $to, 'branch' => $locked->to_branch_id],
                $amount,
                $this->description($locked->type, $from, $to),
                $locked,
            );

            $locked->update([
                'status' => self::APPROVED,
                'transfer_date' => today(),
                'journal_entry_id' => $entry->id,
                'approved_by' => $approver?->id,
                'approved_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Reject a pending float: nothing was posted, the row is kept with the reason.
     */
    public function reject(FloatTransfer $transfer, string $reason, Employee $employee): FloatTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $employee): FloatTransfer {
            $locked = FloatTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['reason' => 'Only pending transfers can be rejected']);
            }

            $locked->update(['status' => self::REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->auditRejection($locked, $employee, $reason);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPending(int $companyId, ?Employee $requester, array $attributes): FloatTransfer
    {
        return FloatTransfer::create($attributes + [
            'company_id' => $companyId,
            'status' => self::PENDING,
            'requested_by' => $requester?->id,
            'transfer_date' => today(),
        ]);
    }

    private function description(string $type, Account $from, Account $to): string
    {
        return match ($type) {
            'company_to_hq' => 'FLOAT '.$from->label().' TO HQ '.$to->label(),
            default => 'FLOAT '.$from->label().' TO '.$to->label(),
        };
    }

    private function auditRejection(FloatTransfer $transfer, Employee $employee, string $reason): void
    {
        AuditLog::create([
            'company_id' => $transfer->company_id,
            'employee_id' => $employee->id,
            'action' => 'FloatTransfer.rejected',
            'auditable_type' => $transfer->getMorphClass(),
            'auditable_id' => $transfer->id,
            'before' => ['status' => self::PENDING],
            'after' => ['status' => self::REJECTED],
            'context' => ['reason' => $reason, 'amount' => (float) $transfer->amount, 'type' => $transfer->type],
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Serialises balance-changing movements of one company: balances are checked with the company row locked.
     */
    private function lockCompany(int $companyId): void
    {
        Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
    }

    private function ensurePositive(float $amount, string $field): float
    {
        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages([$field => 'Amount must be greater than zero']);
        }

        return $amount;
    }

    private function ensureBalance(int $companyId, Account $account, ?int $branchId, float $amount, string $field, ?int $bankAccountId = null): void
    {
        if ($this->ledger->balance($companyId, $account, $branchId, $bankAccountId) < $amount) {
            throw ValidationException::withMessages([$field => 'Insufficient balance in '.$account->label()]);
        }
    }
}
