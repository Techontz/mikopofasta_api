<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Settings → Dividend Settings: Shareholders Dividend % + Principal Reinvestment % must total exactly 100.00.
 */
class DividendSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
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
            'dividend_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'reinvest_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $total = bcadd($this->percent('dividend_percent'), $this->percent('reinvest_percent'), 2);
                if (bccomp($total, '100.00', 2) !== 0) {
                    $validator->errors()->add('dividend_percent', "Shareholders Dividend % and Principal Reinvestment % must total exactly 100% (currently {$total}%).");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['dividend_percent' => 'Shareholders Dividend %', 'reinvest_percent' => 'Principal Reinvestment %'];
    }

    public function percent(string $field): string
    {
        return bcadd((string) $this->input($field), '0', 2);
    }
}
