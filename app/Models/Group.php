<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActiveStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.4 — `groups`.
 *
 * A village banking group: members drawn from one branch, an elected committee,
 * and a meeting day on which collections happen. The rules governing membership
 * live in GroupService; this is the record.
 *
 * @property int $id
 * @property string $name
 * @property int $branch_id
 * @property int|null $leader_customer_id
 * @property ActiveStatus $status
 * @property int|null $meeting_day ISO-8601 weekday, 1 = Monday
 * @property string|null $meeting_time
 * @property CarbonImmutable|null $deleted_at
 *
 * Not a column. The controller computes it from the members' loan schedules and
 * assigns it after paging, because deriving it in the query would load the whole
 * loan book to render one page of groups. Absent unless that has happened, which
 * is what the resource's isset() check reads.
 * @property string|null $outstanding_balance
 */
class Group extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'branch_id', 'leader_customer_id', 'status', 'meeting_day', 'meeting_time'];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'leader_customer_id');
    }

    /**
     * @return HasMany<GroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Members who have not left. The list screens and every count mean this —
     * a departed member stays on the table as history, not as a member.
     *
     * @return HasMany<GroupMember, $this>
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class)->where('status', 'active');
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }
}
