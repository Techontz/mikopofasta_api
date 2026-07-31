<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\EMandateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `e_mandates`.
 *
 * A retry inserts a NEW mandate rather than resetting the failed one, so the
 * history of failed attempts survives.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $bank_name
 * @property string|null $otp_reference
 * @property EMandateStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $verified_at
 */
class EMandate extends Model
{
    /** @var list<string> */
    protected $fillable = ['loan_id', 'bank_name', 'otp_reference', 'status', 'failure_reason', 'verified_at'];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => EMandateStatus::class, 'verified_at' => 'datetime'];
    }
}
