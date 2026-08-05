<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Actions\RecordRecoveryAction;
use App\Domain\Loans\Actions\WriteOffLoanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\RecordRecoveryRequest;
use App\Http\Requests\Loans\WriteOffLoanRequest;
use App\Http\Resources\RecoveryResource;
use App\Http\Resources\WriteOffResource;
use App\Models\Loan;
use App\Models\Recovery;
use App\Models\WriteOff;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bad debt — §5's Write-Off and Recovered Loans accounts.
 *
 * Both operations hang off a loan rather than living in their own module,
 * because both are decisions about one loan and the screens that need them are
 * the loan screens. The UI is locked, so these extend the existing loan detail
 * page rather than adding navigation.
 */
final class LoanRecoveryController extends Controller
{
    /** GET /api/v1/write-offs */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        $writeOffs = WriteOff::query()
            ->with(['loan', 'approver', 'recoveries'])
            ->when(
                $request->filled('branch_id'),
                fn ($q) => $q->whereHas('loan', fn ($l) => $l->where('branch_id', $request->integer('branch_id'))),
            )
            ->latest('id')
            ->get();

        $written = Money::sum($writeOffs->map(fn (WriteOff $w): Money => $w->principalMoney()));
        $recovered = Money::sum($writeOffs->map(fn (WriteOff $w): Money => $w->recoveredTotal()));

        return ApiResponse::data(
            WriteOffResource::collection($writeOffs),
            meta: [
                'principalWrittenOff' => $written->toDecimalString(),
                'recovered' => $recovered->toDecimalString(),
                'outstanding' => $written->subtract($recovered)->max(Money::zero())->toDecimalString(),
            ],
        );
    }

    /** POST /api/v1/loans/{loan}/write-off */
    public function writeOff(WriteOffLoanRequest $request, Loan $loan, WriteOffLoanAction $action): JsonResponse
    {
        $this->authorize('writeOff', $loan);

        $writeOff = $action->handle(
            $loan,
            (string) $request->validated('reason'),
            $this->actor($request),
        );

        return ApiResponse::data(
            new WriteOffResource($writeOff->load(['loan', 'approver'])),
            status: Response::HTTP_CREATED,
        );
    }

    /** POST /api/v1/loans/{loan}/recovery */
    public function recover(RecordRecoveryRequest $request, Loan $loan, RecordRecoveryAction $action): JsonResponse
    {
        $this->authorize('recover', $loan);

        $bankAccountId = $request->validated('bank_account_id');
        $narrative = $request->validated('narrative');

        $recovery = $action->handle(
            $loan,
            Money::of((string) $request->validated('amount')),
            $bankAccountId === null ? null : (int) $bankAccountId,
            $narrative === null ? null : (string) $narrative,
            $this->actor($request),
        );

        return ApiResponse::data(
            new RecoveryResource($recovery->load(['loan', 'recorder'])),
            status: Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/loans/{loan}/recoveries */
    public function recoveries(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        $recoveries = Recovery::query()
            ->with(['loan', 'recorder'])
            ->where('loan_id', $loan->getKey())
            ->latest('id')
            ->get();

        return ApiResponse::data(
            RecoveryResource::collection($recoveries),
            meta: [
                'total' => Money::sum(
                    $recoveries->map(fn (Recovery $r): Money => $r->amountMoney()),
                )->toDecimalString(),
            ],
        );
    }
}
