<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\ChargeValueType;
use App\Domain\Loans\Enums\LoanStatus;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Backend spec §2.5 — `loans`.
 *
 * The three `_snapshot` columns are the point of the design (§6): a loan's
 * commercial terms are frozen at application time, so an administrator
 * editing a product never silently rewrites an agreement already made with a
 * customer.
 *
 * @property int $id
 * @property string $loan_number
 * @property int $customer_id
 * @property int $loan_product_id
 * @property int $repayment_schedule_id
 * @property int|null $group_id
 * @property int $branch_id
 * @property int $officer_id
 * @property string $principal_amount
 * @property string $interest_rate_snapshot
 * @property string $penalty_rate_snapshot
 * @property int $tenure_days
 * @property bool $requires_mandate_snapshot
 * @property LoanStatus $status
 * @property int|null $approval_stage_id
 * @property LoanStatus|null $hold_resume_status
 * @property CarbonImmutable|null $disbursement_date
 * @property CarbonImmutable|null $expected_completion_date
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_reason
 * @property string $interest_waived
 * @property string|null $payment_reference
 * @property CarbonImmutable|null $payment_reference_issued_at
 * @property CarbonImmutable|null $early_settled_at
 * @property int|null $early_settled_by
 * @property int|null $early_settlement_payment_id
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $frozen_until
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 */
class Loan extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'loan_number', 'payment_reference', 'payment_reference_issued_at',
        'customer_id', 'loan_product_id', 'repayment_schedule_id', 'group_id',
        'branch_id', 'officer_id', 'principal_amount',
        'interest_rate_snapshot', 'penalty_rate_snapshot', 'tenure_days', 'requires_mandate_snapshot',
        'fee_type_snapshot', 'fee_amount_snapshot', 'insurance_amount_snapshot', 'fee_charged',
        'status', 'approval_stage_id', 'hold_resume_status',
        'disbursement_date', 'expected_completion_date',
        'approved_by', 'approved_at', 'rejected_reason',
        'interest_waived', 'early_settled_at', 'early_settled_by', 'early_settlement_payment_id',
        'closed_at', 'frozen_until', 'created_by',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<LoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    /**
     * @return BelongsTo<RepaymentSchedule, $this>
     */
    public function repaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(RepaymentSchedule::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The officer who chose to close the loan early.
     *
     * Separate from the settlement payment rather than read off it, because a
     * loan whose whole remaining balance was unearned interest is settled by
     * the waiver alone and has no payment — but it always had somebody decide.
     *
     * @return BelongsTo<User, $this>
     */
    public function earlySettledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'early_settled_by');
    }

    /**
     * The payment that settled the loan, if any money changed hands.
     *
     * Linked rather than copied: the reference and the amount live on the
     * payment, and duplicating them here would give them a second place to be
     * wrong.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function earlySettlementPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'early_settlement_payment_id');
    }

    /**
     * @return HasMany<LoanSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class)->orderBy('installment_number');
    }

    /**
     * @return HasMany<LoanStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(LoanStatusHistory::class);
    }

    /**
     * @return HasMany<EMandate, $this>
     */
    public function mandates(): HasMany
    {
        return $this->hasMany(EMandate::class);
    }

    /**
     * Which tier of the approval chain this loan is waiting on.
     *
     * Null once it has left the chain — approved through, rejected, returned to
     * the officer, or already disbursing. A HELD loan keeps its stage, because
     * that is where releasing puts it back.
     *
     * @return BelongsTo<LoanApprovalStage, $this>
     */
    public function approvalStage(): BelongsTo
    {
        return $this->belongsTo(LoanApprovalStage::class, 'approval_stage_id');
    }

    /**
     * Every decision taken on this loan, newest last.
     *
     * The chain's own record, distinct from `statusHistory`: a status change
     * says where the loan went, a decision says who decided it, at which stage,
     * and why.
     *
     * @return HasMany<LoanApprovalDecision, $this>
     */
    public function approvalDecisions(): HasMany
    {
        return $this->hasMany(LoanApprovalDecision::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<TelcoVerification, $this>
     */
    public function telcoVerifications(): HasMany
    {
        return $this->hasMany(TelcoVerification::class);
    }

    /**
     * @return HasMany<DisbursementBatch, $this>
     */
    public function disbursementBatches(): HasMany
    {
        return $this->hasMany(DisbursementBatch::class);
    }

    /**
     * What was withheld from the payout as fee income.
     *
     * Zero rather than null for a loan that has not disbursed or whose product
     * charges nothing — callers are summing money, and the distinction between
     * "no fee agreed" and "no fee yet" is on the snapshot columns, not here.
     */
    public function feeCharged(): Money
    {
        return $this->fee_charged === null ? Money::zero() : Money::of((string) $this->fee_charged);
    }

    public function principal(): Money
    {
        return Money::of($this->principal_amount);
    }

    public function interestRate(): Percentage
    {
        return Percentage::of($this->interest_rate_snapshot);
    }

    /**
     * Still counts against the customer's "one open loan at a time" rule —
     * mirrors the frontend's `isLoanOpen`.
     */
    public function isOpen(): bool
    {
        return ! $this->status->isTerminal() && $this->deleted_at === null;
    }

    /**
     * Total still owed across every installment. Zero before approval, since
     * no schedule exists yet.
     */
    public function outstandingTotal(): Money
    {
        return Money::sum($this->schedules->map(fn (LoanSchedule $s): Money => $s->outstandingTotal()));
    }

    public function totalPayable(): Money
    {
        return Money::sum($this->schedules->map(fn (LoanSchedule $s): Money => $s->totalDue()));
    }

    /**
     * The same two totals as `totalPayable()` and `outstandingTotal()`, summed
     * in SQL instead of in PHP.
     *
     * Listing loans cannot afford the object versions: they walk `$this->schedules`,
     * so a page of 25 loans is 25 schedule loads, and a caller that wanted a
     * portfolio total had to ask per loan. The frontend was doing exactly that —
     * one `/loans/{id}/schedule` request per row — and tripped the rate limiter
     * under a burst.
     *
     * Two `SUM`s rather than one `SUM(due - paid)` because the resource emits
     * both figures, and because a single subquery per column keeps each one a
     * plain aggregate the planner can satisfy from the `loan_id` index.
     *
     * The arithmetic is identical. Both sides sum the same six DECIMAL(18,2)
     * columns, and MySQL's DECIMAL addition is exact, so the driver hands back
     * a decimal string that `Money::of()` parses without rounding — the same
     * value the PHP path produces, not an approximation of it.
     *
     * Loans with no schedule yet (nothing is owed before approval) aggregate to
     * NULL, which `COALESCE` renders as the zero those loans genuinely owe.
     *
     * @param Builder<Loan> $query
     * @return Builder<Loan>
     */
    public function scopeWithScheduleTotals(Builder $query): Builder
    {
        return $query
            ->withSum('schedules as schedule_due_total', DB::raw('principal_due + interest_due + penalty_due'))
            ->withSum('schedules as schedule_paid_total', DB::raw('principal_paid + interest_paid + penalty_paid'));
    }

    /**
     * @param Builder<Loan> $query
     * @return Builder<Loan>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            // Both identifiers: staff quote the application number, customers
            // quote the payment reference, and a teller taking a call should
            // find the loan from whichever they are read.
            $q->where('loan_number', 'like', $like)
                ->orWhere('payment_reference', 'like', $like)
                ->orWhereHas('customer', function (Builder $c) use ($like): void {
                    $c->where('customer_number', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like]);
                });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'hold_resume_status' => LoanStatus::class,
            'requires_mandate_snapshot' => 'boolean',
            'fee_type_snapshot' => ChargeValueType::class,
            'fee_amount_snapshot' => 'decimal:3',
            'insurance_amount_snapshot' => 'decimal:2',
            'fee_charged' => 'decimal:2',
            'tenure_days' => 'integer',
            'disbursement_date' => 'date',
            'expected_completion_date' => 'date',
            'frozen_until' => 'date',
            'approved_at' => 'datetime',
            'payment_reference_issued_at' => 'datetime',
            'early_settled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
