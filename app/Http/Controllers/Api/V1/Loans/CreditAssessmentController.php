<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanAssessment;
use App\Services\Credit\CreditAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CREDIT OFFICER ANALYSIS SCREEN (§37, §63): the system's recommendation for one loan application, with the evidence
 * behind it.
 *
 *  GET    loans/credit-assessments/queue    §63 applications awaiting a decision, each with its latest recommendation.
 *  GET    loans/{loan}/credit-assessment    the stored snapshot the officer is working from, or a fresh preview when
 *                                           none has been recorded (`?refresh=1` always recomputes, without storing).
 *  POST   loans/{loan}/credit-assessment    re-run the engine and store the snapshot that was shown.
 *  GET    loans/{loan}/credit-assessments   every snapshot recorded for this application, newest first.
 *
 * Every endpoint is permission-gated and branch-scoped like the other loan endpoints (a loan outside the employee's
 * branch scope is a 404). Every response is advisory (§36): nothing here approves, rejects, prices or re-stages a loan —
 * the POST writes one loan_assessments row and touches nothing else.
 */
class CreditAssessmentController extends LoanApiController
{
    public function __construct(private readonly CreditAssessment $assessments) {}

    /**
     * §63: loan requests awaiting analysis — the applications still in the approval pipeline before finance, with the
     * latest recorded recommendation (null when none has been recorded yet).
     */
    public function queue(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.credit_review', 'loans.approve_manager');

        $loans = $this->applyFilters($this->scoped(Loan::query()), $request, 'created_at')
            ->status(LoanStatus::PendingManagerApproval, LoanStatus::MandatePendingOtp, LoanStatus::PendingCreditReview)
            ->with(['customer.customerCategory', 'branch', 'category'])
            ->latest('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        $latest = LoanAssessment::query()
            ->whereIn('loan_id', $loans->getCollection()->modelKeys())
            ->latestFirst()
            ->get()
            ->unique('loan_id')
            ->keyBy('loan_id');

        return response()->json([
            'data' => $loans->getCollection()->map(fn (Loan $loan): array => [
                'loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'status' => $loan->status->value,
                'status_label' => $loan->status->label(),
                'customer_id' => $loan->customer_id,
                'customer' => $loan->customer?->full_name,
                'customer_type' => $loan->customer?->customerCategory?->name,
                'branch' => $loan->branch?->name,
                'applied_at' => $loan->created_at?->toIso8601String(),
                'requested_amount' => (float) $loan->amount_applied,
                'assessment' => ($assessment = $latest->get($loan->id)) === null ? null : [
                    'id' => $assessment->id,
                    'recommended_amount' => (float) $assessment->recommended_amount,
                    'score' => (float) $assessment->score,
                    'risk_band' => $assessment->risk_band,
                    'risk_band_label' => $assessment->analysis['risk_band_label'] ?? null,
                    'assessed_at' => $assessment->assessed_at?->toIso8601String(),
                    'explanation' => $assessment->explanation,
                    'advisory' => true,
                ],
            ])->values(),
            'meta' => ['current_page' => $loans->currentPage(), 'last_page' => $loans->lastPage(), 'per_page' => $loans->perPage(), 'total' => $loans->total()],
        ]);
    }

    /**
     * The recommendation to display: the latest stored snapshot, otherwise a preview computed on the spot.
     */
    public function show(Loan $loan, Request $request): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);

        $stored = $request->boolean('refresh') ? null : $this->assessments->storedFor($loan);

        return response()->json(['data' => $stored ?? ['stored' => false, ...$this->assessments->for($loan)]]);
    }

    /**
     * Re-assess and store the snapshot, so the recommendation the officer saw stays auditable (§36 / §37).
     */
    public function store(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.credit_review', 'loans.approve_manager', 'loans.apply');
        $this->ensureVisible($loan);

        $assessment = $this->assessments->record($loan, $this->currentEmployee());

        return response()->json(['data' => $this->assessments->present($assessment), 'message' => 'Credit assessment recorded. It is advisory only and has changed nothing on the loan.'], 201);
    }

    /**
     * Audit trail: every recommendation ever recorded for this application, newest first.
     */
    public function index(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);

        $rows = LoanAssessment::where('loan_id', $loan->id)->with('assessor')->latestFirst()->get();

        return response()->json(['data' => $rows->map(fn (LoanAssessment $assessment): array => $this->assessments->present($assessment))->values()]);
    }
}
