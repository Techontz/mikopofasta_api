<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `staff_advances`.
 *
 * §11's lifecycle, and the separation it insists on: HR approves, **Finance**
 * disburses, never HR.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property string $amount
 * @property StaffAdvanceStatus $status
 * @property CarbonImmutable $requested_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $disbursed_at
 * @property int|null $journal_entry_id
 */
class StaffAdvance extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_profile_id', 'amount', 'status', 'requested_at',
        'approved_by', 'approved_at', 'disbursed_at', 'journal_entry_id',
    ];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
            'status' => StaffAdvanceStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_datetime',
        ];
    }
}
