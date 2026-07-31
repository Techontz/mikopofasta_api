<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Repayments\Enums\TriggeredBy;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Backend spec §2.6 — `penalty_runs`. One row per execution of §7's
 * `penalty:apply` job.
 *
 * @property int $id
 * @property CarbonImmutable $run_date
 * @property int $loans_processed
 * @property int $installments_penalised
 * @property string $total_penalty_applied
 * @property TriggeredBy $triggered_by
 * @property int|null $triggered_by_user_id
 */
class PenaltyRun extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'run_date', 'loans_processed', 'installments_penalised',
        'total_penalty_applied', 'triggered_by', 'triggered_by_user_id', 'created_at',
    ];

    public function totalPenalty(): Money
    {
        return Money::of($this->total_penalty_applied);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'triggered_by' => TriggeredBy::class,
            'created_at' => 'datetime',
            'loans_processed' => 'integer',
            'installments_penalised' => 'integer',
        ];
    }
}
