<?php

namespace App\Services;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Approvals\ReserveProtection;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Reports\Financial\CashAccounts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Internal company fund movements recorded as `bank_transfers` rows and posted as ledger transfers (Dr the receiving
 * account, Cr the sending account; company totals unchanged; no balance is ever edited directly):
 *  - COMPANY ACCOUNT ↔ company bank account (types company_to_bank / bank_to_company),
 *  - HQ reserve → Investment RESERVE A/C (reserve_to_investment): the one allowed movement out of the interest reserve,
 *  - Investment RESERVE A/C → OPERATION PRINCIPAL (reserve_to_principal): the owners' leg of that same chain,
 *  - HQ interest → branch PETTY CASH A/C (petty_cash_to_branch): the only money a branch holds, spent only on expenses HQ approves.
 *
 * Rule 6 (segregation of duties): every movement is created PENDING (no journal) with its initiator in employee_id and
 * posted by {@see approve()} — a different authorised user, company and transfer rows locked, balance re-checked — or
 * rejected by {@see reject()}. Rule 3: nothing leaves a RESERVE account.
 */
class CompanyFunds
{
    public const CASH_TO_BANK = 'company_to_bank';

    public const BANK_TO_CASH = 'bank_to_company';

    public const RESERVE_TO_INVESTMENT = 'reserve_to_investment';

    /** The second leg: what the Investment already holds goes back into the lending cash (user ruling 2026-09-17). */
    public const RESERVE_TO_PRINCIPAL = 'reserve_to_principal';

    public const PETTY_CASH_TO_BRANCH = 'petty_cash_to_branch';

    /** Staff roles that may approve or reject HQ reserve → Investment; shareholders may too ({@see canDecideReserve()}). */
    public const RESERVE_APPROVER_ROLES = ['super_admin', 'admin'];

    public const RESERVE_APPROVER_MESSAGE = 'Only Super Admin, Admin or a Shareholder can approve or reject a reserve transfer.';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly SegregationOfDuties $duties,
        private readonly CashAccounts $cash,
    ) {}

    /**
     * Request a COMPANY ACCOUNT ↔ bank movement (pending approval). A repeated idempotency key returns the original row.
     *
     * @return array{transfer: BankTransfer, created: bool}
     */
    public function transfer(int $companyId, string $type, int $bankAccountId, float $amount, Employee $employee, ?string $reference = null, ?string $idempotencyKey = null): array
    {
        $amount = round($amount, 2);
        if (! in_array($type, [self::CASH_TO_BANK, self::BANK_TO_CASH], true)) {
            throw ValidationException::withMessages(['direction' => 'Select a valid transfer direction']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero']);
        }

        $previous = $this->replay($companyId, $type, $bankAccountId, $amount, $idempotencyKey);
        if ($previous !== null) {
            return ['transfer' => $previous, 'created' => false];
        }

        if (! BankAccount::where('company_id', $companyId)->whereKey($bankAccountId)->exists()) {
            throw ValidationException::withMessages(['bank_account_id' => 'Select a company bank account']);
        }

        try {
            $transfer = BankTransfer::create([
                'company_id' => $companyId,
                'type' => $type,
                'bank_account_id' => $bankAccountId,
                'employee_id' => $employee->id,
                'amount' => $amount,
                'reference' => $reference,
                'status' => self::PENDING,
                'transfer_date' => today(),
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replay($companyId, $type, $bankAccountId, $amount, $idempotencyKey);
            if ($previous === null) {
                throw $exception;
            }

            return ['transfer' => $previous, 'created' => false];
        }

        return ['transfer' => $transfer, 'created' => true];
    }

    /**
     * Request HQ reserve → Investment RESERVE A/C (pending approval). All interest reserve belongs to HQ, so HQ sends an amount
     * of the HQ reserve ({@see CashAccounts::hqReserve()}); nothing moves until another authorised user approves.
     */
    public function requestReserveToInvestment(int $companyId, float $amount, Employee $employee): BankTransfer
    {
        $this->ensureAmounts($amount, 0);
        $this->assertReserveCovers($companyId, round($amount, 2));

        return BankTransfer::create([
            'company_id' => $companyId,
            'type' => self::RESERVE_TO_INVESTMENT,
            'employee_id' => $employee->id,
            'hq_account' => Account::HqReserve->value,
            'amount' => round($amount, 2),
            'reference' => $this->newReference('RI'),
            'status' => self::PENDING,
            'transfer_date' => today(),
        ]);
    }

    /**
     * Request Investment RESERVE A/C → OPERATION PRINCIPAL (pending approval). The Investment can only send reserve Finance has
     * already sent it ({@see Account::InvestmentReserve}), never reserve still held at HQ.
     */
    public function requestReserveToPrincipal(int $companyId, float $amount, Employee $employee): BankTransfer
    {
        $this->ensureAmounts($amount, 0);
        $this->assertInvestmentReserveCovers($companyId, round($amount, 2));

        return BankTransfer::create([
            'company_id' => $companyId,
            'type' => self::RESERVE_TO_PRINCIPAL,
            'employee_id' => $employee->id,
            'hq_account' => Account::InvestmentReserve->value,
            'amount' => round($amount, 2),
            'reference' => $this->newReference('RP'),
            'status' => self::PENDING,
            'transfer_date' => today(),
        ]);
    }

    /**
     * The Investment RESERVE A/C must hold what is being sent to the OPERATION PRINCIPAL.
     *
     * @throws ValidationException
     */
    public function assertInvestmentReserveCovers(int $companyId, float $amount): float
    {
        $available = $this->ledger->balance($companyId, Account::InvestmentReserve) + 0.0;
        if ($available + 0.001 < $amount) {
            throw ValidationException::withMessages(['amount' => 'The Investment RESERVE A/C holds '.money($available).' — only reserve Finance has already sent can be moved to the OPERATION PRINCIPAL.']);
        }

        return $available;
    }

    /**
     * Request HQ interest → a branch PETTY CASH A/C (pending approval). Petty cash is funded from interest income
     * ({@see CashAccounts::hqInterest()}); the branch then spends it only on expenses HQ approves.
     */
    public function requestPettyCash(int $companyId, int $branchId, float $amount, Employee $employee): BankTransfer
    {
        $this->ensureAmounts($amount, 0);
        $this->assertInterestCovers($companyId, round($amount, 2));

        return BankTransfer::create([
            'company_id' => $companyId,
            'type' => self::PETTY_CASH_TO_BRANCH,
            'branch_id' => $branchId,
            'branch_account' => Account::PettyCash->value,
            'employee_id' => $employee->id,
            'hq_account' => Account::HqInterest->value,
            'amount' => round($amount, 2),
            'reference' => $this->newReference('PC'),
            'status' => self::PENDING,
            'transfer_date' => today(),
        ]);
    }

    /**
     * System reference for an internal HQ transfer, e.g. PC260917K3F9QD (petty cash) or RI260917… (reserve → investment).
     */
    private function newReference(string $prefix): string
    {
        return $prefix.now()->format('ymd').strtoupper(Str::random(6));
    }

    /**
     * Whether the employee may approve or reject a reserve transfer (HQ → Investment, Investment → OPERATION PRINCIPAL): Super Admin, Admin, or a login linked to
     * a shareholder of the company (Shareholder Portal account or staff who is also a shareholder). Maker/checker still applies.
     */
    public static function canDecideReserve(Employee $employee): bool
    {
        if (in_array($employee->role?->key, self::RESERVE_APPROVER_ROLES, true) && ! $employee->isShareholderAccount()) {
            return true;
        }

        $holder = $employee->shareHolder;

        return $holder !== null && (int) $holder->company_id === (int) $employee->company_id;
    }

    /**
     * Approve a pending movement and post it. The approver must not be the initiator unless self-approval is explicitly
     * granted; the sending account must hold the amount (plus the bank charge) at approval time.
     */
    public function approve(BankTransfer $transfer, Employee $approver): BankTransfer
    {
        return DB::transaction(function () use ($transfer, $approver): BankTransfer {
            Company::whereKey($transfer->company_id)->lockForUpdate()->firstOrFail();
            $locked = BankTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['amount' => 'Transaction already processed']);
            }
            $this->duties->assertCanApprove($locked->employee_id, $approver, 'bank transfer', workflow: ApprovalPolicy::BANK_TRANSFERS);

            if ($locked->type === self::RESERVE_TO_INVESTMENT) {
                $this->assertCanDecideReserve($approver);

                return $this->postReserveToInvestment($locked, $approver);
            }

            if ($locked->type === self::RESERVE_TO_PRINCIPAL) {
                $this->assertCanDecideReserve($approver);
                $this->assertInvestmentReserveCovers((int) $locked->company_id, (float) $locked->amount);
                $entry = $this->ledger->transfer(
                    $locked->company_id,
                    ['account' => Account::InvestmentReserve],
                    ['account' => Account::Principal],
                    (float) $locked->amount,
                    'INVESTMENT RESERVE A/C TO OPERATION PRINCIPAL',
                    $locked,
                );
                $locked->update(['status' => self::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now(), 'journal_entry_id' => $entry->id]);

                return $locked;
            }

            if ($locked->type === self::PETTY_CASH_TO_BRANCH) {
                return $this->postFromPool($locked, $approver, ['account' => Account::PettyCash, 'branch' => $locked->branch_id], $this->assertInterestCovers((int) $locked->company_id, (float) $locked->amount), 'HQ INTEREST TO BRANCH PETTY CASH A/C');
            }

            $bank = BankAccount::where('company_id', $locked->company_id)->whereKey($locked->bank_account_id)->lockForUpdate()->first();
            if ($bank === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'Select a company bank account']);
            }

            $amount = (float) $locked->amount;
            $charge = (float) $locked->charge;
            $bankLine = ['account' => Account::Bank, 'bank' => $bank->id];

            [$from, $to, $description] = match ($locked->type) {
                self::CASH_TO_BANK => [['account' => Account::Company], $bankLine, 'COMPANY CASH TO BANK - '.$bank->name],
                self::BANK_TO_CASH => [$bankLine, ['account' => Account::Company], 'BANK TO COMPANY CASH - '.$bank->name],
                default => throw ValidationException::withMessages(['amount' => 'Unknown transfer type']),
            };
            ReserveProtection::assertNotReserveSource($from['account'], 'amount');

            $available = $this->ledger->balance($locked->company_id, $from['account'], $from['branch'] ?? null, $from['bank'] ?? null);
            if ($available < $amount + $charge) {
                $label = isset($from['bank']) ? $bank->name : $from['account']->label();
                throw ValidationException::withMessages(['amount' => 'Insufficient balance in '.$label]);
            }

            $entry = $this->ledger->transfer($locked->company_id, $from, $to, $amount, $description, $locked, $charge);

            $locked->update([
                'status' => self::APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'transfer_date' => today(),
                'journal_entry_id' => $entry->id,
            ]);

            return $locked;
        });
    }

    /**
     * Reject a pending movement: nothing was posted, the row is kept with the reason.
     */
    public function reject(BankTransfer $transfer, string $reason, Employee $employee): BankTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $employee): BankTransfer {
            $locked = BankTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['reason' => 'Only pending transfers can be rejected']);
            }
            if (in_array($locked->type, [self::RESERVE_TO_INVESTMENT, self::RESERVE_TO_PRINCIPAL], true)) {
                $this->assertCanDecideReserve($employee);
            }

            $locked->update(['status' => self::REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $employee->id,
                'action' => 'BankTransfer.rejected',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => ['status' => self::PENDING],
                'after' => ['status' => self::REJECTED],
                'context' => ['reason' => $reason, 'amount' => (float) $locked->amount, 'type' => $locked->type],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * Post an approved HQ reserve → Investment RESERVE A/C transfer: Dr INVESTMENT RESERVE A/C / Cr the reserve accounts that
     * hold the HQ reserve, taken from each in proportion to its balance (the per-branch reserve is only a report). Must be
     * called inside {@see approve()}'s transaction with the company row locked.
     */
    private function postReserveToInvestment(BankTransfer $transfer, Employee $approver): BankTransfer
    {
        return $this->postFromPool($transfer, $approver, ['account' => Account::InvestmentReserve], $this->assertReserveCovers((int) $transfer->company_id, (float) $transfer->amount), 'HQ RESERVE TO INVESTMENT RESERVE A/C');
    }

    /**
     * Post an approved transfer out of an HQ pool held across branch accounts: Dr the receiving account / Cr each holding in
     * proportion to its balance (the per-branch figure is only a report of what that branch generated). Must be called inside
     * {@see approve()}'s transaction with the company row locked.
     *
     * @param  array{account: Account, branch?: int|null}  $destination
     * @param  list<array{account: Account, branch: int|null, balance: float}>  $holdings
     */
    private function postFromPool(BankTransfer $transfer, Employee $approver, array $destination, array $holdings, string $description): BankTransfer
    {
        $amount = (float) $transfer->amount;
        $total = round(array_sum(array_column($holdings, 'balance')), 2);

        $credits = array_map(fn (array $holding): float => min($holding['balance'], floor($amount * $holding['balance'] / $total * 100) / 100), $holdings);
        $remainder = round($amount - array_sum($credits), 2);
        foreach ($holdings as $index => $holding) {
            $extra = min($remainder, round($holding['balance'] - $credits[$index], 2));
            $credits[$index] = round($credits[$index] + $extra, 2);
            $remainder = round($remainder - $extra, 2);
        }

        $lines = [$destination + ['debit' => $amount]];
        foreach ($holdings as $index => $holding) {
            $lines[] = ['account' => $holding['account'], 'branch' => $holding['branch'], 'credit' => $credits[$index]];
        }

        $entry = $this->ledger->journal($transfer->company_id, $description, $lines, $transfer);

        $transfer->update([
            'status' => self::APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'transfer_date' => today(),
            'journal_entry_id' => $entry->id,
        ]);

        return $transfer;
    }

    /**
     * @throws AccessDeniedHttpException
     */
    private function assertCanDecideReserve(Employee $employee): void
    {
        if (! self::canDecideReserve($employee)) {
            throw new AccessDeniedHttpException(self::RESERVE_APPROVER_MESSAGE);
        }
    }

    /**
     * @return list<array{account: Account, branch: int|null, balance: float}>
     *
     * @throws ValidationException when the HQ reserve is smaller than the amount
     */
    private function assertReserveCovers(int $companyId, float $amount): array
    {
        return $this->assertPoolCovers($this->cash->hqReserveHoldings($companyId), $amount, 'HQ reserve');
    }

    /**
     * @return list<array{account: Account, branch: int|null, balance: float}>
     *
     * @throws ValidationException when the HQ interest is smaller than the amount
     */
    private function assertInterestCovers(int $companyId, float $amount): array
    {
        return $this->assertPoolCovers($this->cash->hqInterestHoldings($companyId), $amount, 'HQ interest income');
    }

    /**
     * @param  list<array{account: Account, branch: int|null, balance: float}>  $holdings
     * @return list<array{account: Account, branch: int|null, balance: float}>
     */
    private function assertPoolCovers(array $holdings, float $amount, string $pool): array
    {
        if (round(array_sum(array_column($holdings, 'balance')), 2) < $amount) {
            throw ValidationException::withMessages(['amount' => 'Insufficient balance in '.$pool]);
        }

        return $holdings;
    }

    private function ensureAmounts(float $amount, float $charge): void
    {
        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero']);
        }
        if (round($charge, 2) < 0) {
            throw ValidationException::withMessages(['charger_fee' => 'The charge cannot be negative']);
        }
    }

    private function replay(int $companyId, string $type, int $bankAccountId, float $amount, ?string $idempotencyKey): ?BankTransfer
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $previous = BankTransfer::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        if ((int) $previous->company_id !== $companyId || $previous->type !== $type || (int) $previous->bank_account_id !== $bankAccountId || abs((float) $previous->amount - $amount) > 0.001) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different transfer']);
        }

        return $previous;
    }
}
