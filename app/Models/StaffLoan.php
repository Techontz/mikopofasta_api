<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `staff_loans`. §11's "internal mirror of the customer
 * loan engine", recovered automatically by payroll deduction.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property string $amount
 * @property StaffLoanStatus $status
 * @property CarbonImmutable $disbursed_at
 * @property int $journal_entry_id
 */
class StaffLoan extends Model
{
    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'amount', 'status', 'disbursed_at', 'journal_entry_id'];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffLoanStatus::class,
            'disbursed_at' => 'immutable_date',
        ];
    }
}
