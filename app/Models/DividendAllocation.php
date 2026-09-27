<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shareholder's dividend entitlement in a declaration (ownership snapshot: shares held ÷ total shares on the
 * declaration's as-of date). Paid out from the Dividend account in one or more {@see DividendPayment}s; paid_amount and
 * status are rewritten from the posted payments inside the payment / reversal transaction.
 */
class DividendAllocation extends Model
{
    use Auditable;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_UNPAID => 'UNPAID',
        self::STATUS_PARTIALLY_PAID => 'PARTIALLY PAID',
        self::STATUS_PAID => 'PAID',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'contribution_total' => 'decimal:2',
            'shares_held' => 'integer',
            'total_shares' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DividendPayment::class);
    }

    /**
     * Status for a paid total against the entitlement (amounts in cents).
     */
    public static function statusFor(int $entitlementCents, int $paidCents): string
    {
        return match (true) {
            $paidCents <= 0 => self::STATUS_UNPAID,
            $paidCents >= $entitlementCents => self::STATUS_PAID,
            default => self::STATUS_PARTIALLY_PAID,
        };
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
