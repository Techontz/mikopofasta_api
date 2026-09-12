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
 * @property string|null $description
 * @property string|null $form_title
 * @property list<string>|null $optional_documents
 * @property bool $is_active
 * @property int $sort_order
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
        'name', 'code', 'description',
        /* The heading the registration form shows over this type's own
           questions. Null means "use the name". */
        'form_title',
        /* Whether the type is offered to new registrations, and in what order
           the officer sees it. Switching one off leaves every customer already
           filed under it exactly as they are. */
        'is_active', 'sort_order',
        'risk_tier', 'sector',
        /* Which of the first-class registration blocks this category asks
           for. Booleans on the category rather than entries in
           `dynamic_form_schema`, because sector, contract and salary are real
           typed columns on `customers` and declaring them in the schema too
           would store the same fact in two shapes. See the 2026_08_30
           migration. */
        'requires_sector', 'requires_employer', 'requires_contract', 'requires_salary',
        /* What the file MUST contain, and what it MAY contain. Two lists
           rather than one list of objects with a flag, because
           `required_documents` already means "mandatory" to KycEvaluator and
           to the wizard, and widening it would have changed a contract three
           readers depend on in order to express one boolean. */
        'required_documents', 'optional_documents', 'dynamic_form_schema',
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
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'required_documents' => 'array',
            'optional_documents' => 'array',
            'dynamic_form_schema' => 'array',
            'requires_extra_approval' => 'boolean',
            'requires_sector' => 'boolean',
            'requires_employer' => 'boolean',
            'requires_contract' => 'boolean',
            'requires_salary' => 'boolean',
        ];
    }
}
