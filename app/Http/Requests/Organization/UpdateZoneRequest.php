<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateZoneRequest extends FormRequest
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
        $zone = $this->route('zone');
        $zoneId = $zone instanceof Zone ? $zone->getKey() : null;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('zones', 'name')->ignore($zoneId)->whereNull('deleted_at'),
            ],
            'zoneManagerId' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }
}
