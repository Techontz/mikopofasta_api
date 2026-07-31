<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\GroupRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.4 — `group_members`.
 *
 * @property int $id
 * @property int $group_id
 * @property int $customer_id
 * @property GroupRole $role
 * @property CarbonImmutable $joined_at
 * @property string $status
 */
class GroupMember extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['group_id', 'customer_id', 'role', 'joined_at', 'status'];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

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
        return ['joined_at' => 'date', 'role' => GroupRole::class];
    }
}
