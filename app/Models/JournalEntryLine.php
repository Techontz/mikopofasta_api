<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.7 — `journal_entry_lines`. Immutable, like its parent.
 *
 * The four dimension columns are what make §2.7's derived sub-ledgers work:
 * a customer ledger, loan ledger, staff ledger and branch ledger are all just
 * this table filtered.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property string $debit_amount
 * @property string $credit_amount
 * @property int|null $branch_id
 * @property int|null $customer_id
 * @property int|null $loan_id
 * @property int|null $staff_profile_id
 */
class JournalEntryLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit_amount', 'credit_amount',
        'branch_id', 'customer_id', 'loan_id', 'staff_profile_id', 'created_at',
    ];

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function debitAmount(): Money
    {
        return Money::of($this->debit_amount);
    }

    public function creditAmount(): Money
    {
        return Money::of($this->credit_amount);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableRecordException::cannotUpdate(self::class);
    }

    public function delete(): bool
    {
        throw ImmutableRecordException::cannotDelete(self::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
