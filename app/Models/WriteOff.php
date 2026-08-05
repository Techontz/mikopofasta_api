<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loan the business has stopped expecting to collect — §5's Write-Off account.
 *
 * The split between what is written off and what is merely forgone matters.
 * Only principal reaches the ledger: under the collection basis this system
 * uses, uncollected interest and penalty were never recognised as income, so
 * writing them off would reverse revenue that does not exist. They are kept
 * here because the recovery officer and the arrears report both need to know
 * what the borrower actually owed.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $principal_written_off
 * @property string $interest_forgone
 * @property string $penalty_forgone
 * @property string $reason
 * @property int $approved_by
 * @property int|null $journal_entry_id
 */
class WriteOff extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'principal_written_off', 'interest_forgone', 'penalty_forgone',
        'reason', 'approved_by', 'journal_entry_id',
    ];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Recovery, $this> */
    public function recoveries(): HasMany
    {
        return $this->hasMany(Recovery::class);
    }

    public function principalMoney(): Money
    {
        return Money::of($this->principal_written_off);
    }

    /** What has come back since, across every recovery against this write-off. */
    public function recoveredTotal(): Money
    {
        return Money::sum(
            $this->recoveries()->pluck('amount')->map(static fn ($a): Money => Money::of((string) $a)),
        );
    }

    /**
     * What remains unrecovered.
     *
     * Floored at zero: a recovery may exceed the principal written off when it
     * carries interest the borrower agreed to pay on settlement, and a negative
     * outstanding would read as the company owing the borrower.
     */
    public function outstanding(): Money
    {
        return $this->principalMoney()->subtract($this->recoveredTotal())->max(Money::zero());
    }
}
