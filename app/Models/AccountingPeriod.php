<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One `YYYY-MM` accounting period — Decision Register D1.
 *
 * The client's rule is that profit is measured by date and "mwezi unapokuja
 * inaanza upya" — a new month starts afresh. This row is what makes that true
 * of the books rather than only of the reports: closing a period recognises its
 * profit and appropriates its reserve, and neither may happen twice.
 *
 * A period only exists once someone has closed it. There is no queue of empty
 * future rows, so `exists` and `closed` are the same question.
 *
 * @property int $id
 * @property string $period
 * @property PeriodStatus $status
 * @property string $income_total
 * @property string $expense_total
 * @property string $realised_profit
 * @property string $reserve_percentage
 * @property string $reserve_appropriated
 * @property int|null $profit_journal_entry_id
 * @property int|null $reserve_journal_entry_id
 * @property int|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property string|null $notes
 */
class AccountingPeriod extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'period', 'status', 'income_total', 'expense_total', 'realised_profit',
        'reserve_percentage', 'reserve_appropriated',
        'profit_journal_entry_id', 'reserve_journal_entry_id',
        'closed_by', 'closed_at', 'notes',
    ];

    /** @return HasMany<PeriodBranchResult, $this> */
    public function branchResults(): HasMany
    {
        return $this->hasMany(PeriodBranchResult::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function profitEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'profit_journal_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reserveEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reserve_journal_entry_id');
    }

    public function realisedProfitMoney(): Money
    {
        return Money::of($this->realised_profit);
    }

    public function reserveAppropriatedMoney(): Money
    {
        return Money::of($this->reserve_appropriated);
    }

    /** Whether this period has been closed. */
    public static function isClosed(string $period): bool
    {
        return static::query()
            ->where('period', $period)
            ->where('status', PeriodStatus::Closed)
            ->exists();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }
}
