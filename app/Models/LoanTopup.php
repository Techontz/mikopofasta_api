<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `loan_topups`.
 *
 * The table exists because §2.5 defines it; no endpoint creates a row yet.
 * Granting a top-up would mean deciding how the outstanding balance of the
 * original loan rolls into the new principal, which is not specified anywhere.
 * `GET /loans/{id}/topup-eligibility` (§15.2) is implemented and read-only.
 *
 * @property int $id
 * @property int $original_loan_id
 * @property int $new_loan_id
 * @property array<string, mixed> $eligibility_snapshot
 * @property CarbonImmutable $created_at
 */
class LoanTopup extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['original_loan_id', 'new_loan_id', 'eligibility_snapshot', 'created_at'];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function originalLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'original_loan_id');
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function newLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'new_loan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['eligibility_snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
