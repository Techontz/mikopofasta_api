<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.3 — `interest_formulas`.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 */
class InterestFormula extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description', 'is_default'];

    /**
     * @return HasMany<LoanProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(LoanProduct::class);
    }

    /**
     * The formula a new loan product starts on — client Decision 2, Reducing
     * EMI.
     *
     * A row rather than a constant, because that is what makes it a business
     * decision instead of a deploy. Returns null only if nothing is flagged,
     * which the caller treats as "no default", never as a silent substitution.
     */
    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /*
     * `code` is deliberately NOT cast to an enum any more.
     *
     * It was `ENUM('SIMPLE','FLAT','REDUCING')` in the schema and an enum in
     * PHP, so adding a formula meant a migration and a deploy — the opposite of
     * administrator-managed master data. The code is now a free string, and
     * InterestStrategyRegistry is the authority on which ones can actually be
     * priced: a code is valid when a strategy implements it.
     *
     * InterestFormulaCode still exists, but only as named constants for the
     * three formulas the system seeds. It no longer constrains what may be
     * stored.
     */
}
