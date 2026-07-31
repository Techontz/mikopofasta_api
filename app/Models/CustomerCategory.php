<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\RiskTier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.3 — `customer_categories`. The KYC/risk rule engine.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property RiskTier $risk_tier
 * @property CategorySector $sector
 * @property list<string> $required_documents
 * @property list<array<string, mixed>> $dynamic_form_schema
 * @property bool $requires_extra_approval
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class CustomerCategory extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'code', 'risk_tier', 'sector',
        'required_documents', 'dynamic_form_schema',
        'requires_extra_approval', 'created_by',
    ];

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mirrors the frontend's needsApproval().
     */
    public function needsApproval(): bool
    {
        return $this->requires_extra_approval;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_tier' => RiskTier::class,
            'sector' => CategorySector::class,
            'required_documents' => 'array',
            'dynamic_form_schema' => 'array',
            'requires_extra_approval' => 'boolean',
        ];
    }
}
