<?php

namespace App\Models;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Concerns\Auditable;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'instalment' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'loan_fee' => 'decimal:2',
            'insurance' => 'decimal:2',
            'restoration' => 'decimal:2',
            'fee_deduct' => 'boolean',
            'is_special' => 'boolean',
            'is_legacy_opening' => 'boolean',
            'opening_paid_principal' => 'decimal:2',
            'approved_at' => 'datetime',
            'agreement_uploaded_at' => 'datetime',
            'withdrawn_at' => 'date',
            'end_date' => 'date',
            'expected_completion_date' => 'date',
            'telco_matched' => 'boolean',
            'telco_verified_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
            'early_settlement' => 'boolean',
            'freeze_started_at' => 'datetime',
            'freeze_days' => 'integer',
            'frozen_until' => 'datetime',
            'status' => LoanStatus::class,
            'duration' => Duration::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LoanCategory::class, 'loan_category_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function agreementUploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'agreement_uploaded_by');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class)->orderBy('due_date');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoanTransaction::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function writeOff(): HasOne
    {
        return $this->hasOne(WriteOff::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(LoanRecovery::class);
    }

    public function mandate(): HasOne
    {
        return $this->hasOne(LoanMandate::class)->latestOfMany();
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class)->orderBy('attempt');
    }

    public function latestDisbursement(): HasOne
    {
        return $this->hasOne(LoanDisbursement::class)->latestOfMany();
    }

    /**
     * The row of the old-system Loan File this loan was imported from (legacy opening balance), with the printed figures
     * and the January–September payment history.
     *
     * @return BelongsTo<LegacyImportRow, $this>
     */
    public function legacyImportRow(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRow::class);
    }

    public function topupOf(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'topup_of_loan_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * @param  Builder<Loan>  $query
     */
    public function scopeStatus(Builder $query, LoanStatus ...$statuses): void
    {
        $query->whereIn('status', array_map(fn (LoanStatus $status): string => $status->value, $statuses));
    }

    /**
     * Re-borrowing freeze of this loan: "frozen" while now < frozen_until, "expired" once it passed, "none" when the loan
     * was not settled early (or its category had no Freeze Time).
     *
     * @return 'frozen'|'expired'|'none'
     */
    public function freezeStatus(?CarbonImmutable $now = null): string
    {
        if ($this->early_settlement !== true || $this->frozen_until === null) {
            return 'none';
        }

        return $this->frozen_until->gt($now ?? CarbonImmutable::now()) ? 'frozen' : 'expired';
    }

    protected function paidAmount(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->transactions()->where('type', 'deposit')->whereNull('reversed_at')->sum('amount'));
    }

    /**
     * Outstanding principal + penalty + interest + insurance (see LoanService::outstanding()).
     */
    protected function remainingAmount(): Attribute
    {
        return Attribute::get(fn (): float => app(LoanService::class)->outstanding($this)['total']);
    }
}
