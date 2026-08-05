<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the number in a product's `interest_rate` means — P2, as master data.
 *
 * The row is the administrator's half of the decision; RateBasisRegistry finds
 * the class that implements it. A basis that is seeded but `is_active = false`
 * is implemented and not yet offered, which is exactly the state PER_ANNUM is
 * in until the client answers.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property bool|null $is_default
 * @property bool $is_active
 */
class InterestRateBasis extends Model
{
    use SoftDeletes;

    protected $table = 'interest_rate_bases';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description', 'is_default', 'is_active'];

    /** @return HasMany<LoanProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(LoanProduct::class, 'interest_rate_basis_id');
    }

    /**
     * The basis a product with none configured is priced on.
     *
     * Null when nothing is flagged — the registry falls back to AS_CONFIGURED,
     * which is the same answer, so a missing seed cannot misprice a loan.
     */
    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
