<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\MaritalStatus;
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
 * @property Gender|null $gender
 * @property MaritalStatus|null $marital_status
 * @property GuarantorRelationship $relationship
 * @property string|null $address
 * @property string|null $occupation
 * @property string|null $passport_path
 * @property string|null $passport_original_name
 * @property string|null $passport_mime_type
 * @property int|null $passport_size_bytes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Guarantor extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id', 'name', 'phone', 'nida_number', 'gender', 'marital_status',
        'relationship', 'address', 'occupation',
        /* The passport lives on the private `kyc` disk; only its path and the
           three facts the download response needs are stored here. */
        'passport_path', 'passport_original_name', 'passport_mime_type', 'passport_size_bytes',
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
        return [
            'relationship' => GuarantorRelationship::class,
            /* The same two enums `customers` casts for the same two facts. */
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'passport_size_bytes' => 'integer',
        ];
    }
}
