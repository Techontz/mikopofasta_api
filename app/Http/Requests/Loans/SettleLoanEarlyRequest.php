<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The cash tendered at settlement, if any.
 *
 * Optional, and zero is legitimate: a borrower who has been paying ahead may
 * already hold enough advance credit to close the loan without handing over
 * another shilling. Whether what is offered actually covers the settlement is
 * not a validation question — it depends on a figure only the quoter can
 * compute — so the action refuses a shortfall with both numbers named.
 */
final class SettleLoanEarlyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }
}
