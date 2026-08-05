<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\ReserveUtilisationData;
use App\Domain\Accounting\Enums\ReserveUtilisationStatus;
use App\Enums\AuditAction;
use App\Models\ReserveUtilisation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Finance proposes a use of the Reserve fund — Decision Register D1.
 *
 * Nothing moves here. D1 requires Admin approval before reserve leaves the
 * fund, so a request is a row and only a decision is a posting — the same shape
 * float transfers and expense requests already use, which means a queue of
 * proposals never touches the trial balance.
 *
 * The balance is deliberately NOT checked at this point. Two requests can be
 * raised against a sufficient balance and only one of them be affordable by the
 * time anyone decides; the guard belongs where the money actually moves, and
 * DecideReserveUtilisationAction is where it lives.
 */
final class RequestReserveUtilisationAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ReserveUtilisationData $data, User $actor): ReserveUtilisation
    {
        return DB::transaction(function () use ($data, $actor): ReserveUtilisation {
            $request = ReserveUtilisation::query()->create([
                'reference' => ReserveUtilisation::nextReference(),
                'purpose' => $data->purpose,
                'amount' => $data->amount,
                'narrative' => $data->narrative,
                'target_branch_id' => $data->targetBranchId,
                'status' => ReserveUtilisationStatus::Pending,
                'requested_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ReserveUtilisationRequested,
                $request,
                after: [
                    'reference' => $request->reference,
                    'purpose' => $data->purpose->value,
                    'amount' => $data->amount,
                    'target_branch_id' => $data->targetBranchId,
                ],
                actor: $actor,
            );

            return $request->load(['requester', 'targetBranch']);
        });
    }
}
