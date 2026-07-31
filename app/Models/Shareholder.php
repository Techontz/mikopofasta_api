<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person holding equity in the company — Capital → Share Holders.
 *
 * Distinct from User: a shareholder is not staff and has no login. The two are
 * deliberately unrelated tables.
 *
 * @property int $id
 * @property string $full_name
 * @property string $phone
 * @property string $email
 * @property string $gender
 * @property CarbonImmutable $date_of_birth
 * @property CarbonImmutable|null $deleted_at
 */
class Shareholder extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['full_name', 'phone', 'email', 'gender', 'date_of_birth', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_of_birth' => 'immutable_date'];
    }

    /** @return HasMany<CapitalContribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(CapitalContribution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
