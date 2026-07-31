<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\LoanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.5 — `loan_status_history`. The audit trail §10 insists on.
 *
 * @property int $id
 * @property int $loan_id
 * @property LoanStatus|null $from_status
 * @property LoanStatus $to_status
 * @property int|null $changed_by
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
class LoanStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'loan_status_history';

    /** @var list<string> */
    protected $fillable = ['loan_id', 'from_status', 'to_status', 'changed_by', 'reason', 'created_at'];

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => LoanStatus::class,
            'to_status' => LoanStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
