<?php

namespace App\Http\Requests\Api\Capital;

use App\Services\Assets\AssetRegistry;
use App\Services\Assets\AssetTypes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add Capital with Pay Method ASSET. Common asset fields plus the type-specific `specifications.*` fields from
 * config/assets.php. The total contribution value is always quantity × unit value computed on the server; a client
 * `total_value` that differs is rejected.
 */
class AssetContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('capital.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $types = app(AssetTypes::class);
        $companyId = $this->user()->company_id;
        $type = $types->exists($this->input('asset_type')) ? $types->get((string) $this->input('asset_type')) : null;

        return [
            'share_id' => ['required', Rule::exists('share_holders', 'id')->where('company_id', $companyId)],
            'asset_type' => ['required', Rule::in($types->keys())],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:2000'],
            'quantity' => ($type['fixed_quantity'] ?? false) ? ['nullable', 'integer', 'in:1'] : ['required', 'integer', 'min:1', 'max:100000'],
            'unit_value' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'total_value' => ['nullable', 'numeric'],
            'condition' => ($type['condition'] ?? true) ? ['required', Rule::in(array_keys(config('assets.conditions')))] : ['nullable', 'prohibited'],
            'contribution_date' => ['required', 'date', 'before_or_equal:today'],
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'location' => [($type['location_required'] ?? false) ? 'required' : 'nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'valuation_method' => ['required', Rule::in(array_keys(config('assets.valuation_methods')))],
            'valuation_date' => ['required', 'date', 'before_or_equal:today'],
            'valued_by' => ['nullable', 'string', 'max:191'],
            'valuation_reference' => ['nullable', 'string', 'max:191'],
            'valuation_notes' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            ...($type === null ? [] : $types->specificationRules((string) $this->input('asset_type'))),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['quantity', 'unit_value', 'asset_type']) || ! $this->filled('total_value')) {
                return;
            }

            $type = app(AssetTypes::class)->get((string) $this->input('asset_type'));
            $expected = AssetRegistry::total($type['fixed_quantity'] ? 1 : $this->integer('quantity'), (string) $this->input('unit_value'));
            $given = number_format((float) $this->input('total_value'), 2, '.', '');

            if (! is_numeric($this->input('total_value')) || bccomp($given, $expected, 2) !== 0) {
                $validator->errors()->add('total_value', 'The total contribution value must equal quantity × unit value ('.number_format((float) $expected, 2).')');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $types = app(AssetTypes::class);

        return [
            'share_id' => 'shareholder', 'asset_type' => 'asset type', 'name' => 'asset name', 'unit_value' => 'unit value',
            'total_value' => 'total contribution value', 'contribution_date' => 'contribution date', 'branch_id' => 'allocated branch',
            'valuation_method' => 'valuation method', 'valuation_date' => 'valuation date', 'valued_by' => 'valued by',
            'valuation_reference' => 'valuation reference', 'valuation_notes' => 'valuation notes',
            ...($types->exists($this->input('asset_type')) ? $types->specificationAttributes((string) $this->input('asset_type')) : []),
        ];
    }
}
