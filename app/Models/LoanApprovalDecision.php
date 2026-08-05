<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Loans\Enums\LoanApprovalDecision as Decision;
use App\Domain\Loans\Enums\LoanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision an approver took, at one stage, on one loan.
 *
 * Append-only. Nothing here is ever updated or deleted: a decision that could
 * be edited afterwards is not an audit trail, and "who approved this and when"
 * is the first question asked about any loan that goes wrong.
 *
 * The stage code and name are copied onto the row rather than only referenced,
 * so a trail read years later still says "Zone Manager" even if that stage has
 * since been renamed or retired.
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $loan_approval_stage_id
 * @property string $stage_code
 * @property string $stage_name
 * @property Decision $decision
 * @property LoanStatus $from_status
 * @property LoanStatus $to_status
 * @property string|null $reason
 * @property int $decided_by
 * @property CarbonImmutable|null $created_at
 */
class LoanApprovalDecision extends Model
{
    /** The row is written once and never touched again. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'loan_id', 'loan_approval_stage_id', 'stage_code', 'stage_name',
        'decision', 'from_status', 'to_status', 'reason', 'decided_by', 'created_at',
    ];

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

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'from_status' => LoanStatus::class,
            'to_status' => LoanStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
