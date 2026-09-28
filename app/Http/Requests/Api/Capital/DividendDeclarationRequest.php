<?php

namespace App\Http\Requests\Api\Capital;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Declare a month's dividend. Only the period is submitted: Profit Available, the split percentages and ownership are
 * computed by the server inside the declaration transaction, so a profit amount in the request is rejected.
 */
class DividendDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('capital.manage');
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, 'You do not have permission to perform this action.');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'profit_amount' => ['prohibited'],
            'dividend_percent' => ['prohibited'],
            'reinvest_percent' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.before_or_equal' => 'Dividends cannot be declared for a future month.',
            'profit_amount.prohibited' => 'Profit Available is calculated by the system and cannot be entered.',
            'dividend_percent.prohibited' => 'The dividend percentages come from Settings → Dividend Settings.',
            'reinvest_percent.prohibited' => 'The dividend percentages come from Settings → Dividend Settings.',
        ];
    }
}
