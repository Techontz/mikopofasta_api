<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Backend spec §2.2 — `branches`.
 *
 * HQ is one of these rows, flagged `is_head_office` (§12 Decision 2). Every
 * branch-scoped report runs unchanged against it, and an "HQ-wide" report is
 * the same query with the branch filter omitted.
 *
 * Two independent oversight groupings hang off a branch:
 *   - `zone_id`   commission / Zone Manager oversight
 *   - `region_id` geographic / Regional Manager oversight
 *
 * @property int $id
 * @property string $name
 * @property int|null $region_id
 * @property int|null $zone_id
 * @property string $phone
 * @property BranchType $type
 * @property int|null $parent_branch_id
 * @property bool $is_head_office
 * @property ActiveStatus $status
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Branch extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'region_id',
        'zone_id',
        'phone',
        'type',
        'parent_branch_id',
        'is_head_office',
        'status',
        'created_by',
    ];

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * The main branch this sub-branch rolls up into (§12).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'parent_branch_id');
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Branch::class, 'parent_branch_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Walks up to the root, nearest ancestor first.
     *
     * Guarded against a cycle rather than trusting the data: a cycle would
     * make this loop forever, and a hung request is a far worse symptom than
     * a truncated ancestor list. CreateBranchAction and UpdateBranchAction
     * prevent cycles from being written in the first place.
     *
     * @return Collection<int, Branch>
     */
    public function ancestors(): Collection
    {
        /** @var Collection<int, Branch> $ancestors */
        $ancestors = new Collection;
        $seen = [$this->id => true];

        $current = $this->parent;

        while ($current !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $ancestors->push($current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Every branch beneath this one, at any depth.
     *
     * @return Collection<int, Branch>
     */
    public function descendants(): Collection
    {
        /** @var Collection<int, Branch> $descendants */
        $descendants = new Collection;

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendants());
        }

        return $descendants;
    }

    /**
     * This branch plus everything under it — the set a branch-rollup report
     * covers.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        return array_values(array_unique([
            $this->id,
            ...$this->descendants()->pluck('id')->all(),
        ]));
    }

    public function isHeadOffice(): bool
    {
        return $this->is_head_office;
    }

    /**
     * @param Builder<Branch> $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ActiveStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BranchType::class,
            'status' => ActiveStatus::class,
            'is_head_office' => 'boolean',
        ];
    }
}
