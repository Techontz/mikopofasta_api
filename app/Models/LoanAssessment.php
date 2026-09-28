<?php

namespace App\Models;

use App\Services\Credit\CreditAssessment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored credit recommendation ({@see CreditAssessment}): what the engine recommended for a loan application, the
 * score, the ordered factors with their weights, contributions and evidence, and the plain-language explanation —
 * exactly as the Credit Officer saw it (§36 / §37).
 *
 * The snapshot is advisory evidence, never a control: it holds no status and approves nothing. Rows are never edited;
 * a re-assessment writes a new row and the previous ones stay for audit.
 */
class LoanAssessment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'recommended_amount' => 'decimal:2',
            'recommended_ratio' => 'decimal:4',
            'score' => 'decimal:2',
            'factors' => 'array',
            'excluded_factors' => 'array',
            'overrides' => 'array',
            'limits' => 'array',
            'steps' => 'array',
            'analysis' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assessed_by');
    }

    /**
     * Newest snapshot first.
     *
     * @param  Builder<LoanAssessment>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('assessed_at')->orderByDesc('id');
    }
}
