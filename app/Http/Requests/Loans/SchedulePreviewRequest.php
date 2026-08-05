<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a schedule preview needs: a product, an amount, a cadence and a tenure.
 *
 * Deliberately NOT the eligibility gates. A preview answers "what would this
 * cost?", which an officer needs while the answer to "may this customer have
 * it?" is still no — the eligibility endpoint answers that one, and running
 * both here would refuse to show a plan for a loan the officer is in the middle
 * of correcting.
 */
final class SchedulePreviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loanProductId' => ['required', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],
            'repaymentScheduleId' => [
                'required', 'integer',
                Rule::exists('repayment_schedules', 'id')->whereNull('deleted_at'),
            ],
            'principalAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],

            // Bounded so a typo cannot ask the engine for a hundred thousand
            // installments and hold a request open building them.
            'tenureDays' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
