<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `staff_bank_details`.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property string $bank_name
 * @property string $account_number
 */
class StaffBankDetail extends Model
{
    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'bank_name', 'account_number'];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }
}
