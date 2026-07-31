<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\DisbursementChannel;
use App\Domain\Loans\Enums\DisbursementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `disbursement_batches`. One row per attempt.
 *
 * §6: on failure a NEW row is created with attempt_number+1 and a fresh
 * reference; the old batch is never mutated. After 3 failed attempts the loan
 * is escalated for a manual decision.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $batch_reference
 * @property int $attempt_number
 * @property DisbursementChannel $channel
 * @property DisbursementStatus $status
 * @property string|null $failure_reason
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $completed_at
 */
class DisbursementBatch extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'batch_reference', 'attempt_number', 'channel',
        'status', 'failure_reason', 'requested_by', 'requested_at', 'completed_at',
    ];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DisbursementChannel::class,
            'status' => DisbursementStatus::class,
            'attempt_number' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
