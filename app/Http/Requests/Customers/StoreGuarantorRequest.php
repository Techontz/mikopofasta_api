<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\GuarantorRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors the frontend's CreateGuarantorInputSchema. */
final class StoreGuarantorRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'nidaNumber' => ['nullable', 'string', 'max:30'],
            'relationship' => ['required', 'string', Rule::in(GuarantorRelationship::values())],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:150'],
        ];
    }
}
