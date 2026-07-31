<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\GuarantorRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors the frontend's CreateNextOfKinInputSchema. */
final class StoreNextOfKinRequest extends FormRequest
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
            'relationship' => ['required', 'string', Rule::in(GuarantorRelationship::values())],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
