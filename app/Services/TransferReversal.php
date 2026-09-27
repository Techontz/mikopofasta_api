<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BankTransfer;
use App\Models\CommissionAllocation;
use App\Models\Company;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Models\HqTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reversal of posted internal transfers (Fund Flow Specification §19, §21, §26 Option A, §29, §31):
 * float company → branch, branch → branch, account → account; bank → branch, bank → HQ, branch → bank;
 * HQ ↔ HQ transactions; company cash ↔ bank.
 *
 * A reversal mirrors the original journal exactly ({@see Ledger::reverse()}, posted today — charges included), keeps the
 * original row with status "reversed" and links the reversal entry, operator, time and reason. It is blocked (never
 * partial) when the receiving account no longer holds the money, or when the entry touched profit (bank charges) in a
 * closed period whose profit was already distributed. The shared checks are also used by expense reversals.
 */
class TransferReversal
{
    public const STATUS_REVERSED = 'reversed';

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Reverse a posted transfer inside one transaction with the company and transfer rows locked.
     *
     * @template TTransfer of FloatTransfer|BankTransfer|HqTransaction
     *
     * @param  TTransfer  $transfer
     * @return TTransfer
     *
     * @throws ValidationException
     */
    public function reverse(FloatTransfer|BankTransfer|HqTransaction $transfer, string $reason, Employee $employee): FloatTransfer|BankTransfer|HqTransaction
    {
        return DB::transaction(function () use ($transfer, $reason, $employee): FloatTransfer|BankTransfer|HqTransaction {
            Company::whereKey($transfer->company_id)->lockForUpdate()->firstOrFail();
            /** @var FloatTransfer|BankTransfer|HqTransaction $locked */
            $locked = $transfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            $entry = $this->postedEntry($locked);
            $blocked = $this->blockedReason($locked, $entry);
            if ($blocked !== null) {
                throw ValidationException::withMessages(['reason' => $blocked]);
            }
            app(SegregationOfDuties::class)->assertCanReverse($entry, $employee);

            $reversal = $this->ledger->reverse($entry, $reason);
            $before = ['status' => $locked->status, 'journal_entry_id' => $locked->journal_entry_id];

            $locked->update([
                'status' => self::STATUS_REVERSED,
                'journal_entry_id' => $entry->id,
                'reversed_at' => now(),
                'reversed_by' => $employee->id,
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            $this->audit($locked, $employee, $before, $entry, $reversal, $reason);

            return $locked;
        });
    }

    /**
     * Why this transfer cannot be reversed now, or null when it can. Read-only (used for list flags and, with the rows
     * locked, before posting).
     */
    public function blockedReason(FloatTransfer|BankTransfer|HqTransaction $transfer, ?JournalEntry $entry = null): ?string
    {
        if ($transfer->status === self::STATUS_REVERSED) {
            return 'This transfer has already been reversed.';
        }
        if ($transfer->status !== 'approved') {
            return 'Only approved transfers can be reversed.';
        }

        $entry ??= $this->postedEntry($transfer);

        return $this->entryBlockedReason($entry, 'transfer');
    }

    /**
     * List flags for a transfer row: whether the signed-in user may reverse it now and, when a permitted user cannot,
     * why (including rule 6: the employee who posted the journal does not reverse it). Pending and reversed rows, and
     * users without the permission, carry no reason (the action is not offered).
     *
     * @return array{can_reverse: bool, reverse_blocked_reason: string|null}
     */
    public function flags(FloatTransfer|BankTransfer|HqTransaction $transfer, bool $permitted): array
    {
        if ($transfer->status !== 'approved' || ! $permitted) {
            return ['can_reverse' => false, 'reverse_blocked_reason' => null];
        }

        $entry = $this->postedEntry($transfer);
        $reason = $this->blockedReason($transfer, $entry);
        $viewer = auth()->user();
        if ($reason === null && $viewer instanceof Employee) {
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($entry, $viewer);
        }

        return ['can_reverse' => $reason === null, 'reverse_blocked_reason' => $reason];
    }

    /**
     * Shared checks for a posted journal: present and not yet reversed, receiving accounts still hold the money,
     * profit of a closed period not already distributed.
     */
    public function entryBlockedReason(?JournalEntry $entry, string $noun): ?string
    {
        if ($entry === null) {
            return "This {$noun} has no journal entry to reverse.";
        }
        if ($entry->reversal()->exists()) {
            return "The journal entry {$entry->reference} of this {$noun} has already been reversed.";
        }

        return $this->receivingShortfall($entry) ?? $this->distributedPeriodBlock($entry);
    }

    /**
     * The non-reversal journal entry a record posted: its journal_entry_id, else the entry whose source is the record.
     */
    public function postedEntry(Model $record): ?JournalEntry
    {
        $query = JournalEntry::query()->with('lines.account.branch', 'lines.account.bankAccount');

        if ($record->getAttribute('journal_entry_id') !== null) {
            return $query->find($record->getAttribute('journal_entry_id'));
        }

        return $query
            ->where('company_id', $record->getAttribute('company_id'))
            ->where('source_type', $record->getMorphClass())
            ->where('source_id', $record->getKey())
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * Every money (asset) account the original entry increased must still hold that increase, or the reversal would
     * drive it negative (spec §31) — the money has already been used.
     */
    public function receivingShortfall(JournalEntry $entry): ?string
    {
        foreach ($entry->lines->groupBy('account_id') as $accountId => $lines) {
            /** @var LedgerAccount|null $account */
            $account = $lines->first()->account;
            $increase = round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);

            if ($account === null || $account->key->type() !== 'asset' || $increase <= 0.005) {
                continue;
            }

            $balance = round((float) JournalLine::query()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')->value('balance'), 2);

            if ($balance + 0.005 < $increase) {
                return 'The receiving account ('.$this->accountName($account).') no longer holds TZS '.$this->tzs($increase).' (balance TZS '.$this->tzs($balance).'); the money has already been used.';
            }
        }

        return null;
    }

    /**
     * An entry that touched income or expense accounts, dated in a CLOSED period whose profit has already been
     * distributed (dividend declaration or commission allocation), cannot be reversed (spec §25, §36C).
     */
    public function distributedPeriodBlock(JournalEntry $entry): ?string
    {
        $period = $this->closedProfitPeriod($entry);
        if ($period === null) {
            return null;
        }

        $distributions = array_keys(array_filter([
            'dividend declaration' => DividendDeclaration::query()
                ->where('company_id', $entry->company_id)
                ->whereDate('period', '>=', $period->period_start->toDateString())
                ->whereDate('period', '<=', $period->period_end->toDateString())
                ->exists(),
            'commission allocation' => CommissionAllocation::query()->where('accounting_period_id', $period->id)->exists(),
        ]));

        if ($distributions === []) {
            return null;
        }

        return 'The profit of '.$period->period_start->format('F Y').' has already been distributed ('.implode(' and ', $distributions).'); this reversal would change a distributed period and is blocked.';
    }

    /**
     * Informational note when a profit-affecting entry of a closed (not distributed) period is reversed: the reversal
     * posts today, as an adjustment in the current period.
     */
    public function closedPeriodNotice(JournalEntry $entry): ?string
    {
        $period = $this->closedProfitPeriod($entry);

        return $period === null ? null : 'The period '.$period->period_start->format('F Y').' is closed; the reversal is recorded as an adjustment in the current period ('.now()->format('F Y').').';
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public function audit(Model $record, Employee $employee, array $before, JournalEntry $entry, JournalEntry $reversal, string $reason): void
    {
        AuditLog::create([
            'company_id' => $record->getAttribute('company_id'),
            'employee_id' => $employee->id,
            'action' => class_basename($record).'.reversed',
            'auditable_type' => $record->getMorphClass(),
            'auditable_id' => $record->getKey(),
            'before' => $before,
            'after' => ['status' => $record->getAttribute('status'), 'reversal_journal_entry_id' => $reversal->id],
            'context' => ['reason' => $reason, 'journal_reference' => $entry->reference, 'reversal_reference' => $reversal->reference, 'amount' => (float) $record->getAttribute('amount')],
            'ip_address' => request()?->ip(),
        ]);
    }

    private function closedProfitPeriod(JournalEntry $entry): ?AccountingPeriod
    {
        $touchesProfit = $entry->lines->contains(fn (JournalLine $line): bool => in_array($line->account?->key->type(), ['income', 'expense'], true));
        if (! $touchesProfit) {
            return null;
        }

        return AccountingPeriod::query()
            ->where('company_id', $entry->company_id)
            ->closed()
            ->whereDate('period_start', '<=', $entry->entry_date->toDateString())
            ->whereDate('period_end', '>=', $entry->entry_date->toDateString())
            ->first();
    }

    private function accountName(LedgerAccount $account): string
    {
        $scope = $account->branch?->name ?? $account->bankAccount?->name;

        return $account->key->label().($scope ? ' - '.$scope : '');
    }

    private function tzs(float $amount): string
    {
        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
