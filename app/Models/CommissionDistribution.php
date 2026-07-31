<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `commission_distributions`. One staff member's share of
 * a branch pool, weighted by their base-salary share (§11).
 *
 * @property int $id
 * @property int $commission_pool_id
 * @property int $staff_profile_id
 * @property string $share_amount
 */
class CommissionDistribution extends Model
{
    /** @var list<string> */
    protected $fillable = ['commission_pool_id', 'staff_profile_id', 'share_amount'];

    /**
     * @return BelongsTo<CommissionPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(CommissionPool::class, 'commission_pool_id');
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function shareAmount(): Money
    {
        return Money::of($this->share_amount);
    }
}
