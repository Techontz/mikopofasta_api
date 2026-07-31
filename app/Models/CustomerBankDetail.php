<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.4 — `customer_bank_details`.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property string|null $check_number
 * @property string|null $phone_number
 */
class CustomerBankDetail extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id', 'bank_name', 'account_number', 'account_name', 'check_number', 'phone_number',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
