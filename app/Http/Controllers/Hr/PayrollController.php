<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Actions\FinalizePayrollAction;
use App\Domain\Hr\Actions\GeneratePayrollAction;
use App\Domain\Hr\Actions\PayPayrollAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\GeneratePayrollRequest;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payroll — §15.5.
 *
 * Three endpoints for three acts by two different people, which is §14's
 * separation of duties made concrete:
 *
 *   generate  →  `payroll.generate`  (HR)      draft, posts nothing
 *   finalize  →  `payroll.finalize`  (Finance) recognises the cost
 *   pay       →  `payroll.finalize`  (Finance) settles the debt
 *
 * "HR can generate payroll but not finalize/pay it (Finance does)." Generating
 * and finalizing are separate permissions rather than one, because the whole
 * control is that the person who computed the numbers is not the person who
 * releases the money.
 */
final class PayrollController extends Controller
{
    /**
     * GET /api/v1/payroll
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $query = PayrollRun::query()
            ->with('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('period');

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            PayrollRunResource::class,
        );
    }

    /**
     * GET /api/v1/payroll/{run}
     */
    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('view', $run);

        return ApiResponse::data(new PayrollRunResource(
            $run->load(['lines.staffProfile.user', 'lines.allowances', 'lines.deductions']),
        ));
    }

    /**
     * POST /api/v1/payroll/generate — §15.5. HR's step; status stays `draft`.
     */
    public function generate(GeneratePayrollRequest $request, GeneratePayrollAction $action): JsonResponse
    {
        $this->authorize('generate', PayrollRun::class);

        $run = $action->handle((string) $request->validated('period'), $this->actor($request));

        return ApiResponse::data(
            new PayrollRunResource($run),
            // Said plainly in the response: a draft has computed everyone's
            // pay and moved none of it.
            ['ledgerPosting' => 'none — a draft run posts nothing until Finance finalizes it'],
            Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/payroll/{run}/finalize — §15.5. Finance only; this posts.
     */
    public function finalize(Request $request, PayrollRun $run, FinalizePayrollAction $action): JsonResponse
    {
        $this->authorize('finalize', PayrollRun::class);

        return ApiResponse::data(new PayrollRunResource($action->handle($run, $this->actor($request))));
    }

    /**
     * POST /api/v1/payroll/{run}/pay — §11's "Payment executed".
     */
    public function pay(Request $request, PayrollRun $run, PayPayrollAction $action): JsonResponse
    {
        $this->authorize('finalize', PayrollRun::class);

        return ApiResponse::data(new PayrollRunResource($action->handle($run, $this->actor($request))));
    }
}
