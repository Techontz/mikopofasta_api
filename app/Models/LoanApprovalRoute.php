<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of the chain a specific loan is walking — its route snapshot.
 *
 * Written once, at application time, from the branch's configuration as it
 * stood then. Never rewritten: *"Existing loans already in progress must
 * continue following the route they were assigned when created."*
 *
 * `sequence` is copied rather than joined so the snapshot survives the stage
 * being reordered afterwards, which is the whole point of taking one.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $loan_approval_stage_id
 * @property int $sequence
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LoanApprovalRoute extends Model
{
    /** @var list<string> */
    protected $fillable = ['loan_id', 'loan_approval_stage_id', 'sequence'];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<LoanApprovalStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(LoanApprovalStage::class, 'loan_approval_stage_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }
}
