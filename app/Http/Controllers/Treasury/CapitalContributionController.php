<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Treasury\Actions\DeleteCapitalAction;
use App\Domain\Treasury\Actions\RecordCapitalAction;
use App\Domain\Treasury\DTOs\CapitalContributionData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\StoreCapitalContributionRequest;
use App\Http\Resources\CapitalContributionResource;
use App\Models\CapitalContribution;
use App\Models\Shareholder;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capital → Add Capitals.
 *
 * The screen shows two totals and they answer different questions, which is
 * why both are returned rather than one being derived from the other:
 *
 *   shareholderCapital — the sum of what shareholders have paid in.
 *   companyCapital     — the balance of ledger account 1000.
 *
 * They can disagree, and when they do that is the finding, not a bug.
 */
final class CapitalContributionController extends Controller
{
    use AuthorizesCapital;

    public function __construct(private readonly AccountResolver $accounts) {}

    /** GET /api/v1/capital-contributions */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $contributions = CapitalContribution::query()
            ->with('shareholder')
            ->orderBy('shareholder_id')
            ->orderBy('created_at')
            ->get();

        $shareholderCapital = Money::sum(
            $contributions->map(fn (CapitalContribution $c): Money => Money::of($c->amount)),
        );

        return ApiResponse::data(
            CapitalContributionResource::collection($contributions),
            meta: [
                'shareholderCapital' => $shareholderCapital->toDecimalString(),
                'companyCapital' => $this->accounts->system(SystemAccountCode::Capital)
                    ->load('balances')->cachedBalance()->toDecimalString(),
            ],
        );
    }

    /** POST /api/v1/capital-contributions */
    public function store(StoreCapitalContributionRequest $request, RecordCapitalAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $data = CapitalContributionData::fromArray($request->validated());
        $shareholder = Shareholder::query()->findOrFail($data->shareholderId);

        $contribution = $action->handle($shareholder, $data, $this->actor($request));

        return ApiResponse::data(new CapitalContributionResource($contribution), status: Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/capital-contributions/{contribution} */
    public function destroy(Request $request, CapitalContribution $contribution, DeleteCapitalAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $action->handle($contribution, $this->actor($request));

        return ApiResponse::data(['message' => 'Capital contribution removed and reversed.']);
    }
}
