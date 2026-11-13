<?php

namespace App\Services;

use App\Models\Loan;
use App\Services\Reports\InstalmentBehaviour;
use Carbon\CarbonImmutable;

/**
 * Loan Repayment History: the loan's existing repayment schedule (built from its Start Date at disbursement), one row per
 * instalment, with what confirmed repayments have paid on it. Nothing is recalculated:
 *  - Deduction Date and Installment Amt are the schedule's own;
 *  - Paid Amt is the amount the existing allocation put on the instalment (loan_schedules.paid_amount; penalties are never
 *    part of it, and unconfirmed payments never reach it);
 *  - Collection Date is the date of the confirmed repayment that settled the instalment - or, for a partly paid one, of the
 *    latest repayment that went to it - replayed oldest-first like the allocation ({@see InstalmentBehaviour}).
 */
class LoanRepaymentHistory
{
    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const UNPAID = 'unpaid';

    public function __construct(private InstalmentBehaviour $behaviour) {}

    /**
     * @return array{loan: array<string, mixed>, rows: list<array<string, mixed>>, totals: array{instalments: float, paid: float, outstanding: float}}
     */
    public function for(Loan $loan, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $replayed = $this->behaviour->instalments([$loan->id], $today)->keyBy('id');
        $schedules = $loan->schedules()->orderBy('due_date')->orderBy('id')->get();

        $rows = $schedules->values()->map(function ($schedule, int $index) use ($replayed, $today): array {
            $amount = round((float) $schedule->amount, 2);
            $paid = round(min((float) $schedule->paid_amount, $amount), 2);
            $outstanding = round(max(0, $amount - $paid), 2);
            $status = match (true) {
                $outstanding <= 0.005 => self::PAID,
                $paid > 0.005 => self::PARTIAL,
                default => self::UNPAID,
            };
            $replay = $replayed->get($schedule->id);

            return [
                'number' => $index + 1,
                'deduction_date' => $schedule->due_date->toDateString(),
                'collection_date' => $paid > 0.005 ? ($status === self::PAID ? ($replay['paid_date'] ?? $replay['last_paid_date'] ?? null) : ($replay['last_paid_date'] ?? null)) : null,
                'instalment_amount' => $amount,
                'paid_amount' => $paid,
                'outstanding_amount' => $outstanding,
                'status' => $status,
                'is_due' => $schedule->due_date->lte($today),
            ];
        })->all();

        return [
            'loan' => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'reference_number' => $loan->reference_number,
                'product' => $loan->category?->name,
                'customer' => $loan->customer?->full_name,
                'status' => $loan->status?->label(),
                'start_date' => $loan->start_date?->toDateString(),
                'withdrawn_at' => $loan->withdrawn_at?->toDateString(),
                'end_date' => $loan->end_date?->toDateString(),
                'instalment' => round((float) $loan->restoration, 2),
                'loan_method' => $loan->loan_method?->label(),
            ],
            'rows' => $rows,
            'totals' => [
                'instalments' => round(array_sum(array_column($rows, 'instalment_amount')), 2),
                'paid' => round(array_sum(array_column($rows, 'paid_amount')), 2),
                'outstanding' => round(array_sum(array_column($rows, 'outstanding_amount')), 2),
            ],
        ];
    }
}
