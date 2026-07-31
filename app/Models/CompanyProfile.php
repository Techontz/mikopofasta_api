<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The organization's singleton profile.
 *
 * Introduced by the frontend (types/organization.ts), not by backend spec §2 —
 * see the migration for why. Exactly one row exists; `current()` is the only
 * way the application reaches it, so no caller has to remember the id.
 *
 * @property int $id
 * @property string $legal_name
 * @property string $trading_name
 * @property string $registration_number
 * @property string $tin_number
 * @property string $phone
 * @property string $email
 * @property string $address
 * @property int|null $headquarters_branch_id
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CompanyProfile extends Model
{
    /**
     * The id the frontend expects to see. types/organization.ts declares
     * `id: z.literal("company-profile")`, so the resource emits this constant
     * instead of the numeric primary key.
     */
    public const string PUBLIC_ID = 'company-profile';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_name',
        'trading_name',
        'registration_number',
        'tin_number',
        'phone',
        'email',
        'address',
        'headquarters_branch_id',
        'updated_by',
    ];

    /**
     * The singleton row.
     */
    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function headquarters(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'headquarters_branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
