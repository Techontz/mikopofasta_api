<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\GuarantorRelationship;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frontend addition (types/guarantor.ts) — a customer may have several
 * guarantors, each independently manageable.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string $phone
 * @property string|null $nida_number
 * @property GuarantorRelationship $relationship
 * @property string|null $address
 * @property string|null $occupation
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Guarantor extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id', 'name', 'phone', 'nida_number', 'relationship', 'address', 'occupation',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['relationship' => GuarantorRelationship::class];
    }
}
