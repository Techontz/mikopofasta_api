<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\BranchApprovalRoute;
use App\Models\LoanApprovalStage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Pins, or unpins, which approval stages a branch's applications must clear — D4.
 *
 * ## Why the whole set is replaced
 *
 * The screen shows every stage and submits every stage. Merging instead would
 * make "remove this override" impossible to express: the caller would have to
 * send a value meaning "forget what I said before", which is a second vocabulary
 * for the same form.
 *
 * ## Why this changes nothing about loans already raised
 *
 * It writes configuration, and configuration is read at application time only.
 * Every loan already in the workflow carries its own route snapshot, so an
 * administrator switching zone review on for a branch this afternoon affects
 * applications raised from this afternoon — not the ones sitting on a manager's
 * desk. That is the client's instruction, and it is enforced by where the data
 * lives rather than by anybody remembering it here.
 *
 * Audited, because it changes who has to sign off a loan.
 */
final class ConfigureBranchRouteAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<int, bool> $overrides stage id => required
     */
    public function handle(Branch $branch, array $overrides, User $actor): void
    {
        DB::transaction(function () use ($branch, $overrides, $actor): void {
            $before = BranchApprovalRoute::query()
                ->where('branch_id', $branch->getKey())
                ->pluck('is_required', 'loan_approval_stage_id')
                ->all();

            BranchApprovalRoute::query()->where('branch_id', $branch->getKey())->delete();

            foreach ($overrides as $stageId => $required) {
                BranchApprovalRoute::query()->create([
                    'branch_id' => $branch->getKey(),
                    'loan_approval_stage_id' => $stageId,
                    'is_required' => $required,
                ]);
            }

            $this->audit->log(
                AuditAction::BranchApprovalRouteChanged,
                $branch,
                before: ['overrides' => $this->readable($before)],
                after: ['overrides' => $this->readable($overrides)],
                actor: $actor,
            );
        });
    }

    /**
     * Stage codes rather than ids in the audit row.
     *
     * An auditor reading this a year later should not have to join to a table
     * to learn that stage 2 was the zone.
     *
     * @param array<int|string, bool> $overrides
     * @return array<string, bool>
     */
    private function readable(array $overrides): array
    {
        if ($overrides === []) {
            return [];
        }

        $codes = LoanApprovalStage::query()
            ->whereIn('id', array_keys($overrides))
            ->pluck('code', 'id');

        $result = [];

        foreach ($overrides as $stageId => $required) {
            $result[(string) ($codes[(int) $stageId] ?? $stageId)] = (bool) $required;
        }

        return $result;
    }
}
