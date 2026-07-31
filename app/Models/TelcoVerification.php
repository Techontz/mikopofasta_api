<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\TelcoVerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `telco_verifications`.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $provider
 * @property array<string, mixed> $request_payload
 * @property array<string, mixed>|null $response_payload
 * @property TelcoVerificationStatus $status
 * @property CarbonImmutable|null $verified_at
 */
class TelcoVerification extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'provider', 'request_payload', 'response_payload', 'status', 'verified_at',
    ];

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
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'status' => TelcoVerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}
