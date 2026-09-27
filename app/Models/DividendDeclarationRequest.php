<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested dividend declaration awaiting approval (C1, rule 6 maker/checker). Nothing is posted while it is pending; a
 * different authorised user approves it (the declaration and its journals are then posted, dated the approval date) or rejects
 * it (kept as REJECTED with the reason). Only one request per company and month may be pending ({@see self::pendingKey()}).
 */
class DividendDeclarationRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'profit_amount' => 'decimal:2',
            'distributable_profit' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'dividend_percent' => 'decimal:2',
            'dividend_amount' => 'decimal:2',
            'reinvest_percent' => 'decimal:2',
            'reinvest_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Unique value held by a PENDING request of a company and month (null once approved or rejected).
     */
    public static function pendingKey(int $companyId, CarbonInterface $period): string
    {
        return $companyId.':'.$period->format('Y-m');
    }

    /**
     * The pending request of a company and month.
     *
     * @return Builder<DividendDeclarationRequest>
     */
    public static function pendingFor(int $companyId, CarbonInterface $period): Builder
    {
        return self::query()->where('pending_key', self::pendingKey($companyId, $period));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * "August 2026".
     */
    public function periodLabel(): string
    {
        return $this->period->format('F Y');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }
}
