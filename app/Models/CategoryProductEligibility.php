<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.3 — `category_product_eligibility`.
 *
 * The category → product rule engine: a row must exist for
 * (customer's category, product) before an application is accepted (§6).
 *
 * @property int $id
 * @property int $customer_category_id
 * @property int $loan_product_id
 * @property string|null $max_amount_override
 * @property bool $requires_extra_approval
 */
class CategoryProductEligibility extends Model
{
    protected $table = 'category_product_eligibility';

    /** @var list<string> */
    protected $fillable = [
        'customer_category_id', 'loan_product_id', 'max_amount_override', 'requires_extra_approval',
    ];

    /**
     * @return BelongsTo<CustomerCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }

    /**
     * @return BelongsTo<LoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requires_extra_approval' => 'boolean'];
    }
}
