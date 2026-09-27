<?php

namespace App\Http\Requests\Api\Loans;

use App\Models\LoanDisbursement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Disbursement source: loans are always paid out of the HQ PRINCIPAL A/C ("cash"). The field is optional and only
 * "cash" is accepted — a company bank account is no longer a disbursement source.
 */
class DisbursementSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::sourceRules($this->user()->company_id);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function sourceRules(int $companyId): array
    {
        return [
            'source_account' => ['nullable', Rule::in([LoanDisbursement::SOURCE_CASH])],
        ];
    }

    /**
     * @return array{source_account: string|null, source_bank_account_id: int|null}|null
     */
    public function source(): ?array
    {
        return $this->filled('source_account')
            ? ['source_account' => $this->string('source_account')->toString(), 'source_bank_account_id' => null]
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['source_account' => 'disbursement source'];
    }
}
