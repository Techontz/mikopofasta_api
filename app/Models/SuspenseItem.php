<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.6 — `suspense_items`.
 *
 * §5 calls Suspense "the universal 'unknown money' bucket". Nothing sits
 * un-ledgered: an unmatched payment is Dr Cash / Cr Suspense the moment it
 * arrives, and this row is the queue entry for identifying it.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $reason
 * @property string $amount
 * @property SuspenseStatus $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 */
class SuspenseItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['payment_id', 'reason', 'amount', 'status', 'resolved_by', 'resolved_at'];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    public function isResolved(): bool
    {
        return $this->status === SuspenseStatus::Allocated;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => SuspenseStatus::class, 'resolved_at' => 'datetime'];
    }
}
