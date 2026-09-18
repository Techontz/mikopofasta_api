<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.7 — `reversal_requests`.
 *
 * §14: requesting and approving a reversal are different permissions held by
 * different people. This row records who did which, and — since the table was
 * widened — WHAT was reversed: a payment, a disbursement, a penalty, a salary
 * advance payment or a bare journal entry. `reversal_type` decides which of the four subject columns is
 * set and which executor runs on approval.
 *
 * @property int $id
 * @property ReversalType $reversal_type
 * @property int|null $journal_entry_id
 * @property int|null $payment_id
 * @property int|null $disbursement_batch_id
 * @property int|null $loan_schedule_id
 * @property int|null $customer_advance_payment_id
 * @property int|null $loan_id
 * @property int $requested_by
 * @property string $reason
 * @property string|null $amount
 * @property int|null $approved_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $reversal_entry_id
 * @property ReversalStatus $status
 */
class ReversalRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reversal_type', 'journal_entry_id', 'payment_id', 'disbursement_batch_id',
        'loan_schedule_id', 'customer_advance_payment_id', 'loan_id', 'requested_by', 'reason', 'amount',
        'approved_by', 'decided_at', 'decision_note', 'reversal_entry_id', 'status',
    ];

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<DisbursementBatch, $this>
     */
    public function disbursementBatch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class);
    }

    /**
     * @return BelongsTo<LoanSchedule, $this>
     */
    public function loanSchedule(): BelongsTo
    {
        return $this->belongsTo(LoanSchedule::class, 'loan_schedule_id');
    }

    /**
     * @return BelongsTo<CustomerAdvancePayment, $this>
     */
    public function advancePayment(): BelongsTo
    {
        return $this->belongsTo(CustomerAdvancePayment::class, 'customer_advance_payment_id');
    }

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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === ReversalStatus::Pending;
    }

    /**
     * What the request proposes to reverse, as snapshotted when it was raised.
     *
     * Snapshotted rather than recomputed because a penalty waiver destroys its
     * own evidence — clearing `penalty_due` leaves nothing to read the figure
     * back off afterwards.
     */
    public function amountMoney(): Money
    {
        return Money::of($this->amount ?? '0.00');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reversal_type' => ReversalType::class,
            'status' => ReversalStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
