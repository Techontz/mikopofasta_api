<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CustomerCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer type (shown as "Customer Type" everywhere in the UI; the table keeps its historical name): who the customer is —
 * required documents, risk level and the dynamic registration form. It holds NO loan configuration: its loan products are the
 * loan categories that reference it (loan_categories.customer_category_id, one customer type → many loan categories).
 */
class CustomerCategory extends Model
{
    /** @use HasFactory<CustomerCategoryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_documents' => 'array',
            'form_schema' => 'array',
            'is_active' => 'boolean',
            'optional_documents' => 'array',
            'dynamic_form_schema' => 'array',
            'omitted_standard_fields' => 'array',
            'sort_order' => 'integer',
            'requires_sector' => 'boolean',
            'requires_employer' => 'boolean',
            'requires_contract' => 'boolean',
            'requires_salary' => 'boolean',
            'requires_extra_approval' => 'boolean',
        ];
    }

    /**
     * The customer types a user may choose for one company: active, not deleted, in display order. The single
     * source for every Customer Type select, filter and option list.
     */
    public function scopeSelectable(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * The loan categories (loan products) offered to this customer type.
     */
    public function loanCategories(): HasMany
    {
        return $this->hasMany(LoanCategory::class, 'customer_category_id');
    }
}
