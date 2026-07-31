<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\InterestFormulaCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.3 — `interest_formulas`.
 *
 * @property int $id
 * @property string $name
 * @property InterestFormulaCode $code
 * @property string|null $description
 */
class InterestFormula extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description'];

    /**
     * @return HasMany<LoanProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(LoanProduct::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['code' => InterestFormulaCode::class];
    }
}
