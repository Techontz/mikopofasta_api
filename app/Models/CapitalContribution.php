<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\PayMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Money a shareholder put into the company — Capital → Add Capitals.
 *
 * Every row has a posted journal entry behind it: the contribution and its
 * ledger effect are created in one transaction.
 *
 * @property int $id
 * @property string $reference
 * @property int $shareholder_id
 * @property string $amount
 * @property PayMethod $pay_method
 * @property string|null $receipt_no
 * @property string|null $cheque_no
 * @property int|null $bank_account_id
 * @property int|null $received_account_id
 * @property string|null $source_account_name
 * @property string|null $source_account_number
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 */
class CapitalContribution extends Model
{
    use SoftDeletes;

    /** The prefix of a system-allocated reference: CAP-0000001. */
    public const string REFERENCE_PREFIX = 'CAP-';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'shareholder_id', 'amount', 'pay_method', 'receipt_no', 'cheque_no',
        'bank_account_id', 'received_account_id', 'source_account_name', 'source_account_number',
        'journal_entry_id', 'created_by',
    ];

    /** @return BelongsTo<Shareholder, $this> */
    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The registered company account it was paid into, when not cash.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * The ledger account debited — the company account affected.
     *
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function receivedAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'received_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every contribution has a unique reference, however it was created.
     *
     * One the officer supplies (a bank or mobile-money transaction number) is
     * kept. Otherwise a unique placeholder satisfies the NOT NULL UNIQUE column
     * for the instant of the insert, and is replaced with CAP-{id} as soon as
     * the id exists — inside the same transaction as the insert.
     */
    protected static function booted(): void
    {
        static::creating(function (self $contribution): void {
            if (blank($contribution->reference)) {
                $contribution->reference = self::REFERENCE_PREFIX.'TMP-'.Str::uuid()->toString();
            }
        });

        static::created(function (self $contribution): void {
            if (str_starts_with($contribution->reference, self::REFERENCE_PREFIX.'TMP-')) {
                $contribution->reference = self::REFERENCE_PREFIX.str_pad((string) $contribution->id, 7, '0', STR_PAD_LEFT);
                $contribution->saveQuietly();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pay_method' => PayMethod::class, 'amount' => 'decimal:2'];
    }
}
