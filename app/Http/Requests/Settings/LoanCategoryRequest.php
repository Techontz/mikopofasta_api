<?php

namespace App\Http\Requests\Settings;

use App\Enums\Duration;
use App\Models\InterestFormula;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanCategoryRequest extends FormRequest
{
    /**
     * Approval levels offered by the live "Approve status" dropdown.
     *
     * @var array<string, string>
     */
    public const APPROVE_LEVELS = ['branch' => 'branch', 'hq' => 'hq', 'zone manager' => 'zone manager'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Live amount fields use a money mask ("20,000"); strip the separators.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['loan_price', 'loan_perday', 'interest_formular', 'topup_percent', 'take_home_percent'])
            ->filter(fn (string $field): bool => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field): array => [$field => str_replace([',', ' ', '%'], '', $this->input($field))])
            ->all());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'loan_name' => ['required', 'string', 'max:255'],
            'loan_price' => ['required', 'numeric', 'min:0'],
            'loan_perday' => ['required', 'numeric', 'gte:loan_price'],
            'interest_formular' => ['required', 'numeric', 'min:0', 'max:1000'],
            'formular' => ['required', Rule::in(InterestFormula::pluck('code')->all())],
            'duration' => ['required', Rule::enum(Duration::class)],
            'from_repayment' => ['required', 'integer', 'min:1'],
            'to_repayment' => ['required', 'integer', 'gte:from_repayment'],
            'fee_deduct' => ['required', 'in:YES,NO'],
            'penart' => ['required', 'in:YES,NO'],
            'aprove_status' => ['required', Rule::in(array_keys(self::APPROVE_LEVELS))],
            'topup_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'take_home_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            // The one customer type (customer_categories) of the loan category: same company, active, not deleted. A category
            // being edited may keep its current customer type even if that type was deactivated since.
            'customer_type_id' => ['required', 'integer', Rule::exists('customer_categories', 'id')
                ->where('company_id', $this->user()->company_id)
                ->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('is_active', true)->when(
                    $this->route('loan_category')?->customer_category_id,
                    fn ($query, $currentId) => $query->orWhere('id', $currentId),
                ))],
            // Removed hierarchy / multi-type keys: refused instead of silently ignored.
            'main_category_id' => ['prohibited'],
            'main_id' => ['prohibited'],
            'customer_category_id' => ['prohibited'],
            'customer_category_ids' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('loan_name')->trim()->toString(),
            'amount_from' => $this->float('loan_price'),
            'amount_to' => $this->float('loan_perday'),
            'interest_rate' => $this->float('interest_formular'),
            'formula' => $this->string('formular')->toString(),
            'duration' => $this->enum('duration', Duration::class),
            'repayment_from' => $this->integer('from_repayment'),
            'repayment_to' => $this->integer('to_repayment'),
            'fee_deduct' => $this->input('fee_deduct') === 'YES',
            'has_penalty' => $this->input('penart') === 'YES',
            'approve_level' => $this->string('aprove_status')->toString(),
            'topup_percent' => $this->float('topup_percent'),
            'take_home_percent' => $this->float('take_home_percent'),
            'customer_category_id' => $this->integer('customer_type_id'),
        ];
    }
}
