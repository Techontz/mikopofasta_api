<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One branch's answer for one approval stage — D4's configurability.
 *
 * A row here overrides the stage's default rule for this branch: `is_required`
 * true forces the stage into that branch's chain, false forces it out. The
 * absence of a row means "use the default", which for the zone stage is "include
 * it when the branch belongs to a zone".
 *
 * @property int $id
 * @property int $branch_id
 * @property int $loan_approval_stage_id
 * @property bool $is_required
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BranchApprovalRoute extends Model
{
    /** @var list<string> */
    protected $fillable = ['branch_id', 'loan_approval_stage_id', 'is_required'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<LoanApprovalStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(LoanApprovalStage::class, 'loan_approval_stage_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }
}
