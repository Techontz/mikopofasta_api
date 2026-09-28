<?php

namespace App\Services\Accounting;

use App\Http\Resources\Api\V1\Accounting\JournalEntryResource;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Validation\ValidationException;

/**
 * Rules for the generic Accounting → Journal "Reverse" action (Fund Flow Specification §21, §26 Option A, Rules 16–17).
 *
 * The generic reversal only posts the opposite journal; it cannot restore operational state (loan schedules, payment
 * status, payroll, dividends, capital records…). It is therefore limited to MANUAL entries (no source record). Entries
 * posted by a module must be reversed from that module, which reverses the journal and its dependent records together.
 */
class JournalReversalGuard
{
    /**
     * Why the generic reversal is not allowed for this entry, or null when it is.
     */
    public function blockedReason(JournalEntry $entry): ?string
    {
        if ($entry->reversal_of_id !== null) {
            return 'A reversal entry cannot itself be reversed.';
        }

        $alreadyReversed = $entry->relationLoaded('reversal') ? $entry->reversal !== null : $entry->reversal()->exists();
        if ($alreadyReversed) {
            return "Journal entry {$entry->reference} has already been reversed.";
        }

        if ($entry->transaction_type?->isPeriodClosing() || $entry->source_type === (new AccountingPeriod)->getMorphClass()) {
            return 'Month-end closing entries cannot be reversed: reopening a closed accounting period is not supported.';
        }

        if ($entry->source_type !== null) {
            return 'This entry was posted by '.JournalEntryResource::sourceLabel($entry->source_type).'. Reverse it from that module so its dependent records stay consistent.';
        }

        return null;
    }

    /**
     * Full check before posting: the entry must be reversible and every money account the original entry debited must
     * still hold that money (a reversal may not drive a fund account negative — spec §31).
     *
     * @throws ValidationException
     */
    public function assertReversible(JournalEntry $entry): void
    {
        $reason = $this->blockedReason($entry);
        if ($reason !== null) {
            throw ValidationException::withMessages(['reason' => $reason]);
        }

        $debits = $entry->lines()->with('account')->where('debit', '>', 0)->get()
            ->filter(fn (JournalLine $line): bool => $line->account !== null && in_array($line->account->key, LedgerIntegrity::nonNegativeFunds(), true));

        foreach ($debits->groupBy('account_id') as $accountId => $lines) {
            $needed = round((float) $lines->sum('debit'), 2);
            $account = $lines->first()->account;
            $available = round((float) JournalLine::query()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')->value('balance'), 2);

            if ($available + 0.005 < $needed) {
                throw ValidationException::withMessages([
                    'reason' => "Cannot reverse: {$account->name} holds ".number_format($available, 2).' but the reversal needs '.number_format($needed, 2).'. The money has already been used.',
                ]);
            }
        }
    }
}
