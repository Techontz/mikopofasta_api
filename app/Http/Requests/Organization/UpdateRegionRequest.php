<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRegionRequest extends FormRequest
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
        $region = $this->route('region');
        $regionId = $region instanceof Region ? $region->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100', Rule::unique('regions', 'name')->ignore($regionId)],
        ];
    }
}
