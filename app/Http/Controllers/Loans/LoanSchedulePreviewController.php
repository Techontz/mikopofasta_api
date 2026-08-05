<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Engine\LoanEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\SchedulePreviewRequest;
use App\Models\LoanProduct;
use App\Models\RepaymentSchedule;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/**
 * POST /api/v1/loans/schedule-preview — what a product would produce.
 *
 * ## Why this endpoint exists
 *
 * The application form showed the officer a repayment plan computed IN THE
 * BROWSER, from a TypeScript copy of the interest formulas. Its own comment
 * conceded the two "allocate rounding remainders differently, so individual
 * installments can differ by a cent or two".
 *
 * That was already a second implementation of the thing the engine exists to
 * own. It became a real defect the moment Reducing EMI was made the default:
 * the browser copy knows three formulas, and a product on the fourth would have
 * been previewed with arithmetic that was not the arithmetic the loan would be
 * priced with — quietly, and looking entirely ordinary.
 *
 * So the preview is computed here, by LoanEngine, through the same strategy
 * that will build the real schedule at approval. The figures an officer shows a
 * customer are now the figures the customer will owe.
 *
 * ## Why it is a POST that writes nothing
 *
 * It takes a product, an amount, a cadence and a tenure — a body, not a path.
 * Nothing is persisted; the loan does not exist yet. Gated on `loans.create`,
 * because previewing a plan is part of raising an application.
 */
final class LoanSchedulePreviewController extends Controller
{
    public function __invoke(SchedulePreviewRequest $request, LoanEngine $engine): JsonResponse
    {
        $this->authorize('create', \App\Models\Loan::class);

        $product = LoanProduct::query()
            ->with(['interestFormula', 'interestRateBasis'])
            ->findOrFail((int) $request->validated('loanProductId'));

        $schedule = RepaymentSchedule::query()->findOrFail((int) $request->validated('repaymentScheduleId'));

        $terms = $engine->termsForProduct(
            product: $product,
            principal: Money::of((string) $request->validated('principalAmount')),
            tenureDays: (int) $request->validated('tenureDays'),
            frequencyDays: $schedule->frequency_days,
            /*
             * From today, which is what approval will use. A preview anchored
             * to a different date would show due dates the loan will not have.
             */
            startDate: Date::now()->startOfDay()->toImmutable(),
        );

        $installments = $engine->scheduleFor($terms, $product->interestFormula->code);

        $principal = Money::sum(array_map(static fn ($i): Money => $i->principalDue, $installments));
        $interest = Money::sum(array_map(static fn ($i): Money => $i->interestDue, $installments));

        return ApiResponse::data([
            'formulaCode' => $product->interestFormula->code,
            'formulaName' => $product->interestFormula->name,
            'installmentCount' => count($installments),
            'totalPrincipal' => $principal->toDecimalString(),
            'totalInterest' => $interest->toDecimalString(),
            'totalPayable' => $principal->add($interest)->toDecimalString(),
            'installments' => array_map(static fn ($i): array => [
                'installmentNumber' => $i->installmentNumber,
                'dueDate' => $i->dueDate->toDateString(),
                'principalDue' => $i->principalDue->toDecimalString(),
                'interestDue' => $i->interestDue->toDecimalString(),
                'totalDue' => $i->total()->toDecimalString(),
            ], $installments),
        ]);
    }
}
