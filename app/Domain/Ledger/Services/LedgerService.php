<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Exceptions\UnbalancedEntryException;
use App\Enums\ActiveStatus;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE posting engine — spec §5 and §8.
 *
 * §5: "Enforced in a single `LedgerService::post()` method that is the **only**
 * code path allowed to write to these two tables — no controller or model ever
 * inserts directly." Every financial event in the system, in this phase and
 * every later one, comes through here.
 *
 * What it guarantees on every call:
 *
 *   1. Debits equal credits — EXACTLY, in integer minor units, not within a
 *      tolerance. A ledger that balances "to within a cent" does not balance.
 *   2. At least two lines. A single-sided entry is not double-entry.
 *   3. Every account exists and is active. Posting to a deactivated account
 *      would hide the money somewhere no report looks.
 *   4. The entry is written with its lines and the balance cache is updated in
 *      one transaction, so a committed entry always has committed lines.
 *
 * What it does NOT do: update, delete, or accept an amendment. §2 makes
 * deletion architecturally impossible and §8 has the models throw on mutation.
 * The only way to undo a posting is a reversal, which is itself a new entry.
 */
final class LedgerService
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * Posts a balanced journal entry.
     *
     * @param list<JournalLine> $lines
     *
     * @throws UnbalancedEntryException
     */
    public function post(
        string $description,
        JournalSourceType $sourceType,
        ?int $sourceId,
        array $lines,
        User $postedBy,
        ?CarbonImmutable $entryDate = null,
    ): JournalEntry {
        $this->assertBalanced($lines, $description);
        $this->assertAccountsPostable($lines, $description);

        $date = $entryDate ?? Date::now()->toImmutable();

        return DB::transaction(function () use ($description, $sourceType, $sourceId, $lines, $postedBy, $date): JournalEntry {
            $entry = JournalEntry::query()->create([
                'entry_number' => $this->nextEntryNumber(),
                'entry_date' => $date->toDateString(),
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'is_reversal' => false,
                'created_by' => $postedBy->getKey(),
                'posted_at' => $date,
            ]);

            $this->writeLines($entry, $lines);

            return $entry->load('lines');
        });
    }

    /**
     * Posts the mirror image of an existing entry — §5's reversal rule.
     *
     * "It creates a **new** journal_entries row with is_reversal=true,
     * reversed_entry_id pointing at the original, and lines with debit/credit
     * swapped. The original entry's lines are untouched — this is what makes
     * the ledger auditable end-to-end."
     *
     * The dimensions (branch, customer, loan, staff) are carried across
     * unchanged, so the reversal lands in exactly the same sub-ledgers the
     * original did and nets it to zero there too.
     */
    public function reverse(JournalEntry $original, string $reason, User $postedBy): JournalEntry
    {
        $original->loadMissing('lines');

        $mirrored = $original->lines
            ->map(fn ($line): JournalLine => $line->debitAmount()->isPositive()
                ? JournalLine::credit(
                    $line->account_id,
                    $line->debitAmount(),
                    $line->branch_id,
                    $line->customer_id,
                    $line->loan_id,
                    $line->staff_profile_id,
                )
                : JournalLine::debit(
                    $line->account_id,
                    $line->creditAmount(),
                    $line->branch_id,
                    $line->customer_id,
                    $line->loan_id,
                    $line->staff_profile_id,
                ))
            ->all();

        $this->assertBalanced($mirrored, 'reversal of '.$original->entry_number);

        return DB::transaction(function () use ($original, $mirrored, $reason, $postedBy): JournalEntry {
            $entry = JournalEntry::query()->create([
                'entry_number' => $this->nextEntryNumber(),
                'entry_date' => Date::now()->toDateString(),
                'description' => sprintf('Reversal of %s — %s', $original->entry_number, $reason),
                'source_type' => JournalSourceType::Reversal,
                'source_id' => $original->getKey(),
                'is_reversal' => true,
                'reversed_entry_id' => $original->getKey(),
                'created_by' => $postedBy->getKey(),
                'posted_at' => Date::now(),
            ]);

            $this->writeLines($entry, $mirrored);

            return $entry->load('lines');
        });
    }

    /**
     * §5's double-entry rule, checked exactly.
     *
     * @param list<JournalLine> $lines
     *
     * @throws UnbalancedEntryException
     */
    public function assertBalanced(array $lines, string $context): void
    {
        if (count($lines) < 2) {
            Log::channel('operations')->critical('Ledger posting rejected: too few lines', [
                'context' => $context,
                'lines' => count($lines),
            ]);

            Log::channel('operations')->critical('Ledger posting rejected: too few lines', [
                'context' => $context,
                'lines' => count($lines),
            ]);

            throw UnbalancedEntryException::tooFewLines($context, count($lines));
        }

        $debits = Money::sum(array_map(static fn (JournalLine $l): Money => $l->debit, $lines));
        $credits = Money::sum(array_map(static fn (JournalLine $l): Money => $l->credit, $lines));

        // Exact equality, not a tolerance: with integer minor units there is
        // no rounding noise to forgive, so any difference is a real error.
        if (! $debits->equals($credits)) {
            Log::channel('operations')->critical('Ledger posting rejected: entry does not balance', [
                'context' => $context,
                'debits' => $debits->toDecimalString(),
                'credits' => $credits->toDecimalString(),
                'difference' => $debits->subtract($credits)->toDecimalString(),
            ]);

            throw UnbalancedEntryException::mismatch($context, $debits, $credits);
        }
    }

    /**
     * @param list<JournalLine> $lines
     */
    private function assertAccountsPostable(array $lines, string $context): void
    {
        $accountIds = array_values(array_unique(array_map(
            static fn (JournalLine $l): int => $l->accountId,
            $lines,
        )));

        $postable = ChartOfAccount::query()
            ->whereIn('id', $accountIds)
            ->where('status', ActiveStatus::Active)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($accountIds, $postable));

        if ($missing !== []) {
            Log::channel('operations')->critical('Ledger posting rejected: missing or inactive accounts', [
                'context' => $context,
                'account_ids' => $missing,
            ]);

            throw UnbalancedEntryException::unpostableAccounts($context, $missing);
        }
    }

    /**
     * Writes the lines and refreshes the affected balance rows.
     *
     * @param list<JournalLine> $lines
     */
    private function writeLines(JournalEntry $entry, array $lines): void
    {
        foreach ($lines as $line) {
            $entry->lines()->create($line->toDatabaseRow($entry->getKey()));
        }

        $this->accounts->refreshBalances(array_values(array_unique(array_map(
            static fn (JournalLine $l): int => $l->accountId,
            $lines,
        ))));
    }

    /**
     * Mirrors the frontend's `journalEntryNumber`: JE-0000001.
     *
     * Derived from the highest existing number rather than a row count, for
     * the same reason as the customer and loan generators.
     */
    private function nextEntryNumber(): string
    {
        $highest = (int) DB::table('journal_entries')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'JE-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
