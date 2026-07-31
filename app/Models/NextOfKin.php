<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\GuarantorRelationship;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frontend addition (types/next-of-kin.ts) — standard KYC concept collected by
 * the registration wizard.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property GuarantorRelationship $relationship
 * @property string $phone
 * @property string|null $address
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class NextOfKin extends Model
{
    protected $table = 'next_of_kin';

    /**
     * @var list<string>
     */
    protected $fillable = ['customer_id', 'name', 'relationship', 'phone', 'address'];

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
