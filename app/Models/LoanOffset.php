<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\LoanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Old loan debt settled out of a new top-up loan (specification §13 and §14). The customer never brought this money in
 * cash, so it is not a genuine collection: it is excluded from the commission base and added back before dividend and
 * reinvestment (§15).
 *
 * Offset is a tracking attribute, never an account and never an income category. The settlement itself is posted as an
 * ordinary repayment, component by component, by {@see LoanService::deposit()}; the columns here record what
 * each component was, so the total can be reconciled against the top-up settlements (§52).
 */
class LoanOffset extends Model
{
    use Auditable;

    /** @var list<string> */
    public const COMPONENTS = ['principal', 'penalty', 'interest', 'salary_advance', 'insurance'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'salary_advance_amount' => 'decimal:2',
            'insurance_amount' => 'decimal:2',
            'cash_disbursed' => 'decimal:2',
            'settled_on' => 'date',
            'reversed_at' => 'datetime',
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

    /** The loan that was settled. */
    public function oldLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'old_loan_id');
    }

    /** The top-up loan that settled it. */
    public function newLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'new_loan_id');
    }

    public function loanTransaction(): BelongsTo
    {
        return $this->belongsTo(LoanTransaction::class);
    }

    /**
     * Offsets that still count: a reversed top-up settlement is no longer an offset.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    /**
     * Offset settled in a period, company-wide or for one branch — the figure the commission base excludes and the
     * distribution base adds back (§15).
     *
     * @param  list<int>|null  $branchIds  null = the whole company
     */
    public static function settledBetween(int $companyId, string $from, string $to, ?array $branchIds = null): float
    {
        return round((float) self::query()->active()
            ->where('company_id', $companyId)
            ->whereBetween('settled_on', [$from, $to])
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->sum('amount'), 2);
    }
}
