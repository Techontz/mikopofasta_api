<?php

namespace App\Models;

use App\Enums\Duration;
use App\Models\Concerns\Auditable;
use Database\Factories\LoanCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanCategory extends Model
{
    /** @use HasFactory<LoanCategoryFactory> */
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'fee_deduct' => 'boolean',
            'has_penalty' => 'boolean',
            'requires_mandate' => 'boolean',
            'topup_percent' => 'decimal:2',
            'freeze_time_days' => 'integer',
            'take_home_percent' => 'decimal:2',
            'fee_value' => 'decimal:2',
            'insurance' => 'decimal:2',
            'duration' => Duration::class,
        ];
    }

    /**
     * The one customer type this loan category is offered to (customer_categories). Trashed types still resolve for display.
     */
    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id')->withTrashed();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * Active loan categories: the customer type is active (not deleted) and the category is assigned to at least one branch (a
     * category with no branch cannot be applied for anywhere). Pass a branch to require that branch.
     */
    public function scopeActive(Builder $query, ?int $branchId = null): void
    {
        $query->whereHas('customerType', fn (Builder $type) => $type->where('is_active', true)->whereNull('deleted_at'))
            ->whereHas('branches', fn (Builder $branches) => $branchId === null ? $branches : $branches->whereKey($branchId));
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * "WAJASILIAMALI / 20000 - 2000000" as used in the loan application dropdown.
     */
    protected function optionLabel(): Attribute
    {
        return Attribute::get(fn (): string => sprintf('%s / %d - %d', $this->name, $this->amount_from, $this->amount_to));
    }

    /**
     * "20,000 - 2,000,000" as used in category tables.
     */
    protected function levelLabel(): Attribute
    {
        return Attribute::get(fn (): string => number_format((float) $this->amount_from).' - '.number_format((float) $this->amount_to));
    }

    /**
     * Loan fee for a given principal, honouring MONEY / PERCENTAGE fee types.
     */
    public function feeFor(float $principal): float
    {
        if ($this->fee_type === 'percentage') {
            return round($principal * (float) $this->fee_value / 100, 2);
        }

        return (float) $this->fee_value;
    }
}
