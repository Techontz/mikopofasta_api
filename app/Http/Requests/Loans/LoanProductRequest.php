<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\PenaltyType;
use App\Enums\ActiveStatus;
use App\Models\LoanProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's CreateLoanProductInputSchema.
 *
 * Money and rates arrive as decimal strings and are validated as such — see
 * the note on ApplyForLoanRequest for why a float is never accepted.
 *
 * `penaltyRate` allows 3 decimals and a wide range because its unit depends on
 * `penaltyType`: a percentage for the two percentage types, a flat TZS amount
 * for `flat_fee` (§2.3, OSC-2).
 */
final class LoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $id = $product instanceof LoanProduct ? $product->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => ['required', 'string', 'min:2', 'max:40', Rule::unique('loan_products', 'code')->ignore($id)->whereNull('deleted_at')],

            'interestFormulaId' => ['required', 'integer', Rule::exists('interest_formulas', 'id')->whereNull('deleted_at')],
            'interestRate' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999.999'],

            'minAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'maxAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'gte:minAmount'],

            'minTenureDays' => ['required', 'integer', 'min:1', 'max:3650'],
            'maxTenureDays' => ['required', 'integer', 'min:1', 'max:3650', 'gte:minTenureDays'],

            'penaltyType' => ['required', 'string', Rule::in(PenaltyType::values())],
            'penaltyRate' => ['required', 'numeric', 'decimal:0,3', 'min:0'],
            'penaltyGraceDays' => ['required', 'integer', 'min:0', 'max:365'],
            'penaltyCapAmount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0'],

            'requiresMandate' => ['required', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(ActiveStatus::values())],

            // Which cadences this product allows (§2.3 pivot). Required and
            // non-empty: a product no schedule can be applied under is a
            // product no loan can ever use.
            'repaymentScheduleIds' => ['required', 'array', 'min:1'],
            'repaymentScheduleIds.*' => ['integer', Rule::exists('repayment_schedules', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maxAmount.gte' => 'The maximum amount must be at least the minimum amount.',
            'maxTenureDays.gte' => 'The maximum tenure must be at least the minimum tenure.',
            'repaymentScheduleIds.required' => 'A product must allow at least one repayment schedule.',
        ];
    }
}
