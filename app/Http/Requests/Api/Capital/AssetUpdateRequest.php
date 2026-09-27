<?php

namespace App\Http\Requests\Api\Capital;

use App\Models\Asset;
use App\Services\Assets\AssetTypes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Edit of an asset's descriptive fields. Financial and ownership fields are immutable here: the contribution value,
 * quantity, unit value, contributor and contribution date never change, the branch changes only through a transfer, the
 * status through a status change and the current value through a revaluation.
 */
class AssetUpdateRequest extends FormRequest
{
    /**
     * Fields that must go through their own audited action (or never change).
     */
    public const IMMUTABLE = [
        'share_id', 'share_holder_id', 'asset_type', 'quantity', 'unit_value', 'total_value', 'contribution_value', 'current_value',
        'contribution_date', 'contributed_on', 'branch_id', 'status', 'valuation_method', 'valuation_date', 'valued_by',
        'valuation_reference', 'valuation_notes', 'name', 'asset_code', 'qr_token',
    ];

    public function authorize(): bool
    {
        return Gate::allows('capital.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');
        $types = app(AssetTypes::class);
        $type = $types->exists($asset->asset_type) ? $types->get($asset->asset_type) : null;

        return [
            ...array_fill_keys(self::IMMUTABLE, ['prohibited']),
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'location' => ['sometimes', ($type['location_required'] ?? false) ? 'required' : 'nullable', 'string', 'max:191'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'condition' => ($type['condition'] ?? true) ? ['sometimes', 'required', Rule::in(array_keys(config('assets.conditions')))] : ['nullable', 'prohibited'],
            'specifications' => ['sometimes', 'array'],
            ...($type !== null && $this->has('specifications') ? $types->specificationRules($asset->asset_type) : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(
            array_map(fn (string $field): string => $field.'.prohibited', self::IMMUTABLE),
            'The :attribute cannot be edited here (use transfer, status change or revaluation; contribution values never change).',
        );
    }
}
