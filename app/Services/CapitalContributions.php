<?php

namespace App\Services;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Assets\AssetRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records shareholder capital contributions (Capital → Add Capitals, and paid share issuances from Shares → Issue
 * Shares, which link the share transaction to the contribution this service records).
 *
 * Each contribution is its own `capitals` row and one balanced journal entry:
 *   Dr the company account that received the money — COMPANY ACCOUNT for CASH, the selected bank account for BANK —
 *   Cr CAPITAL ACCOUNT (equity; never revenue).
 * Row and entry are written in one database transaction. A request carrying an idempotency key that was already
 * recorded returns the original contribution instead of posting again. A wrongly recorded CASH / BANK contribution is
 * corrected by {@see self::reverse()} (asset contributions are reversed from the asset register).
 *
 * Rule 6 (segregation of duties): Capital → Add Capitals records a CASH / BANK contribution as PENDING
 * ({@see self::requestContribution()}: row only, no journal, not counted in ownership, contribution totals or dividends);
 * a different authorised user approves it ({@see self::approve()}), which posts the journal, or rejects it.
 * {@see self::contribute()} still posts immediately for internal callers (the approval of a paid share issuance request).
 *
 * C6: an asset contribution is also recorded PENDING ({@see self::contributeAsset()}); it is approved or rejected from the
 * asset register ({@see AssetRegistry::approve()}), which passes the step that activates the asset.
 */
class CapitalContributions
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly TransferReversal $reversals,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * Record a CASH / BANK contribution as PENDING approval: the row (with receipt) only — no journal, no balance change.
     * A repeated idempotency key returns the original row.
     *
     * @param  Closure(Capital): void|null  $afterCreate  runs inside the transaction for a new contribution (e.g. storing the receipt file)
     * @return array{capital: Capital, created: bool}
     */
    public function requestContribution(
        ShareHolder $holder,
        float $amount,
        string $payMethod,
        ?int $bankAccountId,
        Employee $recordedBy,
        ?string $receiptNumber = null,
        ?string $chequeNumber = null,
        ?CarbonImmutable $contributedAt = null,
        ?string $idempotencyKey = null,
        ?Closure $afterCreate = null,
        ?string $source = null,
    ): array {
        $amount = round($amount, 2);
        $contributedAt ??= CarbonImmutable::now();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero']);
        }

        $previous = $this->replay($holder, $amount, $idempotencyKey);
        if ($previous !== null) {
            return ['capital' => $previous, 'created' => false];
        }

        $receiving = $this->receivingAccount((int) $holder->company_id, $payMethod, $bankAccountId);

        return $this->createPending($holder, $amount, $payMethod, $receiving, $recordedBy, $receiptNumber, $chequeNumber, $contributedAt, $idempotencyKey, $afterCreate, $source);
    }

    /**
     * @param  array{account: Account, bank?: int}  $receiving
     * @param  Closure(Capital): void|null  $afterCreate
     * @return array{capital: Capital, created: bool}
     */
    private function createPending(
        ShareHolder $holder,
        float $amount,
        string $payMethod,
        array $receiving,
        Employee $recordedBy,
        ?string $receiptNumber,
        ?string $chequeNumber,
        CarbonImmutable $contributedAt,
        ?string $idempotencyKey,
        ?Closure $afterCreate,
        ?string $source,
    ): array {
        try {
            $capital = DB::transaction(function () use ($holder, $amount, $payMethod, $receiving, $recordedBy, $receiptNumber, $chequeNumber, $contributedAt, $idempotencyKey, $afterCreate, $source): Capital {
                $capital = Capital::create([
                    'company_id' => $holder->company_id,
                    'share_holder_id' => $holder->id,
                    'amount' => $amount,
                    'pay_method' => $payMethod,
                    'receiving_account' => $receiving['account']->value,
                    'bank_account_id' => $receiving['bank'] ?? null,
                    'receipt_number' => $receiptNumber,
                    'cheque_number' => $chequeNumber,
                    'recorded_by' => $recordedBy->id,
                    'contributed_at' => $contributedAt,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Capital::STATUS_PENDING,
                    'source' => $source,
                ]);

                if ($afterCreate !== null) {
                    $afterCreate($capital);
                }

                return $capital;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replay($holder, $amount, $idempotencyKey);
            if ($previous === null) {
                throw $exception;
            }

            return ['capital' => $previous, 'created' => false];
        }

        return ['capital' => $capital, 'created' => true];
    }

    /**
     * Approve a pending contribution and post it: Dr COMPANY ACCOUNT / bank, Cr CAPITAL ACCOUNT, dated the contribution
     * date. The employee who recorded it cannot approve it unless self-approval is explicitly granted.
     *
     * An ASSET contribution posts Dr its fixed-asset account / Cr CAPITAL ACCOUNT dated the approval date, and only through
     * the asset register ({@see AssetRegistry::approve()}), which passes `$afterPost` (activates the asset in the same
     * transaction) and the journal description.
     *
     * @param  Closure(Capital): void|null  $afterPost
     *
     * @throws ValidationException
     */
    public function approve(Capital $capital, Employee $approver, ?Closure $afterPost = null, ?string $description = null): Capital
    {
        return DB::transaction(function () use ($capital, $approver, $afterPost, $description): Capital {
            Company::whereKey($capital->company_id)->lockForUpdate()->firstOrFail();
            $locked = Capital::whereKey($capital->id)->lockForUpdate()->with('shareHolder')->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['capital' => 'This capital contribution is not pending approval.']);
            }
            $isAsset = $locked->pay_method === 'ASSET';
            if ($isAsset && $afterPost === null) {
                throw ValidationException::withMessages(['capital' => 'Approve asset contributions from the asset register.']);
            }
            $this->duties->assertCanApprove($this->initiatorIds($locked), $approver, $isAsset ? 'asset contribution' : 'capital contribution', workflow: $isAsset ? ApprovalPolicy::ASSET_CONTRIBUTIONS : ApprovalPolicy::CAPITAL_CONTRIBUTIONS);

            $receiving = $isAsset ? $this->assetReceivingAccount($locked) : $this->receivingAccount((int) $locked->company_id, (string) $locked->pay_method, $locked->bank_account_id);
            $postedAt = $isAsset ? CarbonImmutable::now() : CarbonImmutable::parse($locked->contributed_at ?? $locked->created_at);
            $amount = (float) $locked->amount;

            $entry = $this->ledger->journal($locked->company_id, $description ?? 'CAPITAL CONTRIBUTION - '.$locked->shareHolder?->full_name, [
                $receiving + ['debit' => $amount],
                ['account' => Account::Capital, 'credit' => $amount],
            ], $locked, $postedAt, employee: $approver);

            $locked->update([
                'status' => Capital::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            if ($afterPost !== null) {
                $afterPost($locked);
            }

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $approver->id,
                'action' => 'Capital.approved',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => ['status' => Capital::STATUS_PENDING],
                'after' => ['status' => Capital::STATUS_POSTED, 'journal_entry_id' => $entry->id],
                'context' => ['amount' => $amount, 'share_holder_id' => $locked->share_holder_id, 'source' => $locked->source, 'journal_reference' => $entry->reference],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * Employees who may not approve this contribution (rule 6): the employee who recorded it and the login account of the
     * shareholder it belongs to — nobody approves capital added to their own shareholding, even through a linked staff
     * account holding capital.manage, unless self-approval is explicitly granted.
     *
     * @return list<int|null>
     */
    public function initiatorIds(Capital $capital): array
    {
        $holderAccountId = $capital->relationLoaded('shareHolder')
            ? $capital->shareHolder?->employee_id
            : ShareHolder::whereKey($capital->share_holder_id)->value('employee_id');

        return [$capital->recorded_by, $holderAccountId];
    }

    /**
     * The shareholder who submitted a pending contribution from the Shareholder Portal withdraws it: nothing was posted;
     * the row is kept with status cancelled (never counted) and the cancellation is audit-logged.
     *
     * @throws ValidationException
     */
    public function cancel(Capital $capital, Employee $employee): Capital
    {
        return DB::transaction(function () use ($capital, $employee): Capital {
            $locked = Capital::whereKey($capital->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['capital' => 'Only pending capital contributions can be cancelled.']);
            }

            $locked->update(['status' => Capital::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $employee->id]);

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $employee->id,
                'action' => 'Capital.cancelled_by_shareholder',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => ['status' => Capital::STATUS_PENDING],
                'after' => ['status' => Capital::STATUS_CANCELLED],
                'context' => ['amount' => (float) $locked->amount, 'share_holder_id' => $locked->share_holder_id],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * Reject a pending contribution: nothing was posted; the row is kept (status rejected) with the reason.
     */
    public function reject(Capital $capital, string $reason, Employee $employee, ?Closure $afterReject = null): Capital
    {
        return DB::transaction(function () use ($capital, $reason, $employee, $afterReject): Capital {
            $locked = Capital::whereKey($capital->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['reason' => 'Only pending capital contributions can be rejected.']);
            }
            if ($locked->pay_method === 'ASSET' && $afterReject === null) {
                throw ValidationException::withMessages(['reason' => 'Reject asset contributions from the asset register.']);
            }

            $locked->update(['status' => Capital::STATUS_REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            if ($afterReject !== null) {
                $afterReject($locked);
            }

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $employee->id,
                'action' => 'Capital.rejected',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => ['status' => Capital::STATUS_PENDING],
                'after' => ['status' => Capital::STATUS_REJECTED],
                'context' => ['reason' => $reason, 'amount' => (float) $locked->amount, 'share_holder_id' => $locked->share_holder_id],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * @param  Closure(Capital): void|null  $afterCreate  runs inside the transaction for a new contribution (e.g. storing the receipt file)
     * @param  string|null  $description  journal description (defaults to "CAPITAL CONTRIBUTION - <shareholder>")
     * @return array{capital: Capital, created: bool}
     */
    public function contribute(
        ShareHolder $holder,
        float $amount,
        string $payMethod,
        ?int $bankAccountId,
        Employee $recordedBy,
        ?string $receiptNumber = null,
        ?string $chequeNumber = null,
        ?CarbonImmutable $contributedAt = null,
        ?string $idempotencyKey = null,
        ?Closure $afterCreate = null,
        ?string $description = null,
    ): array {
        $amount = round($amount, 2);
        $contributedAt ??= CarbonImmutable::now();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero']);
        }

        $previous = $this->replay($holder, $amount, $idempotencyKey);
        if ($previous !== null) {
            return ['capital' => $previous, 'created' => false];
        }

        $receiving = $this->receivingAccount((int) $holder->company_id, $payMethod, $bankAccountId);

        return $this->record($holder, $amount, $payMethod, $receiving, $recordedBy, $receiptNumber, $chequeNumber, $contributedAt, $idempotencyKey, $afterCreate, $description);
    }

    /**
     * An asset contributed as capital, recorded PENDING approval (C6): one `capitals` row (pay method ASSET, receiving account =
     * the fixed-asset account, status pending) and no journal. The asset row itself is written by `$afterCreate` inside the same
     * transaction. On approval ({@see self::approve()}) one balanced journal Dr the fixed-asset account / Cr CAPITAL ACCOUNT is
     * posted — cash and bank never move.
     *
     * @param  Closure(Capital): void  $afterCreate
     * @return array{capital: Capital, created: bool}
     */
    public function contributeAsset(
        ShareHolder $holder,
        float $amount,
        Account $assetAccount,
        Employee $recordedBy,
        CarbonImmutable $contributedAt,
        ?string $idempotencyKey,
        Closure $afterCreate,
    ): array {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['unit_value' => 'The contribution value must be greater than zero']);
        }
        if (! in_array($assetAccount, Account::fixedAssets(), true)) {
            throw ValidationException::withMessages(['asset_type' => 'An asset contribution must be posted to a fixed-asset account']);
        }

        $previous = $this->replay($holder, $amount, $idempotencyKey);
        if ($previous !== null) {
            return ['capital' => $previous, 'created' => false];
        }

        return $this->createPending($holder, $amount, 'ASSET', ['account' => $assetAccount], $recordedBy, null, null, $contributedAt, $idempotencyKey, $afterCreate, null);
    }

    /**
     * The fixed-asset account an ASSET contribution posts to.
     *
     * @return array{account: Account}
     *
     * @throws ValidationException
     */
    private function assetReceivingAccount(Capital $capital): array
    {
        $account = Account::tryFrom((string) $capital->receiving_account);
        if ($account === null || ! in_array($account, Account::fixedAssets(), true)) {
            throw ValidationException::withMessages(['capital' => 'An asset contribution must be posted to a fixed-asset account']);
        }

        return ['account' => $account];
    }

    /**
     * @param  array{account: Account, bank?: int}  $receiving
     * @return array{capital: Capital, created: bool}
     */
    private function record(
        ShareHolder $holder,
        float $amount,
        string $payMethod,
        array $receiving,
        Employee $recordedBy,
        ?string $receiptNumber,
        ?string $chequeNumber,
        CarbonImmutable $contributedAt,
        ?string $idempotencyKey,
        ?Closure $afterCreate,
        ?string $description,
    ): array {
        try {
            $capital = DB::transaction(function () use ($holder, $amount, $payMethod, $receiving, $recordedBy, $receiptNumber, $chequeNumber, $contributedAt, $idempotencyKey, $afterCreate, $description): Capital {
                $capital = Capital::create([
                    'company_id' => $holder->company_id,
                    'share_holder_id' => $holder->id,
                    'amount' => $amount,
                    'pay_method' => $payMethod,
                    'receiving_account' => $receiving['account']->value,
                    'bank_account_id' => $receiving['bank'] ?? null,
                    'receipt_number' => $receiptNumber,
                    'cheque_number' => $chequeNumber,
                    'recorded_by' => $recordedBy->id,
                    'contributed_at' => $contributedAt,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Capital::STATUS_POSTED,
                ]);

                $entry = $this->ledger->journal($holder->company_id, $description ?? 'CAPITAL CONTRIBUTION - '.$holder->full_name, [
                    $receiving + ['debit' => $amount],
                    ['account' => Account::Capital, 'credit' => $amount],
                ], $capital, $contributedAt, employee: $recordedBy);

                $capital->update(['journal_entry_id' => $entry->id]);

                if ($afterCreate !== null) {
                    $afterCreate($capital);
                }

                return $capital;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replay($holder, $amount, $idempotencyKey);
            if ($previous === null) {
                throw $exception;
            }

            return ['capital' => $previous, 'created' => false];
        }

        return ['capital' => $capital, 'created' => true];
    }

    /**
     * Reverse a wrongly recorded CASH or BANK contribution (spec §21, §26 Option A, §29): {@see Ledger::reverse()} posts
     * Dr CAPITAL ACCOUNT / Cr the receiving account (COMPANY ACCOUNT or that bank account) today; the original row stays,
     * marked reversed with the reversal entry, operator, time and reason, and the reversal is audit-logged. Company and
     * contribution rows are locked and every block is re-checked inside the transaction; blocked reversals post nothing.
     *
     * @throws ValidationException
     */
    public function reverse(Capital $capital, string $reason, Employee $employee): Capital
    {
        return DB::transaction(function () use ($capital, $reason, $employee): Capital {
            Company::whereKey($capital->company_id)->lockForUpdate()->firstOrFail();
            $locked = Capital::whereKey($capital->id)->lockForUpdate()->firstOrFail();

            $entry = $this->postedEntry($locked);
            $blocked = $this->reverseBlockedReason($locked, $entry);
            if ($blocked !== null) {
                throw ValidationException::withMessages(['reason' => $blocked]);
            }
            $this->duties->assertCanReverse($entry, $employee);

            $reversal = $this->ledger->reverse($entry, $reason);
            $locked->update([
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_journal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
            ]);

            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $employee->id,
                'action' => 'Capital.reversed',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => ['reversed_at' => null, 'journal_entry_id' => $entry->id],
                'after' => ['reversed_at' => $locked->reversed_at?->toDateTimeString(), 'reversal_journal_entry_id' => $reversal->id],
                'context' => [
                    'reason' => $reason,
                    'amount' => (float) $locked->amount,
                    'pay_method' => $locked->pay_method,
                    'receiving_account' => $locked->receiving_account,
                    'bank_account_id' => $locked->bank_account_id,
                    'share_holder_id' => $locked->share_holder_id,
                    'journal_reference' => $entry->reference,
                    'reversal_reference' => $reversal->reference,
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $locked;
        });
    }

    /**
     * Why this contribution cannot be reversed now, or null when it can. Read-only (list flags); {@see reverse()} runs it
     * again with the rows locked.
     */
    public function reverseBlockedReason(Capital $capital, ?JournalEntry $entry = null): ?string
    {
        if ($capital->isReversed()) {
            return 'This capital contribution has already been reversed.';
        }
        if (! $capital->isPosted()) {
            return 'Only posted (approved) capital contributions can be reversed.';
        }
        if ($capital->pay_method === 'ASSET') {
            return 'Reverse asset contributions from the asset register.';
        }

        $linked = ShareTransaction::where('capital_id', $capital->id)->where('status', ShareTransaction::COMPLETED)->value('reference');
        if ($linked !== null) {
            return "Shares were issued against this contribution ({$linked}); reverse that share transaction first.";
        }

        $entry ??= $this->postedEntry($capital);
        if ($entry === null) {
            return 'This capital contribution has no journal entry to reverse.';
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this capital contribution has already been reversed.";
        }

        return $this->reversals->receivingShortfall($entry);
    }

    /**
     * List flags for a contribution row: whether the signed-in user may reverse it now and, when a permitted user cannot,
     * why. Reversed rows and users without the permission carry no reason (the action is not offered).
     *
     * @return array{can_reverse: bool, reverse_blocked_reason: string|null}
     */
    public function reverseFlags(Capital $capital, bool $permitted): array
    {
        if ($capital->isReversed() || ! $capital->isPosted() || ! $permitted) {
            return ['can_reverse' => false, 'reverse_blocked_reason' => null];
        }

        $entry = $capital->relationLoaded('journalEntry') ? $capital->journalEntry : $this->postedEntry($capital);
        $reason = $this->reverseBlockedReason($capital, $entry);
        $viewer = auth()->user();
        if ($reason === null && $viewer instanceof Employee) {
            $reason = $this->duties->reverseBlockedReason($entry, $viewer);
        }

        return ['can_reverse' => $reason === null, 'reverse_blocked_reason' => $reason];
    }

    private function postedEntry(Capital $capital): ?JournalEntry
    {
        return $capital->journal_entry_id === null
            ? null
            : JournalEntry::with('lines.account.branch', 'lines.account.bankAccount')->where('company_id', $capital->company_id)->find($capital->journal_entry_id);
    }

    /**
     * COMPANY ACCOUNT for CASH; for BANK a bank account of the same company is required.
     *
     * @return array{account: Account, bank?: int}
     */
    public function receivingAccount(int $companyId, string $payMethod, ?int $bankAccountId): array
    {
        if ($payMethod !== 'BANK') {
            return ['account' => Account::Company];
        }

        $bank = $bankAccountId === null ? null : BankAccount::where('company_id', $companyId)->find($bankAccountId);
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Select the company bank account that received the money']);
        }

        return ['account' => Account::Bank, 'bank' => $bank->id];
    }

    /**
     * The contribution already recorded under this idempotency key, if any.
     */
    private function replay(ShareHolder $holder, float $amount, ?string $idempotencyKey): ?Capital
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $previous = Capital::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        if ((int) $previous->company_id !== (int) $holder->company_id || (int) $previous->share_holder_id !== (int) $holder->id || abs((float) $previous->amount - $amount) > 0.001) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different contribution']);
        }

        return $previous;
    }
}
